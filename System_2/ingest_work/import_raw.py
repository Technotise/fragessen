#!/usr/bin/env python3
"""
import_raw.py – System 2 · JSON → raw Layer Importer
======================================================
Trigger:  ops.ingest_jobs.status = 'done'
          + ops.raw_import_jobs.status = 'pending'
Strategie: Hard-Replace (DELETE CASCADE + INSERT)
Move:      incoming/{id}/ → done/{id}/  |  failed/{id}/
Logging:   ops.ingest_logs + ops.raw_import_jobs

Usage:
    python ingest_work/import_raw.py --root /pfad/zu/ingest/incoming
    python ingest_work/import_raw.py --root /pfad/zu/ingest/incoming --once
    python ingest_work/import_raw.py --root /pfad/zu/ingest/incoming --package-id 1 --once
"""

import argparse
import hashlib
import json
import logging
import os
import shutil
import sys
import time
import traceback
from pathlib import Path

from dotenv import load_dotenv
load_dotenv(Path(__file__).resolve().parent / ".env", override=True)

import psycopg2
import psycopg2.extras

# =============================================================
# Konfiguration
# =============================================================

DB_DSN = (os.environ.get("KOMMRAG_DSN") or "").strip()
if not DB_DSN:
    sys.exit("KOMMRAG_DSN nicht gesetzt (Umgebungsvariable oder .env), z. B. postgresql://user:pass@localhost:5432/kommrag")

POLL_INTERVAL_SEC = 10
WORKER_ID         = os.environ.get("WORKER_ID", "import_raw_01")

# =============================================================
# Logging
# =============================================================

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    handlers=[logging.StreamHandler(sys.stdout)]
)
log = logging.getLogger("import_raw")


# =============================================================
# DB-Helpers
# =============================================================

def get_conn():
    return psycopg2.connect(DB_DSN, cursor_factory=psycopg2.extras.RealDictCursor)


def log_to_db(conn, job_id, level, scope, message, details=None):
    try:
        with conn.cursor() as cur:
            cur.execute("""
                INSERT INTO ops.ingest_logs (job_id, level, scope, message, details)
                VALUES (%s, %s, %s, %s, %s)
            """, (
                job_id, level, scope, message,
                json.dumps(details, ensure_ascii=False) if details else '{}'
            ))
        conn.commit()
    except Exception as e:
        log.warning(f"DB-Log fehlgeschlagen: {e}")


def fetch_pending_jobs(conn):
    with conn.cursor() as cur:
        cur.execute("""
            INSERT INTO ops.raw_import_jobs (job_id, status)
            SELECT id, 'pending'
            FROM ops.ingest_jobs
            WHERE status = 'done'
            ON CONFLICT (job_id) DO NOTHING
        """)
        conn.commit()

        cur.execute("""
            SELECT
                rij.id        AS raw_job_id,
                rij.job_id,
                ij.package_dir,
                ij.document_key,
                ij.meta
            FROM ops.raw_import_jobs rij
            JOIN ops.ingest_jobs ij ON ij.id = rij.job_id
            WHERE rij.status = 'pending'
            ORDER BY rij.id ASC
        """)
        return cur.fetchall()


def fetch_job_by_package_id(conn, package_id):
    unix_pat = f"%/{package_id}"
    with conn.cursor() as cur:
        cur.execute("""
            INSERT INTO ops.raw_import_jobs (job_id, status)
            SELECT id, 'pending'
            FROM ops.ingest_jobs
            WHERE status = 'done'
              AND package_dir LIKE %s
            ON CONFLICT (job_id) DO NOTHING
        """, (unix_pat,))
        conn.commit()

        cur.execute("""
            SELECT
                rij.id        AS raw_job_id,
                rij.job_id,
                ij.package_dir,
                ij.document_key,
                ij.meta
            FROM ops.raw_import_jobs rij
            JOIN ops.ingest_jobs ij ON ij.id = rij.job_id
            WHERE rij.status = 'pending'
              AND ij.package_dir LIKE %s
            LIMIT 1
        """, (unix_pat,))
        return cur.fetchone()


def set_raw_job_started(conn, raw_job_id):
    with conn.cursor() as cur:
        cur.execute("""
            UPDATE ops.raw_import_jobs
            SET started_at = now()
            WHERE id = %s
        """, (raw_job_id,))
    conn.commit()


def set_raw_job_imported(conn, raw_job_id, raw_document_id):
    with conn.cursor() as cur:
        cur.execute("""
            UPDATE ops.raw_import_jobs
            SET status = 'imported',
                raw_document_id = %s,
                imported_at = now()
            WHERE id = %s
        """, (raw_document_id, raw_job_id))
    conn.commit()


def set_raw_job_failed(conn, raw_job_id, error):
    with conn.cursor() as cur:
        cur.execute("""
            UPDATE ops.raw_import_jobs
            SET status = 'failed',
                error  = %s
            WHERE id = %s
        """, (error, raw_job_id))
    conn.commit()


# =============================================================
# Helpers
# =============================================================

def load_json(path):
    if not path.exists():
        return None
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def strip_nul(text):
    """Entfernt NUL-Zeichen die PostgreSQL nicht akzeptiert."""
    if not text:
        return text
    return text.replace("\x00", "")


def sha256(text):
    if not text:
        return None
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def jdumps(obj):
    """json.dumps mit UTF-8 und ensure_ascii=False."""
    return json.dumps(obj, ensure_ascii=False)


# =============================================================
# Insert: raw.documents
# =============================================================

def insert_document(cur, package_id, documents_json, segments_json, job):
    doc   = documents_json.get("document", {})
    grem  = documents_json.get("gremium", {})
    smeta = (segments_json or {}).get("meta", {})
    # meta aus DB ist bereits ein dict (psycopg2 parsed JSONB automatisch)
    jmeta = job.get("meta") or {}

    gremium_key      = grem.get("key")
    file_hash_sha256 = doc.get("file_hash_sha256")

    cur.execute("""
        DELETE FROM raw.documents
        WHERE gremium_key = %s AND file_hash_sha256 = %s
    """, (gremium_key, file_hash_sha256))

    cur.execute("""
        INSERT INTO raw.documents (
            package_id,
            gremium_key,             gremium_name,
            file_hash_sha256,
            source_filename,         source_path,
            meeting_date,            mime_type,
            document_key,
            pipeline_ok,             pipeline_score,
            pipeline_stage,          pipeline_used_docai,
            pipeline_recommendation, pipeline_span_coverage,
            tops_total,              tops_found,
            pages_total,
            meta
        ) VALUES (
            %s, %s, %s, %s, %s, %s, %s, %s, %s,
            %s, %s, %s, %s, %s, %s, %s, %s, %s, %s
        )
        RETURNING id
    """, (
        package_id,
        gremium_key,
        grem.get("name"),
        file_hash_sha256,
        doc.get("original_filename"),
        smeta.get("pdf"),
        None,
        "application/pdf",
        job.get("document_key"),
        smeta.get("pipeline_ok"),
        smeta.get("pipeline_score"),
        smeta.get("pipeline_stage"),
        smeta.get("pipeline_used_docai"),
        smeta.get("pipeline_recommendation"),
        smeta.get("pipeline_span_coverage"),
        smeta.get("tops_total"),
        smeta.get("tops_found"),
        smeta.get("pages_total"),
        jdumps(jmeta),
    ))
    return cur.fetchone()["id"]


# =============================================================
# Insert: raw.document_core
# =============================================================

def insert_core(cur, document_id, core_json):
    if not core_json:
        return

    sitzungsdatum = core_json.get("curated_sitzungsdatum")

    cur.execute("""
        INSERT INTO raw.document_core (
            document_id,    extracted_json,
            curated_sitzungsdatum, curated_uhrzeit_start,
            curated_ort,    curated_sitzungstyp,
            curated_niederschrift_nr,
            needs_review
        ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
    """, (
        document_id,
        jdumps(core_json),
        sitzungsdatum,
        core_json.get("curated_uhrzeit_start"),
        core_json.get("curated_ort"),
        core_json.get("curated_sitzungstyp"),
        str(core_json["curated_niederschrift_nr"])
            if core_json.get("curated_niederschrift_nr") is not None else None,
        False,
    ))

    if sitzungsdatum:
        cur.execute(
            "UPDATE raw.documents SET meeting_date = %s WHERE id = %s",
            (sitzungsdatum, document_id)
        )


# =============================================================
# Insert: raw.document_agenda_items
# =============================================================

def insert_agenda(cur, document_id, agenda_json):
    if not agenda_json:
        return
    for item in agenda_json:
        cur.execute("""
            INSERT INTO raw.document_agenda_items (
                document_id,          row_index,            extracted_json,
                top_key_extracted,    title_extracted,
                drucksache_extracted, section_extracted,
                top_key_curated,      title_curated,
                drucksache_curated,   section_curated,
                top_num,  top_suffix, top_sub, top_norm,
                needs_review
            ) VALUES (
                %s, %s, %s, %s, %s, %s, %s,
                %s, %s, %s, %s, %s, %s, %s, %s, %s
            )
        """, (
            document_id,
            item.get("row_index"),
            jdumps(item),
            item.get("top_key_curated"),
            item.get("title_curated"),
            item.get("drucksache_curated"),
            item.get("section_curated"),
            item.get("top_key_curated"),
            item.get("title_curated"),
            item.get("drucksache_curated"),
            item.get("section_curated"),
            item.get("top_num"),
            item.get("top_suffix"),
            item.get("top_sub"),
            item.get("top_norm"),
            False,
        ))


# =============================================================
# Insert: raw.document_attendance_rows
# =============================================================

def insert_attendance(cur, document_id, attendance_json):
    if not attendance_json:
        return
    for row in attendance_json:
        cur.execute("""
            INSERT INTO raw.document_attendance_rows (
                document_id,       row_index,          extracted_json,
                role_extracted,    last_name_extracted, faction_extracted,
                role_curated,      last_name_curated,   faction_curated,
                salutation,        title_akad,
                name_norm,         base_norm,           row_hash,
                needs_review
            ) VALUES (
                %s, %s, %s, %s, %s, %s,
                %s, %s, %s, %s, %s, %s, %s, %s, %s
            )
        """, (
            document_id,
            row.get("row_index"),
            jdumps(row),
            row.get("role_curated"),
            row.get("last_name_curated"),
            row.get("faction_raw_curated"),
            row.get("role_curated"),
            row.get("last_name_curated"),
            row.get("faction_raw_curated"),
            row.get("salutation_curated"),
            row.get("title_curated"),
            row.get("name_norm"),
            row.get("base_norm"),
            row.get("row_hash"),
            False,
        ))


# =============================================================
# Insert: raw.segments
# =============================================================

def insert_segments(cur, document_id, segments_json):
    if not segments_json:
        return
    smeta = segments_json.get("meta", {})

    for seg in segments_json.get("segments", []):
        text = strip_nul(seg.get("text_full"))
        cur.execute("""
            INSERT INTO raw.segments (
                document_id,
                type,         top_key,      title,
                start_page,   start_char,
                end_page,     end_char,
                hint_anchor,
                text_full,    content_hash,
                match_method, match_score,  confidence,
                meta
            ) VALUES (
                %s, %s, %s, %s, %s, %s, %s, %s,
                %s, %s, %s, %s, %s, %s, %s
            )
        """, (
            document_id,
            seg.get("type"),
            seg.get("top_key"),
            seg.get("title"),
            seg.get("start_page"),
            seg.get("start_char"),
            seg.get("end_page"),
            seg.get("end_char"),
            None,
            text,
            sha256(text),
            seg.get("match_method"),
            seg.get("match_score"),
            seg.get("confidence"),
            jdumps({
                "pipeline_score": smeta.get("pipeline_score"),
                "pipeline_stage": smeta.get("pipeline_stage"),
            }),
        ))


# =============================================================
# Paket-Import (atomar, transaktional)
# =============================================================

def import_package(conn, job, root):
    raw_job_id = job["raw_job_id"]
    job_id     = job["job_id"]
    pkg_dir    = Path(job["package_dir"])
    package_id = int(pkg_dir.name)

    log.info(f"[pkg {package_id}] Starte Import (job_id={job_id}, raw_job_id={raw_job_id})")
    set_raw_job_started(conn, raw_job_id)
    log_to_db(conn, job_id, "info", "import_raw",
              "Import gestartet", {"package_dir": str(pkg_dir)})

    documents_json  = load_json(pkg_dir / "documents.json")
    core_json       = load_json(pkg_dir / "core.json")
    agenda_json     = load_json(pkg_dir / "agenda.json")
    attendance_json = load_json(pkg_dir / "attendance.json")
    segments_json   = load_json(pkg_dir / "segments.json")

    if not documents_json:
        msg = f"documents.json fehlt in {pkg_dir}"
        log.error(f"[pkg {package_id}] {msg}")
        log_to_db(conn, job_id, "error", "import_raw", msg)
        set_raw_job_failed(conn, raw_job_id, msg)
        _move_package(pkg_dir, root, "failed")
        return False

    try:
        with conn:
            with conn.cursor() as cur:
                doc_id = insert_document(
                    cur, package_id, documents_json, segments_json, job
                )
                insert_core(cur, doc_id, core_json)
                insert_agenda(cur, doc_id, agenda_json)
                insert_attendance(cur, doc_id, attendance_json)
                insert_segments(cur, doc_id, segments_json)

        set_raw_job_imported(conn, raw_job_id, doc_id)
        log_to_db(conn, job_id, "info", "import_raw",
                  "Import erfolgreich", {"raw_document_id": doc_id})
        log.info(f"[pkg {package_id}] ✓ Importiert (raw.documents.id={doc_id})")

        _move_package(pkg_dir, root, "done")
        return True

    except Exception as e:
        tb = traceback.format_exc()
        log.error(f"[pkg {package_id}] FEHLER: {e}\n{tb}")
        log_to_db(conn, job_id, "error", "import_raw", str(e), {"traceback": tb})
        set_raw_job_failed(conn, raw_job_id, str(e))
        _move_package(pkg_dir, root, "failed")
        return False


def _move_package(pkg_dir, root, target):
    dest_base = root.parent / target
    dest_base.mkdir(parents=True, exist_ok=True)
    dest = dest_base / pkg_dir.name
    if dest.exists():
        shutil.rmtree(dest)
    shutil.move(str(pkg_dir), str(dest))
    log.info(f"Paket verschoben: {pkg_dir.name} → {target}/")


# =============================================================
# Worker-Loop
# =============================================================

def run_worker(root, once=False, package_id=None):
    log.info(f"Worker '{WORKER_ID}' gestartet. Root: {root}")

    while True:
        try:
            conn = get_conn()

            if package_id:
                job  = fetch_job_by_package_id(conn, package_id)
                jobs = [job] if job else []
            else:
                jobs = fetch_pending_jobs(conn)

            if jobs:
                log.info(f"{len(jobs)} Job(s) gefunden.")
                for job in jobs:
                    import_package(conn, job, root)
            else:
                log.debug("Keine Jobs. Warte...")

            conn.close()

        except Exception as e:
            log.error(f"Worker-Fehler: {e}\n{traceback.format_exc()}")

        if once or package_id:
            break

        time.sleep(POLL_INTERVAL_SEC)


# =============================================================
# CLI
# =============================================================

def main():
    parser = argparse.ArgumentParser(description="System 2 · raw Layer Importer")
    parser.add_argument("--root", required=True, type=Path)
    parser.add_argument("--once", action="store_true")
    parser.add_argument("--package-id", type=int, default=None)
    args = parser.parse_args()
    run_worker(root=args.root, once=args.once, package_id=args.package_id)


if __name__ == "__main__":
    main()
