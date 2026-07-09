#!/usr/bin/env bash
# Protokoll-Pipeline – verarbeitet alle Pakete in incoming/ automatisch (Schritte 1–5).
# Annahme: Layout auf System 2 identisch zu System 1. Nur die 5 Pfad-Variablen unten anpassen.

set -uo pipefail   # bewusst kein -e: Loop soll bei Einzelfehlern weiterlaufen

# ── venv aktivieren (damit auch via Cron die richtigen Pakete genutzt werden) ───
source /home/kommrag/.venv/bin/activate

# ── Pfade ─────────────────────────────────────────────────────────────────────
INGEST_WORK=/home/kommrag/ingest_work
RAG_WORK=/home/kommrag/rag_work
INCOMING=/home/kommrag/ingest/incoming
DONE=/home/kommrag/ingest/done
LOG_JSONL="$INCOMING/logs/segments_pipeline.jsonl"

# ── Lock (verhindert parallele Cron-Läufe) ──────────────────────────────────────
exec 9>/tmp/kommrag_pipeline.lock
flock -n 9 || { echo "Pipeline läuft bereits – Abbruch."; exit 0; }

log() { printf '[%s] %s\n' "$(date '+%F %T')" "$*"; }

# ── DSN aus .env (nur für psql-Gates; Python-Skripte laden .env selbst) ─────────
DSN=$(grep -E '^KOMMRAG_DSN=' "$INGEST_WORK/.env" | head -1 | cut -d= -f2-)
[ -n "$DSN" ] || { log "FEHLER: KOMMRAG_DSN nicht in $INGEST_WORK/.env gefunden."; exit 1; }

# ── 0) Pending-Pakete erfassen (VOR dem Verschieben durch import_raw) ───────────
mapfile -t PKGS < <(find "$INCOMING" -mindepth 1 -maxdepth 1 -type d ! -name logs -printf '%f\n' | sort)
if [ "${#PKGS[@]}" -eq 0 ]; then log "Keine Pakete in incoming/. Nichts zu tun."; exit 0; fi
log "Gefundene Pakete (${#PKGS[@]}): ${PKGS[*]}"

# ── 1) Segmentierung (batch über alle Pakete) ───────────────────────────────────
cd "$INGEST_WORK" || exit 1
log "Schritt 1: Segmentierung"
python run_segments_batch.py \
  --root "$INCOMING" \
  --pipeline "$INGEST_WORK/segments_pipeline.py" \
  --segmenter "$INGEST_WORK/segments.py" \
  --workers 1
# Soft-Gate: tail prüft nur die letzte JSONL-Zeile (Anleitung). Bei Multi-Paket-Batch
# ist das nur ein Indikator – die echte Pro-Paket-Validierung passiert über done/failed
# (Schritt 3) und die Embedding-NULL-Checks (Schritt 5).
if ! tail -1 "$LOG_JSONL" 2>/dev/null | grep -q '"pipeline_ok": true'; then
  log "WARNUNG: letzter JSONL-Eintrag hat pipeline_ok != true. Fahre fort, prüfe pro Paket."
fi

# ── 2) DB-Rekonstruktion (idempotent, ON CONFLICT DO UPDATE) ────────────────────
log "Schritt 2: Reconstruct ops.ingest_jobs"
python reconstruct_ops_from_jsonl.py \
  --jsonl "$LOG_JSONL" \
  --job_type import_json \
  --dsn "$DSN" \
  || log "WARNUNG: reconstruct meldete Fehler – fahre fort."

# ── 3–5) Pro Paket ──────────────────────────────────────────────────────────────
ok=0; fail=0
for ID in "${PKGS[@]}"; do
  log "═══ Paket $ID ═══"

  # 3) Raw-Import (verschiebt selbst nach done/ oder failed/)
  if ! python import_raw.py --root "$INCOMING" --package-id "$ID" --once; then
    log "  import_raw fehlgeschlagen → übersprungen"; ((fail++)); continue
  fi
  if [ ! -d "$DONE/$ID" ]; then
    log "  Paket nicht in done/ (vermutlich failed/) → übersprungen"; ((fail++)); continue
  fi

  # document_id(s) auflösen – package_id ≠ document_id
  mapfile -t DOCIDS < <(psql "$DSN" -tAc \
    "SELECT id FROM raw.documents WHERE package_id = '$ID';" 2>/dev/null)
  if [ "${#DOCIDS[@]}" -eq 0 ] || [ -z "${DOCIDS[0]:-}" ]; then
    log "  keine document_id in raw.documents → übersprungen"; ((fail++)); continue
  fi

  for DID in "${DOCIDS[@]}"; do
    [ -z "$DID" ] && continue
    log "  document_id=$DID"

    # 4) Chunking
    if ! python "$RAG_WORK/extract_and_chunk.py" --done "$DONE" --document-id "$DID" --once; then
      log "    Chunking fehlgeschlagen"; ((fail++)); continue
    fi

    # 5) Embedding
    if ! python "$RAG_WORK/embed.py" --mode both --document-id "$DID"; then
      log "    Embedding fehlgeschlagen"; ((fail++)); continue
    fi

    # Verifikation: beide NULL-Counts müssen 0 sein
    C=$(psql "$DSN" -tAc "SELECT COUNT(*) FROM rag.chunks   WHERE document_id = $DID AND embedding IS NULL;" 2>/dev/null); C=${C:--1}
    S=$(psql "$DSN" -tAc "SELECT COUNT(*) FROM raw.segments WHERE document_id = $DID AND embedding IS NULL;" 2>/dev/null); S=${S:--1}
    if [ "$C" -eq 0 ] && [ "$S" -eq 0 ]; then
      log "    ✅ vollständig embedded"; ((ok++))
    else
      log "    ⚠️  unvollständig: chunks_null=$C segments_null=$S"; ((fail++))
    fi
  done
done

log "Fertig. Erfolgreich: $ok | Probleme: $fail"
[ "$fail" -eq 0 ]
