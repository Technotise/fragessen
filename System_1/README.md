# System 1 — Ingest

Admin-Oberfläche und Verarbeitungspipeline für kommunalpolitische Sitzungsprotokolle (PDF).
Teil der dreiteiligen FragEssen-Architektur — siehe [Root-README](../README.md).

**Aufgabe:** PDF-Upload → Seitenextraktion (Slice) → strukturierte Extraktion via Mistral
Document AI (JSON-Schema) → manuelle Review/Kuratierung → Export an das RAG-Backend
(System 2) per SFTP.

## Stack

- PHP 8.x (Shared Webhosting, z. B. Netcup)
- MySQL / MariaDB — **geteilte Datenbank mit System 3**, Schema: [`../db/schema.mysql.sql`](../db/schema.mysql.sql)
- Python 3 (nur `tools/slice_pdf.py`, Abhängigkeit: pypdf)
- Composer: phpseclib3 (SFTP)
- Mistral AI: Document AI / Annotations API

## Verzeichnisstruktur

```
System_1/
├── cron/
│   └── worker.php          # Pipeline-Worker (Cron), Dokument-Locking
└── httpdocs/               # Webroot
    ├── api/                # Mistral Document AI Call, Queue-Status, Retry
    ├── src/                # Config, DB, Auth, Locks
    │   └── schema/
    │       └── protokoll_extraktion.json   # JSON-Schema für Document AI
    ├── tools/              # Task-Skripte: slice, extract_core/attendance/agenda
    ├── ui/                 # Layout, CSS
    ├── storage/pdf/        # PDF-Ablage (<gremium_key>/<jahr>/), nicht im Repo
    ├── py_pkgs/            # Vendored Python-Pakete, nicht im Repo (s. Setup)
    ├── upload.php          # PDF-Upload
    ├── queue.php           # Verarbeitungs-Queue
    ├── review_core.php     # Review: Sitzungs-Stammdaten
    ├── review_attendance.php
    ├── review_agenda.php
    ├── export.php          # Export → System 2 (SFTP)
    └── logs.php            # Log-Ansicht
```

## Verarbeitungspipeline

Jedes Dokument durchläuft Tasks in `document_state` (Reihenfolge via `task_order`):

| Task | Skript | Funktion |
|---|---|---|
| `slice` | `tools/slice_pdf.php` (+ `.py`) | Relevante Seiten aus dem Protokoll extrahieren |
| `get_json` | `api/mistral_docai.php` | Strukturierte Extraktion via Mistral Document AI gegen `protokoll_extraktion.json` |
| `extract_core` | `tools/extract_core.php` | Sitzungs-Stammdaten (Datum, Ort, Typ, ...) |
| `extract_attendance` | `tools/extract_attendance.php` | Anwesenheitsliste |
| `extract_agenda` | `tools/extract_agenda.php` | Tagesordnungspunkte |

Der Cron-Worker (`cron/worker.php`) claimed pro Lauf genau ein Dokument exklusiv
(Lock-Spalten in `documents`: `processing_token`, `processing_lock_until`) und arbeitet
dessen Tasks sequenziell ab. Mehrere parallele Worker sind dadurch möglich.

Nach der Extraktion werden die Ergebnisse in der Web-UI reviewt und kuratiert
(`review_*.php`). Der Export (`export.php`) überträgt PDF + kuratierte JSON-Dateien
(`documents.json`, `core.json`, `attendance.json`, `agenda.json`) per SFTP in das
Incoming-Verzeichnis von System 2 und schließt mit einer `ready.done`-Markerdatei ab.
Nicht-öffentliche Tagesordnungspunkte (`section_curated = 'non_public'`) werden beim
Export ausgefiltert.

## Setup

### 1. Dateien & Composer

```bash
# im httpdocs/-Verzeichnis
composer install        # phpseclib3
```

### 2. Python-Abhängigkeit (Shared Hosting ohne venv)

Auf Shared Webhosting ohne venv-Möglichkeit wird pypdf direkt ins Projekt installiert:

```bash
pip install --target=httpdocs/py_pkgs pypdf
```

`tools/slice_pdf.py` erwartet `py_pkgs/` im Suchpfad. Die Python-Version des Hostings
muss zur Installation passen (aktuell getestet: CPython 3.13).

### 3. Konfiguration

```bash
cp httpdocs/src/config_example.php httpdocs/src/config.php
```

Anzupassen:

- `db.*` — Zugangsdaten der geteilten MySQL-Datenbank
- `mistral_api_key` — API-Key ([console.mistral.ai](https://console.mistral.ai))
- `cli.php` / `cli.ini_scan_dir` — Pfad zum PHP-CLI-Binary des Hostings
  (Netcup-Beispiel: `/usr/local/php85/bin/php`); bei Standard-Setups entbehrlich
- `system2.sftp.*` — Host, User und **privater ed25519-Key als String** für den
  Export an System 2

`config.php` ist per `.gitignore` ausgeschlossen und darf nie committet werden.

### 4. Datenbank

```bash
mysql -u <user> -p <dbname> < ../db/schema.mysql.sql
```

Das Schema umfasst auch die Tabellen von System 3 (geteilte Datenbank).

### 5. Cron

Worker regelmäßig ausführen, z. B. alle 15 Minuten:

```
*/15 * * * * /usr/local/php85/bin/php /pfad/zu/System_1/cron/worker.php
```

Der Worker beendet sich selbst, wenn keine Dokumente anstehen. Logs:
`httpdocs/var/logs/cron_worker.log`.

### 6. SFTP-Schlüsselpaar

```bash
ssh-keygen -t ed25519 -f system1_to_system2 -N ""
```

Public Key auf System 2 in `~/.ssh/authorized_keys` des Ingest-Users eintragen,
Private Key als String in `config.php` (`system2.sftp.private_key`).

## Sicherheitshinweise

- `storage/pdf/` ist per `.htaccess` gegen direkten Webzugriff gesperrt —
  nach Deployment verifizieren (Aufruf muss 403 liefern).
- Alle Admin-Seiten setzen eine Session via `src/auth.php` voraus.
- Secrets (DB, Mistral-Key, SFTP-Key) liegen ausschließlich in `config.php`.

## Lizenz

EUPL-1.2 — siehe [LICENSE](../LICENSE) im Repository-Root.
