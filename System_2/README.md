# System 2 — RAG-Backend

Retrieval-Augmented-Generation-Backend von FragEssen: importiert die von System 1
gelieferten Dokumentpakete, segmentiert Sitzungsprotokolle, erzeugt Embeddings und
beantwortet Anfragen des Web-Frontends (System 3) über eine FastAPI.
Teil der dreiteiligen Architektur — siehe [Root-README](../README.md).

## Stack

- Python 3.11+, venv
- PostgreSQL 15+ mit Extensions **pgvector** und **pg_trgm**
- FastAPI + uvicorn (systemd-Service)
- Mistral AI: `mistral-ocr-latest` (OCR/Document AI in der Segmentierung),
  `mistral-embed` (Embeddings, 1024 Dim.), `mistral-medium-latest`
  (Query-Condensation) sowie `mistral-small-latest`, `mistral-medium-latest`
  und `mistral-large-latest` für die Antwortgenerierung, wählbar über den
  `quality`-Parameter (Standard: `mistral-small-latest`)

## Verzeichnisstruktur

```
System_2/
├── schema.postgres.sql     # Schemas: raw, rag, ops
├── requirements.txt
├── kommrag-api.service     # systemd-Unit
├── run_pipeline.sh         # Cron-Einstieg: Triage → Import → Chunks → Embeddings
├── ingest_work/            # Segmentierungs-Pipeline
│   ├── .env.example
│   ├── triage_packages.py          # incoming/ → done/ | failed/
│   ├── import_raw.py               # JSON-Pakete → raw.*
│   ├── run_segments_batch.py       # Batch-Runner
│   ├── segments_pipeline.py        # Orchestrierung inkl. DocAI-Fallback/Repair
│   ├── segments.py                 # deterministische, agenda-getriebene Segmentierung
│   ├── segments_document_ai.py     # Mistral OCR/Document-AI-Anbindung
│   ├── segments_validate.py        # Span-Validierung
│   ├── segments_seg_*.py           # Matching, Layout, Content-Heuristiken, Typen
│   └── segments_utils.py           # Offset-/Match-Space-Normalisierung
├── rag_work/               # Chunking + Embeddings
│   ├── .env.example
│   ├── extract_and_chunk.py        # Sliding Window: 600 Tokens, 20 % Overlap
│   └── embed.py                    # mistral-embed → pgvector
├── web_api/                # API für System 3
│   ├── .env.example
│   ├── app.py                      # FastAPI: /chat, /chat/stream, /session, /health, /gremien
│   ├── retrieve.py                 # Multi-Layer Retrieval (Dense + Sparse + Trigram, RRF)
│   ├── answer.py                   # Prompting, Antwortgenerierung, Streaming
│   └── synonyms.py                 # Synonym-Expansion (rag.synonyms, LLM-Fallback)
└── ingest/                 # Laufzeitdaten (nicht im Repo): incoming/, done/, failed/
```

## Datenfluss

1. **Anlieferung:** System 1 legt pro Dokument ein Paket unter `ingest/incoming/<id>/`
   ab (PDF + `documents.json`, `core.json`, `attendance.json`, `agenda.json`,
   abgeschlossen durch `ready.done`).
2. **Triage** (`triage_packages.py`): prüft Vollständigkeit und Segmentqualität,
   verschiebt nach `done/` bzw. `failed/`.
3. **Import** (`import_raw.py`): schreibt Pakete nach `raw.documents`,
   `raw.document_core`, `raw.document_agenda_items`, `raw.document_attendance_rows`;
   Job-Steuerung über `ops.ingest_jobs` / `ops.raw_import_jobs`.
4. **Segmentierung** (`segments*.py`): agenda-getriebene Zerlegung der Protokolle in
   Vorwort / TOPs / Nachwort / Anhang. Deterministisches Matching (Marker-Regex,
   Layout-Heuristiken, DP-Pfadwahl); bei unvollständigen Treffern Mistral
   Document AI als Repair-Fallback. Ergebnis in `raw.segments`.
5. **Chunking + Embeddings** (`rag_work/`): Sliding-Window-Chunks (600 Tokens,
   20 % Overlap) nach `rag.chunks`, Embeddings via `mistral-embed` in
   pgvector-Spalten (Chunks und Segmente).
6. **Serving** (`web_api/`): Multi-Layer Retrieval über Chunks, Segmente und
   strukturierte Metadaten, fusioniert per Reciprocal Rank Fusion;
   Antwortgenerierung mit dem über `quality` gewählten Modell.

`run_pipeline.sh` fasst die Schritte 2–5 zusammen (flock gegen Parallel-Läufe,
strukturiertes JSONL-Logging) und läuft per Cron.

## Setup

### 1. System & Datenbank

```bash
# PostgreSQL-Extensions müssen installierbar sein (Paket je nach Distribution,
# z. B. postgresql-16-pgvector)
createdb kommrag
psql -d kommrag -f schema.postgres.sql
```

### 2. Python-Umgebung

```bash
python3 -m venv ~/.venv
~/.venv/bin/pip install -r requirements.txt
```

### 3. Konfiguration

Je Komponente eine `.env` aus dem Example anlegen:

```bash
cp ingest_work/.env.example ingest_work/.env
cp rag_work/.env.example    rag_work/.env
cp web_api/.env.example     web_api/.env
```

Pflichtwerte überall: `KOMMRAG_DSN` (PostgreSQL-DSN) und `MISTRAL_API_KEY`.
`web_api/.env` zusätzlich: `KOMMRAG_API_KEY` — Shared Secret zur Authentifizierung
von System 3 (erzeugen z. B. mit `openssl rand -hex 32`; derselbe Wert gehört in
die `config.php` von System 3). `ingest_work/.env` enthält darüber hinaus
Tuning-Parameter der Pipeline (Schwellwerte, Timeouts, Repair-Fenster) — Defaults
siehe Example.

### 4. SFTP-Anlieferung von System 1

Eingehender Benutzer (z. B. `kommrag`) mit dem Public Key aus System 1 in
`~/.ssh/authorized_keys`; Zielverzeichnis `~/ingest/incoming` (muss dem in
System 1 konfigurierten `remote_base_dir` entsprechen).

### 5. Pipeline per Cron

```
*/15 * * * * /home/kommrag/run_pipeline.sh
```

### 6. API als systemd-Service

```bash
sudo cp kommrag-api.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now kommrag-api
curl http://127.0.0.1:8000/health
```

Die API bindet bewusst nur an `127.0.0.1` — System 3 erreicht sie über einen
vorgelagerten Reverse Proxy (TLS-Terminierung) oder einen Tunnel; zusätzlich ist
jeder Request per `X-API-Key`-Header (`KOMMRAG_API_KEY`) authentifiziert.

## Architektur-Notizen

- **Retrieval:** Drei Kanäle (Dense via pgvector/HNSW, Sparse via tsvector/german,
  Trigram via pg_trgm) über zwei Ebenen (`rag.chunks`, `raw.segments`) plus
  strukturierte Metadaten, fusioniert per Reciprocal Rank Fusion. `gremium_key`
  filtert durchgängig nach Gremium.
- **Suchmodi:** `relevant`, `recent`, `breadth` steuern Recency-Boost,
  Dokument-Cap und Kandidaten-Multiplikator.
- **Determinismus:** Temperature 0.0 für Antwort- und Condense-Schritt.
- **Offset- vs. Match-Space:** Segment-Spans referenzieren ausschließlich die
  kanonische Offset-Normalisierung (`segments_utils.py`) — aggressivere
  Normalisierung existiert nur für Matching und darf nie Spans erzeugen.

## Lizenz

EUPL-1.2 — siehe [LICENSE](../LICENSE) im Repository-Root.
