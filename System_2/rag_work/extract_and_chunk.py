#!/usr/bin/env python3
"""
extract_and_chunk.py – System 2 · PDF → Sliding Window Chunks → rag.chunks
============================================================================
Liest PDFs aus /home/kommrag/ingest/done/<package_id>/
Extrahiert Text pro Seite via pymupdf
Erstellt Sliding Window Chunks (600 Token, 20% Overlap)
Schreibt in rag.chunks (chunk_source = 'page')

Usage:
    python rag_work/extract_and_chunk.py --done /home/kommrag/ingest/done
    python rag_work/extract_and_chunk.py --done /home/kommrag/ingest/done --document-id 1
    python rag_work/extract_and_chunk.py --done /home/kommrag/ingest/done --once
"""

import argparse
import json
import logging
import os
import re
import sys
import traceback
from pathlib import Path

import fitz  # pymupdf
import psycopg2
import psycopg2.extras
from dotenv import load_dotenv

load_dotenv(Path(__file__).resolve().parent / ".env", override=True)

# =============================================================
# Konfiguration
# =============================================================

DB_DSN           = (os.environ.get("KOMMRAG_DSN") or "").strip()
if not DB_DSN:
    sys.exit("KOMMRAG_DSN nicht gesetzt (Umgebungsvariable oder .env), z. B. postgresql://user:pass@localhost:5432/kommrag")
CHUNK_TOKEN_SIZE = 600
CHUNK_OVERLAP    = 0.20   # 20%
WORKER_ID        = os.environ.get("WORKER_ID", "chunk_worker_01")

# =============================================================
# Logging
# =============================================================

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    handlers=[logging.StreamHandler(sys.stdout)]
)
log = logging.getLogger("extract_and_chunk")


# =============================================================
# DB
# =============================================================

def get_conn():
    return psycopg2.connect(DB_DSN, cursor_factory=psycopg2.extras.RealDictCursor)


def fetch_documents(conn, document_id=None):
    """Hole alle Dokumente die noch keine page-Chunks haben."""
    with conn.cursor() as cur:
        if document_id:
            cur.execute("""
                SELECT d.id, d.package_id, d.source_filename, d.meta
                FROM raw.documents d
                WHERE d.id = %s
            """, (document_id,))
        else:
            cur.execute("""
                SELECT d.id, d.package_id, d.source_filename, d.meta
                FROM raw.documents d
                WHERE NOT EXISTS (
                    SELECT 1 FROM rag.chunks c
                    WHERE c.document_id = d.id
                      AND c.chunk_source = 'page'
                )
                ORDER BY d.id ASC
            """)
        return cur.fetchall()


def delete_page_chunks(conn, document_id):
    with conn.cursor() as cur:
        cur.execute("""
            DELETE FROM rag.chunks
            WHERE document_id = %s AND chunk_source = 'page'
        """, (document_id,))
    conn.commit()


def insert_chunks(conn, document_id, chunks):
    with conn.cursor() as cur:
        for chunk in chunks:
            cur.execute("""
                INSERT INTO rag.chunks (
                    document_id, segment_id, top_key,
                    chunk_index, start_page, end_page,
                    chunk_text, chunk_source, meta
                ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
                ON CONFLICT (document_id, chunk_index) DO UPDATE SET
                    chunk_text   = EXCLUDED.chunk_text,
                    start_page   = EXCLUDED.start_page,
                    end_page     = EXCLUDED.end_page,
                    chunk_source = EXCLUDED.chunk_source,
                    meta         = EXCLUDED.meta,
                    updated_at   = now()
            """, (
                document_id,
                None,                                    # segment_id – page-chunks haben keinen
                chunk.get("top_key"),
                chunk["chunk_index"],
                chunk["start_page"],
                chunk["end_page"],
                chunk["text"],
                "page",
                json.dumps(chunk.get("meta", {}), ensure_ascii=False),
            ))
    conn.commit()


# =============================================================
# TOP-Key Mapping aus raw.segments
# =============================================================

def load_segment_map(conn, document_id):
    """
    Gibt eine Liste von (start_page, end_page, top_key) zurück.
    Wird genutzt um Chunks mit top_key zu annotieren.
    """
    with conn.cursor() as cur:
        cur.execute("""
            SELECT top_key, start_page, end_page
            FROM raw.segments
            WHERE document_id = %s
              AND type = 'top'
              AND top_key IS NOT NULL
            ORDER BY start_page
        """, (document_id,))
        return cur.fetchall()


def find_top_key(page_no, segment_map):
    """Gibt den top_key zurück wenn die Seite in einem TOP-Segment liegt."""
    for seg in segment_map:
        if seg["start_page"] is not None and seg["end_page"] is not None:
            if seg["start_page"] <= page_no <= seg["end_page"]:
                return seg["top_key"]
    return None


# =============================================================
# Textreinigung
# =============================================================

def clean_text(text: str) -> str:
    """
    Bereinigt extrahierten PDF-Text vor dem Chunking.

    Entfernt Rausch der BM25-Suche verfälscht aber keinen inhaltlichen
    Wert hat. Der Fix sitzt hier an der Quelle – nicht in der DB und
    nicht in retrieve.py – damit jedes neu importierte Dokument
    automatisch sauber ist.

    Aktuell entfernt:
    - Windows-Dateipfade (z.B. I:\\AMT15\\15-1_BV\\...\\TOP 14 Anlage 3)
      Diese stammen aus Anlagen-PDFs die den internen Ablageort als
      Fußzeile eingebettet haben. Sie enthalten zwar TOP-Nummern und
      Datumsangaben, aber als Pfad-Kontext – nicht als inhaltlichen Text.
    """
    # Windows-Pfade entfernen: beginnen mit Laufwerksbuchstabe + Doppelpunkt
    text = re.sub(r'[A-Za-z]:\\[^\n]*', '', text)
    text = re.sub(r'[A-Za-z]:/[^\n]*',  '', text)
    # Mehrfache Leerzeilen normalisieren
    text = re.sub(r'\n{3,}', '\n\n', text)
    return text.strip()


# =============================================================
# PDF-Extraktion
# =============================================================

def extract_pages(pdf_path):
    """
    Extrahiert Text pro Seite aus dem PDF.
    Gibt Liste von (page_no, text) zurück (1-basiert).
    """
    pages = []
    doc = fitz.open(str(pdf_path))
    for page_no, page in enumerate(doc, start=1):
        text = page.get_text("text")
        text = text.replace("\x00", "")    # NUL-Zeichen entfernen
        text = clean_text(text)            # Pfade und Rausch entfernen
        if text:
            pages.append({"page_no": page_no, "text": text})
    doc.close()
    return pages


# =============================================================
# Tokenisierung (naive, aber deterministisch)
# =============================================================

def tokenize(text):
    """Naive Whitespace-Tokenisierung – konsistent und schnell."""
    return text.split()


def detokenize(tokens):
    return " ".join(tokens)


# =============================================================
# Sliding Window Chunking
# =============================================================

def sliding_window_chunks(pages, chunk_size=CHUNK_TOKEN_SIZE, overlap=CHUNK_OVERLAP):
    """
    Sliding Window über alle Seiten eines Dokuments.
    Gibt Liste von Chunk-Dicts zurück.
    """
    # Alle Tokens mit Seiten-Annotation
    all_tokens = []  # [(token, page_no)]
    for page in pages:
        tokens = tokenize(page["text"])
        for t in tokens:
            all_tokens.append((t, page["page_no"]))

    if not all_tokens:
        return []

    step       = max(1, int(chunk_size * (1 - overlap)))
    chunks     = []
    chunk_idx  = 0
    pos        = 0

    while pos < len(all_tokens):
        window     = all_tokens[pos:pos + chunk_size]
        tokens_    = [t for t, _ in window]
        pages_     = [p for _, p in window]

        text = detokenize(tokens_)
        text = text.strip()

        if len(text) >= 20:  # Mindestlänge
            chunks.append({
                "chunk_index": chunk_idx,
                "start_page":  pages_[0],
                "end_page":    pages_[-1],
                "text":        text,
                "top_key":     None,  # wird später annotiert
                "meta": {
                    "token_count": len(tokens_),
                    "char_count":  len(text),
                }
            })
            chunk_idx += 1

        pos += step

    return chunks


# =============================================================
# PDF-Pfad ermitteln
# =============================================================

def find_pdf(done_root, package_id, source_filename):
    """Findet die PDF-Datei im done-Ordner."""
    pkg_dir = done_root / str(package_id)

    # 1. Direkt über source_filename
    if source_filename:
        candidate = pkg_dir / source_filename
        if candidate.exists():
            return candidate

    # 2. Erstes PDF im Paketordner
    if pkg_dir.exists():
        pdfs = list(pkg_dir.glob("*.pdf"))
        if pdfs:
            return pdfs[0]

    return None


# =============================================================
# Dokument verarbeiten
# =============================================================

def process_document(conn, doc, done_root):
    doc_id          = doc["id"]
    package_id      = doc["package_id"]
    source_filename = doc["source_filename"]

    log.info(f"[doc {doc_id}] Starte Extraktion (package_id={package_id})")

    pdf_path = find_pdf(done_root, package_id, source_filename)
    if not pdf_path:
        log.warning(f"[doc {doc_id}] PDF nicht gefunden in {done_root}/{package_id}/")
        return False

    try:
        # PDF extrahieren
        pages = extract_pages(pdf_path)
        if not pages:
            log.warning(f"[doc {doc_id}] Keine Seiten extrahiert aus {pdf_path}")
            return False

        # Sliding Window Chunks
        chunks = sliding_window_chunks(pages)

        # TOP-Key Annotation
        segment_map = load_segment_map(conn, doc_id)
        for chunk in chunks:
            chunk["top_key"] = find_top_key(chunk["start_page"], segment_map)

        # Hard-Replace page-Chunks
        delete_page_chunks(conn, doc_id)
        insert_chunks(conn, doc_id, chunks)

        log.info(f"[doc {doc_id}] ✓ {len(chunks)} Chunks aus {len(pages)} Seiten")
        return True

    except Exception as e:
        log.error(f"[doc {doc_id}] FEHLER: {e}\n{traceback.format_exc()}")
        return False


# =============================================================
# Main
# =============================================================

def main():
    parser = argparse.ArgumentParser(description="PDF → Sliding Window Chunks → rag.chunks")
    parser.add_argument("--done", required=True, type=Path,
                        help="Pfad zu done/ (z.B. /home/kommrag/ingest/done)")
    parser.add_argument("--document-id", type=int, default=None,
                        help="Nur ein bestimmtes Dokument verarbeiten")
    parser.add_argument("--once", action="store_true",
                        help="Einmalig laufen")
    args = parser.parse_args()

    conn = get_conn()
    docs = fetch_documents(conn, args.document_id)

    if not docs:
        log.info("Keine Dokumente zu verarbeiten.")
        return

    log.info(f"{len(docs)} Dokument(e) zu verarbeiten.")
    ok = 0
    fail = 0

    for doc in docs:
        success = process_document(conn, doc, args.done)
        if success:
            ok += 1
        else:
            fail += 1

    conn.close()
    log.info(f"Fertig. ✓ {ok} erfolgreich, ✗ {fail} fehlgeschlagen.")


if __name__ == "__main__":
    main()
