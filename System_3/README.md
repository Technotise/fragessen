# System 3 — Frontend

Öffentliches Chat-Interface von FragEssen (fragessen.stadtstimme.de): nimmt Fragen
zu kommunalpolitischen Sitzungsprotokollen entgegen, reicht sie an das RAG-Backend
(System 2) weiter und rendert Antworten mit Quellenangaben. Dazu Admin-Panel,
Zugangscode-Verwaltung, Rate-Limiting und eine optionale Evaluations-Umfrage.
Teil der dreiteiligen Architektur — siehe [Root-README](../README.md).

## Stack

- PHP 8.x (Shared Webhosting, z. B. Netcup)
- MySQL / MariaDB — **geteilte Datenbank mit System 1**, Schema: [`../db/schema.mysql.sql`](../db/schema.mysql.sql)
- Kein Framework, kein Build-Schritt, keine externen Frontend-Dependencies
- Anbindung an System 2 per HTTP (cURL), authentifiziert über `X-API-Key`

## Verzeichnisstruktur

```
System_3/httpdocs/
├── index.php                 # Chat-UI (Filter: Gremium, Zeitraum, Antwortlänge,
│                             #   Qualität, Quellenanzahl; Theme; Session-Handling)
├── api_bridge.php            # Proxy → System 2 /chat (JSON)
├── api_bridge_stream.php     # Proxy → System 2 /chat/stream (NDJSON)
├── feedback.php              # 👍/👎 zu Antworten
├── redeem_code.php           # Zugangscode einlösen
├── clear_session.php         # Gesprächsverlauf zurücksetzen
├── admin.php / admin_api.php # Admin-Panel (Logs, Feedback, Codes)
├── config_example.php        # → kopieren nach config.php
├── style.css / fonts.css
├── lib/
│   ├── security.php          # Prompt-Injection-Vorfilter, PDO-Helper
│   ├── ratelimit.php         # Rate-Limiting, Code-Einlösung, Usage-Tracking
│   └── chatlog.php           # Chat-Log + Feedback-Persistenz
├── pages/
│   ├── filter-hilfe.html
│   ├── impressum.example.html            # → kopieren, Platzhalter ersetzen
│   ├── datenschutz_fragessen.example.html
│   └── datenschutz_umfrage.example.html
├── assets/fonts/ubuntu/      # Ubuntu-Fontfamilie (Ubuntu Font Licence 1.0,
│                             #   Lizenztext liegt bei)
└── umfrage/                  # Evaluations-Umfrage (2-stufig, anonym,
    ├── index.php, stage2.php #   Session-Token zur Teil-1/2-Zuordnung)
    ├── submit.php, danke.php
    ├── admin.php
    └── lib/questions.php, db.php
```

## Funktionsweise

**Chat-Flow:** `index.php` sendet Anfragen an `api_bridge.php` bzw.
`api_bridge_stream.php`. Die Bridge prüft Session, Rate-Limit und den
Prompt-Injection-Vorfilter, reicht die Anfrage mit `X-API-Key` an System 2 weiter
und persistiert Frage, Antwort, Quellen und Laufzeit in `chat_logs`.

**Zugangsstufen:**
- *Anonym:* Rate-Limit pro IP-Hash (`rate_limit_window` / `rate_limit_requests`
  in der Config), Standard-Qualitätsstufe.
- *Session-Code:* schaltet eine Session für `session_lifetime` frei
  (Standard 24 h).
- *Persistent-Code:* wird zusätzlich im LocalStorage abgelegt und bei neuen
  Sessions automatisch wieder eingelöst; Ablauf folgt dem Code selbst.
  Höhere Qualitätsstufen (größeres Modell) sind an Codes gebunden;
  Nutzung wird pro Code und Stufe aggregiert gezählt (`code_usage_stats`).

**Sicherheit:** `lib/security.php` ist ein clientseitiger *Vorfilter* gegen
offensichtliche Prompt-Injection-Muster. Die primäre Absicherung liegt
serverseitig in System 2 (Security-Prompt mit Allowlist legitimer kommunaler
Begriffe, Temperature 0.0). Die Blockliste ist bewusst offen dokumentiert —
Security-by-Obscurity ist hier kein Schutzziel.

**Umfrage (`umfrage/`):** Zweistufige, anonyme Evaluations-Umfrage
(NPS, SUS, UMUX-Lite plus offene Fragen). Teil 1 und optionaler Teil 2 werden
über ein technisches Session-Token verknüpft; es werden keine Kontaktdaten
erhoben. Fragenkatalog in `umfrage/lib/questions.php`.

## Setup

1. **Dateien** nach `httpdocs/` des Webhostings legen.
2. **Datenbank:** geteiltes Schema einspielen (falls nicht bereits durch
   System 1 geschehen):
   ```bash
   mysql -u <user> -p <dbname> < ../db/schema.mysql.sql
   ```
3. **Konfiguration:**
   ```bash
   cp config_example.php config.php
   ```
   Pflichtwerte: `db_*` (geteilte MySQL-DB), `api_base` (URL der System-2-API)
   und `api_key` — identisch mit `KOMMRAG_API_KEY` in `System_2/web_api/.env`.
4. **Rechtstexte:** `pages/*.example.html` kopieren, Platzhalter ersetzen
   (Impressum, Datenschutz — instanzspezifisch, keine Rechtsberatung).
5. **Zugangscodes** über das Admin-Panel anlegen.

## Betriebshinweise

- `error_log` im Webroot regelmäßig prüfen und gegen Webzugriff sperren bzw.
  löschen — Standard-PHP-Fehlerlogs gehören nicht öffentlich.
- `chat_logs` und `survey_*` enthalten Nutzereingaben — bei Backups und
  Exporten entsprechend behandeln; niemals in ein öffentliches Repo dumpen.

## Lizenz

Code: EUPL-1.2 — siehe [LICENSE](../LICENSE) im Repository-Root.
Fonts: Ubuntu Font Licence 1.0 — siehe `assets/fonts/ubuntu/`.
