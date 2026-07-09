#!/usr/bin/env python3
"""
embed.py – System 2 · Mistral Embeddings → rag.chunks + raw.segments
=====================================================================
- Batch-Size: 16 (konservativ)
- Rate Limit: automatischer Retry mit Backoff
- Token-Limit: Fallback auf Einzel-Requests mit Retry
- Zu langer Text: Text auf 6000 Whitespace-Token truncaten

Usage:
    python rag_work/embed.py
    python rag_work/embed.py --mode chunks
    python rag_work/embed.py --mode segments
    python rag_work/embed.py --document-id 1
"""

import argparse
import logging
import os
import sys
import time
import traceback
from pathlib import Path

import psycopg2
import psycopg2.extras
from dotenv import load_dotenv

load_dotenv(Path(__file__).resolve().parent / ".env", override=True)

try:
    from mistralai import Mistral
except ImportError:
    print("ERROR: mistralai nicht installiert. pip install mistralai")
    sys.exit(1)

# =============================================================
# Konfiguration
# =============================================================

DB_DSN          = (os.environ.get("KOMMRAG_DSN") or "").strip()
if not DB_DSN:
    sys.exit("KOMMRAG_DSN nicht gesetzt (Umgebungsvariable oder .env), z. B. postgresql://user:pass@localhost:5432/kommrag")
MISTRAL_API_KEY = os.environ.get("MISTRAL_API_KEY", "")
EMBED_MODEL     = "mistral-embed"
BATCH_SIZE      = 16      # konservativ
MAX_TOKENS      = 6000    # Whitespace-Token Limit vor dem Senden
RETRY_WAIT      = 10      # Sekunden bei Rate Limit
MAX_RETRIES     = 10

# =============================================================
# Logging
# =============================================================

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    handlers=[logging.StreamHandler(sys.stdout)]
)
log = logging.getLogger("embed")


# =============================================================
# DB
# =============================================================

def get_conn():
    return psycopg2.connect(DB_DSN, cursor_factory=psycopg2.extras.RealDictCursor)


def fetch_chunks_without_embedding(conn, document_id=None):
    with conn.cursor() as cur:
        if document_id:
            cur.execute("""
                SELECT id, chunk_text FROM rag.chunks
                WHERE embedding IS NULL
                  AND document_id = %s
                  AND length(chunk_text) >= 20
                ORDER BY id LIMIT %s
            """, (document_id, BATCH_SIZE))
        else:
            cur.execute("""
                SELECT id, chunk_text FROM rag.chunks
                WHERE embedding IS NULL
                  AND length(chunk_text) >= 20
                ORDER BY id LIMIT %s
            """, (BATCH_SIZE,))
        return cur.fetchall()


def fetch_segments_without_embedding(conn, document_id=None):
    with conn.cursor() as cur:
        if document_id:
            cur.execute("""
                SELECT id, text_full FROM raw.segments
                WHERE embedding IS NULL
                  AND document_id = %s
                  AND length(coalesce(text_full, '')) >= 20
                ORDER BY id LIMIT %s
            """, (document_id, BATCH_SIZE))
        else:
            cur.execute("""
                SELECT id, text_full FROM raw.segments
                WHERE embedding IS NULL
                  AND length(coalesce(text_full, '')) >= 20
                ORDER BY id LIMIT %s
            """, (BATCH_SIZE,))
        return cur.fetchall()


def update_chunk_embeddings(conn, id_vector_pairs):
    with conn.cursor() as cur:
        for row_id, vector in id_vector_pairs:
            if vector is None:
                log.warning(f"Chunk {row_id} – kein Embedding, übersprungen.")
                continue
            cur.execute("""
                UPDATE rag.chunks
                SET embedding = %s::vector, updated_at = now()
                WHERE id = %s
            """, (str(vector), row_id))
    conn.commit()


def update_segment_embeddings(conn, id_vector_pairs):
    with conn.cursor() as cur:
        for row_id, vector in id_vector_pairs:
            if vector is None:
                log.warning(f"Segment {row_id} – kein Embedding, übersprungen.")
                continue
            cur.execute("""
                UPDATE raw.segments
                SET embedding = %s::vector, updated_at = now()
                WHERE id = %s
            """, (str(vector), row_id))
    conn.commit()


# =============================================================
# Text-Bereinigung
# =============================================================

def clean_text(text):
    """NUL entfernen + auf MAX_TOKENS truncaten."""
    text = (text or "").replace("\x00", "").strip()
    tokens = text.split()
    if len(tokens) > MAX_TOKENS:
        log.warning(f"Text mit {len(tokens)} Token auf {MAX_TOKENS} truncated.")
        text = " ".join(tokens[:MAX_TOKENS])
    return text


# =============================================================
# Mistral API – einzelner Request mit Retry
# =============================================================

def embed_one(client, text):
    """
    Embeddet einen einzelnen Text mit vollem Retry-Handling.
    Gibt Vektor oder None zurück.
    """
    clean = clean_text(text)
    for attempt in range(MAX_RETRIES):
        try:
            response = client.embeddings.create(
                model=EMBED_MODEL,
                inputs=[clean],
            )
            return response.data[0].embedding
        except Exception as e:
            err = str(e)
            if "429" in err or "rate" in err.lower() or "too many" in err.lower():
                wait = RETRY_WAIT * (attempt + 1)
                log.warning(f"Rate Limit – warte {wait}s... (Versuch {attempt+1}/{MAX_RETRIES})")
                time.sleep(wait)
            elif "400" in err and "token" in err.lower():
                # Text wirklich zu lang – nochmal härter truncaten
                tokens = clean.split()
                if len(tokens) > 1000:
                    tokens = tokens[:len(tokens)//2]
                    clean = " ".join(tokens)
                    log.warning(f"Token-Limit – truncate auf 4000 Token, retry...")
                else:
                    log.error(f"Text zu kurz zum Truncaten aber immer noch zu lang: {err}")
                    return None
            else:
                log.error(f"Unbekannter API-Fehler: {err}")
                return None
    log.error(f"Maximale Retries erreicht – Text übersprungen.")
    return None


# =============================================================
# Mistral API – Batch mit Fallback auf Einzel
# =============================================================

def embed_batch(client, texts):
    """
    Versucht Batch-Request. Bei Token-Limit → Einzel-Requests.
    Bei Rate Limit → Retry des gesamten Batches.
    """
    clean_texts = [clean_text(t) for t in texts]

    for attempt in range(MAX_RETRIES):
        try:
            response = client.embeddings.create(
                model=EMBED_MODEL,
                inputs=clean_texts,
            )
            return [item.embedding for item in response.data]
        except Exception as e:
            err = str(e)
            if "429" in err or "rate" in err.lower() or "too many" in err.lower():
                wait = RETRY_WAIT * (attempt + 1)
                log.warning(f"Rate Limit (Batch) – warte {wait}s...")
                time.sleep(wait)
            elif "400" in err and "token" in err.lower():
                # Einzeln verarbeiten mit echtem Retry
                log.warning(f"Token-Limit im Batch – verarbeite {len(texts)} Texte einzeln...")
                results = []
                for i, text in enumerate(texts):
                    vec = embed_one(client, text)
                    results.append(vec)
                    # Kurze Pause zwischen Einzel-Requests
                    time.sleep(0.5)
                return results
            else:
                log.error(f"Unbekannter API-Fehler (Batch): {err}")
                return [None] * len(texts)

    log.error("Maximale Retries (Batch) erreicht.")
    return [None] * len(texts)


# =============================================================
# Embed Chunks
# =============================================================

def embed_chunks(conn, client, document_id=None):
    total = 0
    while True:
        rows = fetch_chunks_without_embedding(conn, document_id)
        if not rows:
            break

        texts   = [r["chunk_text"] for r in rows]
        ids     = [r["id"] for r in rows]
        vectors = embed_batch(client, texts)

        update_chunk_embeddings(conn, zip(ids, vectors))
        total += len(rows)
        log.info(f"Chunks embedded: {total}")

        # Kurze Pause zwischen Batches
        time.sleep(1)

    log.info(f"✓ Chunks fertig: {total} total")


# =============================================================
# Embed Segments
# =============================================================

def embed_segments(conn, client, document_id=None):
    with conn.cursor() as cur:
        cur.execute("""
            SELECT column_name FROM information_schema.columns
            WHERE table_schema = 'raw' AND table_name = 'segments'
              AND column_name = 'embedding'
        """)
        if not cur.fetchone():
            log.warning("raw.segments hat keine embedding-Spalte.")
            log.info("Bitte ausführen:")
            log.info("  ALTER TABLE raw.segments ADD COLUMN embedding vector(1024);")
            log.info("  CREATE INDEX ON raw.segments USING hnsw (embedding vector_cosine_ops);")
            return

    total = 0
    while True:
        rows = fetch_segments_without_embedding(conn, document_id)
        if not rows:
            break

        texts   = [r["text_full"] for r in rows]
        ids     = [r["id"] for r in rows]
        vectors = embed_batch(client, texts)

        update_segment_embeddings(conn, zip(ids, vectors))
        total += len(rows)
        log.info(f"Segmente embedded: {total}")

        time.sleep(1)

    log.info(f"✓ Segmente fertig: {total} total")


# =============================================================
# Main
# =============================================================

def main():
    parser = argparse.ArgumentParser(description="Mistral Embeddings → rag.chunks + raw.segments")
    parser.add_argument("--mode", choices=["both", "chunks", "segments"], default="both")
    parser.add_argument("--document-id", type=int, default=None)
    args = parser.parse_args()

    if not MISTRAL_API_KEY:
        log.error("MISTRAL_API_KEY fehlt in .env")
        sys.exit(1)

    client = Mistral(api_key=MISTRAL_API_KEY)
    conn   = get_conn()

    try:
        if args.mode in ("both", "chunks"):
            log.info("=== Chunks embedden ===")
            embed_chunks(conn, client, args.document_id)

        if args.mode in ("both", "segments"):
            log.info("=== Segmente embedden ===")
            embed_segments(conn, client, args.document_id)

    except Exception as e:
        log.error(f"FEHLER: {e}\n{traceback.format_exc()}")
    finally:
        conn.close()

    log.info("Embedding abgeschlossen.")


if __name__ == "__main__":
    main()
