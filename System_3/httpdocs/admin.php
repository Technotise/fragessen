<?php
session_start();
?>
<!DOCTYPE html>
<html lang="de" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>FragEssen – Admin</title>
<link rel="stylesheet" href="fonts.css">
<style>
:root {
  --bg:          #0f1117;
  --bg-card:     #181b24;
  --bg-input:    #1e2230;
  --bg-hover:    #252a38;
  --border:      #2a2f40;
  --border-light:#353b50;
  --text:        #e2e4ea;
  --text-muted:  #8b90a0;
  --text-dim:    #5c6178;
  --accent:      #5b8af5;
  --accent-dim:  #3d6ad4;
  --green:       #3dbe78;
  --green-dim:   rgba(61,190,120,.12);
  --red:         #e05555;
  --red-dim:     rgba(224,85,85,.12);
  --yellow:      #e0a830;
  --yellow-dim:  rgba(224,168,48,.12);
  --radius:      8px;
  --radius-sm:   5px;
  --shadow:      0 2px 12px rgba(0,0,0,.25);
  --font:        'Ubuntu', -apple-system, BlinkMacSystemFont, sans-serif;
  --mono:        'Ubuntu Mono', 'Fira Code', monospace;
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
  font-family: var(--font);
  background: var(--bg);
  color: var(--text);
  line-height: 1.55;
  min-height: 100vh;
}

/* ─── Login ─────────────────────────────────── */

.login-wrap {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  padding: 2rem;
}

.login-card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 2.5rem;
  width: 100%;
  max-width: 380px;
  box-shadow: var(--shadow);
}

.login-card h1 {
  font-size: 1.25rem;
  font-weight: 600;
  margin-bottom: .3rem;
}

.login-card .login-sub {
  color: var(--text-muted);
  font-size: .88rem;
  margin-bottom: 1.6rem;
}

.login-card label {
  display: block;
  font-size: .82rem;
  color: var(--text-muted);
  margin-bottom: .3rem;
  font-weight: 500;
}

.login-card input {
  width: 100%;
  padding: .6rem .75rem;
  background: var(--bg-input);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  color: var(--text);
  font-family: var(--font);
  font-size: .92rem;
  margin-bottom: 1rem;
  transition: border-color .15s;
}

.login-card input:focus { outline: none; border-color: var(--accent); }

.login-error {
  color: var(--red);
  font-size: .85rem;
  margin-bottom: .8rem;
  display: none;
}

.login-error.visible { display: block; }

.btn-login {
  width: 100%;
  padding: .65rem;
  background: var(--accent);
  color: #fff;
  border: none;
  border-radius: var(--radius-sm);
  font-family: var(--font);
  font-size: .92rem;
  font-weight: 600;
  cursor: pointer;
  transition: background .15s;
}

.btn-login:hover { background: var(--accent-dim); }

/* ─── Shell ─────────────────────────────────── */

.shell { display: none; }
.shell.active { display: flex; min-height: 100vh; }

.shell-sidebar {
  width: 220px;
  background: var(--bg-card);
  border-right: 1px solid var(--border);
  padding: 1.4rem 0;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
}

.shell-brand {
  padding: 0 1.2rem 1.2rem;
  border-bottom: 1px solid var(--border);
  margin-bottom: .8rem;
}

.shell-brand h2 { font-size: 1rem; font-weight: 700; display: flex; align-items: center; gap: .4rem; }
.shell-brand span { font-size: .78rem; color: var(--text-muted); font-weight: 400; }

.nav-item {
  display: flex; align-items: center; gap: .6rem;
  padding: .55rem 1.2rem; color: var(--text-muted); font-size: .88rem;
  cursor: pointer; transition: all .12s; border: none; background: none;
  width: 100%; text-align: left; font-family: var(--font);
}

.nav-item:hover { color: var(--text); background: var(--bg-hover); }
.nav-item.active { color: var(--accent); background: rgba(91,138,245,.08); }
.nav-icon { font-size: 1.05rem; width: 1.4rem; text-align: center; }
.nav-spacer { flex: 1; }

.nav-item.nav-logout {
  border-top: 1px solid var(--border);
  margin-top: .4rem; padding-top: .8rem; color: var(--text-dim);
}

.shell-main { flex: 1; padding: 2rem; overflow-y: auto; max-height: 100vh; }

/* ─── Panels ────────────────────────────────── */

.panel { display: none; }
.panel.active { display: block; }
.panel-title { font-size: 1.3rem; font-weight: 700; margin-bottom: .3rem; }
.panel-sub { color: var(--text-muted); font-size: .88rem; margin-bottom: 1.5rem; }

/* ─── Stats ─────────────────────────────────── */

.stat-grid {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 1rem; margin-bottom: 1.5rem;
}

.stat-card {
  background: var(--bg-card); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 1.1rem 1.2rem;
}

.stat-card .stat-label {
  font-size: .78rem; color: var(--text-muted);
  text-transform: uppercase; letter-spacing: .04em; margin-bottom: .3rem;
}

.stat-card .stat-value { font-size: 1.6rem; font-weight: 700; font-family: var(--mono); }
.stat-card .stat-value.green { color: var(--green); }
.stat-card .stat-value.red { color: var(--red); }

.period-pills { display: flex; gap: .4rem; margin-bottom: 1.2rem; }

.period-pill {
  padding: .3rem .7rem; border: 1px solid var(--border); border-radius: 999px;
  background: transparent; color: var(--text-muted); font-family: var(--font);
  font-size: .82rem; cursor: pointer; transition: all .12s;
}

.period-pill:hover { border-color: var(--accent); color: var(--text); }
.period-pill.active { background: var(--accent); color: #fff; border-color: var(--accent); }

.chart-wrap {
  background: var(--bg-card); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 1.2rem; margin-bottom: 1.5rem;
}

.chart-title { font-size: .85rem; color: var(--text-muted); margin-bottom: .8rem; font-weight: 600; }

.bar-chart { display: flex; align-items: flex-end; gap: 3px; height: 120px; }

.bar-col {
  flex: 1; min-width: 4px; background: var(--accent); border-radius: 2px 2px 0 0;
  transition: height .3s ease; position: relative; cursor: default;
}

.bar-col:hover { background: #7ba3ff; }

.bar-col .bar-tip {
  display: none; position: absolute; bottom: calc(100% + 6px); left: 50%;
  transform: translateX(-50%); background: var(--bg-card); border: 1px solid var(--border);
  border-radius: var(--radius-sm); padding: .25rem .5rem; font-size: .72rem;
  white-space: nowrap; color: var(--text); z-index: 10;
}

.bar-col:hover .bar-tip { display: block; }

/* ─── Buttons & Tables ──────────────────────── */

.btn {
  padding: .5rem 1rem; border: none; border-radius: var(--radius-sm);
  font-family: var(--font); font-size: .85rem; font-weight: 600;
  cursor: pointer; transition: all .12s; display: inline-flex; align-items: center; gap: .35rem;
}

.btn-primary { background: var(--accent); color: #fff; }
.btn-primary:hover { background: var(--accent-dim); }
.btn-danger { background: var(--red-dim); color: var(--red); border: 1px solid rgba(224,85,85,.2); }
.btn-danger:hover { background: rgba(224,85,85,.2); }
.btn-ghost { background: transparent; color: var(--text-muted); border: 1px solid var(--border); }
.btn-ghost:hover { color: var(--text); border-color: var(--border-light); }
.btn-sm { padding: .3rem .6rem; font-size: .8rem; }

.table-wrap {
  background: var(--bg-card); border: 1px solid var(--border);
  border-radius: var(--radius); overflow-x: auto;
}

table { width: 100%; border-collapse: collapse; font-size: .85rem; }

thead th {
  text-align: left; padding: .7rem .8rem; background: var(--bg-hover);
  color: var(--text-muted); font-size: .78rem; text-transform: uppercase;
  letter-spacing: .04em; font-weight: 600; border-bottom: 1px solid var(--border);
}

tbody td { padding: .6rem .8rem; border-bottom: 1px solid var(--border); vertical-align: top; }
tbody tr:hover { background: rgba(255,255,255,.02); }
tbody tr:last-child td { border-bottom: none; }

.badge {
  display: inline-block; padding: .15rem .5rem; border-radius: 999px;
  font-size: .75rem; font-weight: 600;
}

.badge-green { background: var(--green-dim); color: var(--green); }
.badge-red { background: var(--red-dim); color: var(--red); }
.badge-yellow { background: var(--yellow-dim); color: var(--yellow); }
.badge-muted { background: rgba(140,144,160,.1); color: var(--text-muted); }

code {
  font-family: var(--mono); background: var(--bg-input);
  padding: .15rem .4rem; border-radius: 3px; font-size: .88em; letter-spacing: .03em;
}

.label-text {
  color: var(--text-muted); font-size: .82rem; max-width: 200px;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}

.action-btns { display: flex; gap: .4rem; }

.type-legend {
  display: flex; gap: 1.5rem; margin-bottom: 1rem;
  font-size: .78rem; color: var(--text-dim);
}

.type-badge {
  display: inline-block; padding: .12rem .45rem; border-radius: 3px;
  font-size: .72rem; font-weight: 600; letter-spacing: .03em; text-transform: uppercase;
}

.type-badge.session { background: rgba(91,138,245,.12); color: var(--accent); }
.type-badge.persistent { background: rgba(224,168,48,.12); color: var(--yellow); }

.usage-cell {
  font-family: var(--mono); font-size: .8rem; white-space: nowrap;
}

.usage-cell .u-s { color: var(--text-muted); }
.usage-cell .u-m { color: var(--yellow); }
.usage-cell .u-l { color: var(--accent); }

/* ─── Code-Verwaltung ───────────────────────── */

.code-actions {
  display: flex; gap: .8rem; align-items: flex-end;
  margin-bottom: 1.2rem; flex-wrap: wrap;
}

.code-actions .field label {
  font-size: .78rem; color: var(--text-muted); margin-bottom: .2rem; display: block;
}

.code-actions input, .code-actions select {
  padding: .5rem .65rem; background: var(--bg-input); border: 1px solid var(--border);
  border-radius: var(--radius-sm); color: var(--text); font-family: var(--font); font-size: .88rem;
}

.code-actions input:focus, .code-actions select:focus { outline: none; border-color: var(--accent); }

/* ─── Chat-Logs ─────────────────────────────── */

.logs-toolbar {
  display: flex; gap: .8rem; align-items: center;
  margin-bottom: 1rem; flex-wrap: wrap;
}

.logs-toolbar input, .logs-toolbar select {
  padding: .5rem .7rem; background: var(--bg-input); border: 1px solid var(--border);
  border-radius: var(--radius-sm); color: var(--text); font-family: var(--font); font-size: .88rem;
}

.logs-toolbar input { width: 260px; }
.logs-toolbar input:focus { outline: none; border-color: var(--accent); }

.pager { display: flex; gap: .4rem; align-items: center; margin-top: 1rem; justify-content: center; }

.pager button {
  padding: .35rem .7rem; background: var(--bg-card); border: 1px solid var(--border);
  border-radius: var(--radius-sm); color: var(--text-muted); font-family: var(--font);
  font-size: .82rem; cursor: pointer;
}

.pager button:hover { border-color: var(--accent); color: var(--text); }
.pager button:disabled { opacity: .3; cursor: default; }
.pager .pager-info { font-size: .82rem; color: var(--text-muted); }

.query-cell { max-width: 300px; word-break: break-word; }
.answer-preview { color: var(--text-muted); font-size: .82rem; max-width: 300px; word-break: break-word; }
.elapsed-cell { font-family: var(--mono); font-size: .82rem; color: var(--text-muted); }
.rating-cell .rating-up { color: var(--green); }
.rating-cell .rating-down { color: var(--red); }
.rating-cell .rating-none { color: var(--text-dim); }

/* ─── Modal ─────────────────────────────────── */

.modal-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,.6);
  z-index: 100; display: none; align-items: center; justify-content: center; padding: 2rem;
}

.modal-overlay.open { display: flex; }

.modal-content {
  background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius);
  width: 100%; max-width: 700px; max-height: 85vh; overflow-y: auto;
  padding: 1.8rem; box-shadow: 0 8px 40px rgba(0,0,0,.4);
}

.modal-content h3 { font-size: 1.1rem; margin-bottom: 1rem; }

.detail-grid {
  display: grid; grid-template-columns: 130px 1fr;
  gap: .4rem .8rem; font-size: .88rem; margin-bottom: 1rem;
}

.detail-grid .dl { color: var(--text-muted); font-weight: 500; }
.detail-grid .dv { word-break: break-word; }

.detail-answer {
  background: var(--bg-input); border: 1px solid var(--border); border-radius: var(--radius-sm);
  padding: 1rem 1.2rem; font-size: .88rem; word-break: break-word;
  max-height: 400px; overflow-y: auto; line-height: 1.7;
}

.detail-answer p { margin-bottom: .6rem; }
.detail-answer p:last-child { margin-bottom: 0; }

.detail-answer .tldr-label { color: var(--accent); }

.detail-answer .md-h2 { font-size: 1.05rem; font-weight: 700; margin: 1rem 0 .4rem; color: var(--text); }
.detail-answer .md-h3 { font-size: .95rem; font-weight: 700; margin: .8rem 0 .3rem; color: var(--text); }
.detail-answer .md-h4 { font-size: .9rem; font-weight: 600; margin: .6rem 0 .3rem; color: var(--text); }

.detail-answer .md-hr { border: none; border-top: 1px solid var(--border); margin: .8rem 0; }

.detail-answer .md-ul { margin: .4rem 0 .6rem 1.2rem; padding: 0; list-style: disc; }
.detail-answer .md-li { margin-bottom: .25rem; }
.detail-answer .md-li-nested { margin-left: 1rem; list-style: circle; }

.detail-answer .inline-source {
  font-size: .78rem; color: var(--text-dim); background: rgba(91,138,245,.08);
  padding: .1rem .35rem; border-radius: 3px;
}

.detail-answer code {
  font-family: var(--mono); background: rgba(255,255,255,.06);
  padding: .1rem .3rem; border-radius: 3px; font-size: .85em;
}

.detail-answer strong { color: var(--text); }

.detail-answer .md-table-wrap { overflow-x: auto; margin: .6rem 0; }

.detail-answer .md-table {
  width: 100%; border-collapse: collapse; font-size: .82rem;
}

.detail-answer .md-table th {
  text-align: left; padding: .4rem .6rem; background: var(--bg-hover);
  border-bottom: 1px solid var(--border); font-weight: 600; color: var(--text-muted);
}

.detail-answer .md-table td {
  padding: .35rem .6rem; border-bottom: 1px solid var(--border);
}

.modal-close {
  float: right; background: none; border: none; color: var(--text-muted);
  font-size: 1.3rem; cursor: pointer; padding: .2rem; line-height: 1;
}

.modal-close:hover { color: var(--text); }

.detail-params {
  background: var(--bg-hover); border: 1px solid var(--border); border-radius: var(--radius);
  padding: .8rem 1rem; margin-bottom: 1rem;
}

.detail-params-title {
  font-size: .75rem; text-transform: uppercase; letter-spacing: .04em;
  color: var(--text-dim); font-weight: 600; margin-bottom: .5rem;
}

.param-chips { display: flex; flex-wrap: wrap; gap: .4rem; }

.param-chip {
  display: inline-flex; align-items: center; gap: .3rem;
  background: var(--bg-card); border: 1px solid var(--border);
  border-radius: 999px; padding: .25rem .65rem;
  font-size: .8rem; color: var(--text);
}

.param-key {
  color: var(--text-muted); font-weight: 600; font-size: .72rem;
  text-transform: uppercase; letter-spacing: .03em;
}

/* ─── Responsive ────────────────────────────── */

@media (max-width: 768px) {
  .shell.active { flex-direction: column; }
  .shell-sidebar { width: 100%; flex-direction: row; overflow-x: auto; padding: .6rem; }
  .shell-brand { display: none; }
  .nav-item { padding: .5rem .8rem; white-space: nowrap; }
  .nav-spacer { display: none; }
  .shell-main { padding: 1rem; max-height: none; }
  .stat-grid { grid-template-columns: 1fr 1fr; }
  .code-actions { flex-direction: column; }
  .logs-toolbar { flex-direction: column; }
  .logs-toolbar input { width: 100%; }
}
</style>
</head>
<body>

<!-- ═══════════════ LOGIN ═══════════════ -->
<div class="login-wrap" id="login-screen">
  <div class="login-card">
    <h1>⚖ FragEssen Admin</h1>
    <p class="login-sub">Zugang nur für Administratoren</p>
    <label for="login-user">Benutzername</label>
    <input type="text" id="login-user" autocomplete="username" spellcheck="false">
    <label for="login-pass">Passwort</label>
    <input type="password" id="login-pass" autocomplete="current-password">
    <div class="login-error" id="login-error"></div>
    <button class="btn-login" id="btn-do-login">Anmelden</button>
  </div>
</div>

<!-- ═══════════════ APP SHELL ═══════════════ -->
<div class="shell" id="app-shell">
  <aside class="shell-sidebar">
    <div class="shell-brand">
      <h2>⚖ Admin</h2>
      <span>FragEssen</span>
    </div>
    <button class="nav-item active" data-panel="dashboard">
      <span class="nav-icon">📊</span> Dashboard
    </button>
    <button class="nav-item" data-panel="codes">
      <span class="nav-icon">🔑</span> Zugangscodes
    </button>
    <button class="nav-item" data-panel="logs">
      <span class="nav-icon">💬</span> Chat-Logs
    </button>
    <div class="nav-spacer"></div>
    <button class="nav-item nav-logout" id="btn-logout">
      <span class="nav-icon">↩</span> Abmelden
    </button>
  </aside>

  <main class="shell-main">

    <!-- ─── Dashboard ─────────────────────── -->
    <div class="panel active" id="panel-dashboard">
      <h2 class="panel-title">Dashboard</h2>
      <p class="panel-sub">Nutzungsübersicht von FragEssen</p>

      <div class="period-pills" id="period-pills">
        <button class="period-pill" data-period="24h">24h</button>
        <button class="period-pill active" data-period="7d">7 Tage</button>
        <button class="period-pill" data-period="30d">30 Tage</button>
        <button class="period-pill" data-period="all">Gesamt</button>
      </div>

      <div class="stat-grid" id="stat-grid">
        <div class="stat-card">
          <div class="stat-label">Anfragen</div>
          <div class="stat-value" id="stat-total">–</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Sessions</div>
          <div class="stat-value" id="stat-sessions">–</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Ø Antwortzeit</div>
          <div class="stat-value" id="stat-avg-time">–</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">👍 Positiv</div>
          <div class="stat-value green" id="stat-fb-pos">–</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">👎 Negativ</div>
          <div class="stat-value red" id="stat-fb-neg">–</div>
        </div>
      </div>

      <div class="chart-wrap">
        <div class="chart-title">Anfragen pro Tag</div>
        <div class="bar-chart" id="chart-daily"></div>
      </div>

      <div class="chart-wrap">
        <div class="chart-title">Top Gremien</div>
        <div id="gremien-list" style="font-size:.88rem;"></div>
      </div>
    </div>

    <!-- ─── Zugangscodes ──────────────────── -->
    <div class="panel" id="panel-codes">
      <h2 class="panel-title">Zugangscodes</h2>
      <p class="panel-sub">Codes erstellen und verwalten. Codes sind <strong>nicht</strong> mit Chat-Verläufen verknüpft.</p>

      <div class="code-actions">
        <div class="field">
          <label>Label (optional)</label>
          <input type="text" id="code-label" placeholder="z.B. Testperson A" style="width:200px;">
        </div>
        <div class="field">
          <label>Custom Code (optional)</label>
          <input type="text" id="code-custom" placeholder="XXXX-XXXX-XXXX" style="width:170px;">
        </div>
        <div class="field">
          <label>Max. Einlösungen <span style="color:var(--text-dim);font-weight:400;">(0 = ∞)</span></label>
          <input type="number" id="code-max-uses" value="1" min="0" max="99999" style="width:90px;">
        </div>
        <div class="field">
          <label>Typ</label>
          <select id="code-type" style="width:130px;">
            <option value="session">Session</option>
            <option value="persistent">Persistent</option>
          </select>
        </div>
        <div class="field">
          <label>Ablaufdatum <span style="color:var(--text-dim);font-weight:400;">(optional)</span></label>
          <input type="datetime-local" id="code-expires" style="width:200px;">
        </div>
        <button class="btn btn-primary" id="btn-create-code" style="align-self:flex-end;">+ Code erstellen</button>
      </div>

      <div class="type-legend">
        <span><strong>Session</strong> – gilt nur für aktuelle Browser-Sitzung, weg nach Tab-Schließen</span>
        <span><strong>Persistent</strong> – wird im LocalStorage gespeichert, überlebt Sitzungswechsel</span>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Code</th>
              <th>Label</th>
              <th>Typ</th>
              <th>Status</th>
              <th>Einlösungen</th>
              <th>Nutzung (S/M/L)</th>
              <th>Ablauf</th>
              <th>Aktionen</th>
            </tr>
          </thead>
          <tbody id="codes-tbody"></tbody>
        </table>
      </div>
    </div>

    <!-- ─── Chat-Logs ─────────────────────── -->
    <div class="panel" id="panel-logs">
      <h2 class="panel-title">Chat-Logs</h2>
      <p class="panel-sub">Alle Nutzeranfragen und Antworten (ohne Zuordnung zu Zugangscodes)</p>

      <div class="logs-toolbar">
        <input type="text" id="logs-search" placeholder="Suche in Fragen/Antworten…">
        <select id="logs-rating-filter">
          <option value="">Alle Bewertungen</option>
          <option value="1">👍 Positiv</option>
          <option value="-1">👎 Negativ</option>
          <option value="none">Ohne Bewertung</option>
        </select>
        <button class="btn btn-ghost" id="btn-logs-search">Suchen</button>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Datum</th>
              <th>Frage</th>
              <th>Antwort (Vorschau)</th>
              <th>Zeit</th>
              <th>Rating</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="logs-tbody"></tbody>
        </table>
      </div>

      <div class="pager" id="logs-pager"></div>
    </div>

  </main>
</div>

<!-- ═══════════════ DETAIL MODAL ═══════════════ -->
<div class="modal-overlay" id="detail-modal">
  <div class="modal-content">
    <button class="modal-close" id="modal-close-btn">&times;</button>
    <h3>Chat-Detail</h3>
    <div id="detail-body"></div>
  </div>
</div>

<script>
const API = 'admin_api.php';

async function api(action, data = {}) {
  const res = await fetch(API, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action, ...data }),
  });
  const json = await res.json();
  if (!res.ok) throw new Error(json.error || `HTTP ${res.status}`);
  return json;
}

function esc(str) {
  return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function fmtDate(str) {
  if (!str) return '–';
  const d = new Date(str);
  return d.toLocaleDateString('de-DE', { day:'2-digit', month:'2-digit', year:'numeric' })
    + ' ' + d.toLocaleTimeString('de-DE', { hour:'2-digit', minute:'2-digit' });
}

// ─── Auth ──────────────────────────────────────

async function checkAuth() {
  try {
    const data = await api('check_auth');
    if (data.authenticated) { showApp(); loadDashboard(); }
  } catch (_) {}
}

function showApp() {
  document.getElementById('login-screen').style.display = 'none';
  document.getElementById('app-shell').classList.add('active');
}

function showLogin() {
  document.getElementById('login-screen').style.display = '';
  document.getElementById('app-shell').classList.remove('active');
}

document.getElementById('btn-do-login').addEventListener('click', doLogin);
document.getElementById('login-pass').addEventListener('keydown', e => { if (e.key === 'Enter') doLogin(); });

async function doLogin() {
  const errEl = document.getElementById('login-error');
  errEl.classList.remove('visible');
  try {
    await api('login', {
      username: document.getElementById('login-user').value.trim(),
      password: document.getElementById('login-pass').value,
    });
    showApp();
    loadDashboard();
  } catch (err) {
    errEl.textContent = err.message;
    errEl.classList.add('visible');
  }
}

document.getElementById('btn-logout').addEventListener('click', async () => {
  await api('logout');
  showLogin();
});

// ─── Navigation ────────────────────────────────

document.querySelectorAll('.nav-item[data-panel]').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.nav-item[data-panel]').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
    document.getElementById('panel-' + btn.dataset.panel).classList.add('active');
    if (btn.dataset.panel === 'dashboard') loadDashboard();
    if (btn.dataset.panel === 'codes') loadCodes();
    if (btn.dataset.panel === 'logs') loadLogs();
  });
});

// ─── Dashboard ─────────────────────────────────

let currentPeriod = '7d';

document.getElementById('period-pills').addEventListener('click', e => {
  const pill = e.target.closest('.period-pill');
  if (!pill) return;
  document.querySelectorAll('.period-pill').forEach(p => p.classList.remove('active'));
  pill.classList.add('active');
  currentPeriod = pill.dataset.period;
  loadDashboard();
});

async function loadDashboard() {
  try {
    const data = await api('chat_stats', { period: currentPeriod });
    document.getElementById('stat-total').textContent = data.total.toLocaleString('de-DE');
    document.getElementById('stat-sessions').textContent = data.unique_sessions.toLocaleString('de-DE');
    document.getElementById('stat-avg-time').textContent = (data.avg_elapsed_ms / 1000).toFixed(1) + 's';
    document.getElementById('stat-fb-pos').textContent = data.feedback?.positive ?? 0;
    document.getElementById('stat-fb-neg').textContent = data.feedback?.negative ?? 0;

    const chartEl = document.getElementById('chart-daily');
    const perDay = data.per_day || [];
    if (!perDay.length) {
      chartEl.innerHTML = '<span style="color:var(--text-dim);font-size:.85rem;">Keine Daten</span>';
    } else {
      const max = Math.max(...perDay.map(d => d.cnt), 1);
      chartEl.innerHTML = perDay.map(d => {
        const h = Math.max(4, (d.cnt / max) * 110);
        return `<div class="bar-col" style="height:${h}px;"><div class="bar-tip">${d.day}: ${d.cnt}</div></div>`;
      }).join('');
    }

    const gremEl = document.getElementById('gremien-list');
    const gremien = data.top_gremien || [];
    if (!gremien.length) {
      gremEl.innerHTML = '<span style="color:var(--text-dim);">Keine Daten</span>';
    } else {
      gremEl.innerHTML = gremien.map(g =>
        `<div style="display:flex;justify-content:space-between;padding:.3rem 0;border-bottom:1px solid var(--border);">
          <span>${esc(g.gremium)}</span>
          <span style="font-family:var(--mono);color:var(--text-muted);">${g.cnt}</span>
        </div>`
      ).join('');
    }
  } catch (err) { console.error('Dashboard error:', err); }
}

// ─── Zugangscodes ──────────────────────────────

document.getElementById('btn-create-code').addEventListener('click', async () => {
  try {
    const expiresVal = document.getElementById('code-expires').value;
    await api('create_code', {
      label: document.getElementById('code-label').value.trim(),
      custom_code: document.getElementById('code-custom').value.trim(),
      max_uses: parseInt(document.getElementById('code-max-uses').value, 10),
      code_type: document.getElementById('code-type').value,
      expires_at: expiresVal ? new Date(expiresVal).toISOString().slice(0, 19).replace('T', ' ') : null,
    });
    document.getElementById('code-label').value = '';
    document.getElementById('code-custom').value = '';
    document.getElementById('code-max-uses').value = '1';
    document.getElementById('code-expires').value = '';
    document.getElementById('code-type').value = 'session';
    loadCodes();
  } catch (err) { alert('Fehler: ' + err.message); }
});

async function loadCodes() {
  try {
    const data = await api('list_codes');
    const tbody = document.getElementById('codes-tbody');
    if (!data.codes.length) {
      tbody.innerHTML = '<tr><td colspan="8" style="color:var(--text-dim);text-align:center;padding:2rem;">Noch keine Codes erstellt</td></tr>';
      return;
    }
    tbody.innerHTML = data.codes.map(c => {
      const isExpired = c.expires_at && new Date(c.expires_at) < new Date();
      const statusBadge = !c.is_active
        ? '<span class="badge badge-red">Deaktiviert</span>'
        : isExpired
          ? '<span class="badge badge-yellow">Abgelaufen</span>'
          : '<span class="badge badge-green">Aktiv</span>';

      const typeBadge = c.code_type === 'persistent'
        ? '<span class="type-badge persistent">Persistent</span>'
        : '<span class="type-badge session">Session</span>';

      const maxLabel = (c.max_uses == 0) ? '∞' : c.max_uses;

      const us = c.usage || {};
      const usageHtml = `<span class="usage-cell">`
        + `<span class="u-s" title="Small">${us.small || 0}</span> / `
        + `<span class="u-m" title="Medium">${us.medium || 0}</span> / `
        + `<span class="u-l" title="Large">${us.large || 0}</span>`
        + `</span>`;

      return `<tr>
        <td><code>${esc(c.code)}</code></td>
        <td><span class="label-text">${esc(c.label || '–')}</span></td>
        <td>${typeBadge}</td>
        <td>${statusBadge}</td>
        <td style="font-family:var(--mono);font-size:.85rem;">${c.used_count} / ${maxLabel}</td>
        <td>${usageHtml}</td>
        <td style="font-size:.82rem;color:var(--text-muted);">${c.expires_at ? fmtDate(c.expires_at) : '–'}</td>
        <td>
          <div class="action-btns">
            <button class="btn btn-ghost btn-sm" onclick="copyCode('${esc(c.code)}')">Kopieren</button>
            <button class="btn btn-ghost btn-sm" onclick="toggleCode(${c.id})">
              ${c.is_active == 1 ? 'Deakt.' : 'Aktiv.'}
            </button>
            <button class="btn btn-danger btn-sm" onclick="deleteCode(${c.id})">×</button>
          </div>
        </td>
      </tr>`;
    }).join('');
  } catch (err) { console.error('Codes error:', err); }
}

function copyCode(code) {
  navigator.clipboard.writeText(code).then(() => {
    // Kurzes visuelles Feedback wäre nett, aber reicht erstmal
  }).catch(() => {
    prompt('Code kopieren:', code);
  });
}

async function toggleCode(id) {
  try { await api('toggle_code', { id }); loadCodes(); }
  catch (err) { alert(err.message); }
}

async function deleteCode(id) {
  if (!confirm('Code wirklich löschen?')) return;
  try { await api('delete_code', { id }); loadCodes(); }
  catch (err) { alert(err.message); }
}

// ─── Chat-Logs ─────────────────────────────────

let logsPage = 1;

document.getElementById('btn-logs-search').addEventListener('click', () => { logsPage = 1; loadLogs(); });
document.getElementById('logs-search').addEventListener('keydown', e => {
  if (e.key === 'Enter') { logsPage = 1; loadLogs(); }
});

async function loadLogs() {
  try {
    const search = document.getElementById('logs-search').value.trim();
    const ratingRaw = document.getElementById('logs-rating-filter').value;
    let rating = null;
    if (ratingRaw === '1') rating = 1;
    else if (ratingRaw === '-1') rating = -1;
    else if (ratingRaw === 'none') rating = 'none';

    const data = await api('chat_logs', { page: logsPage, per_page: 25, search, rating_filter: rating });
    const tbody = document.getElementById('logs-tbody');

    if (!data.logs.length) {
      tbody.innerHTML = '<tr><td colspan="6" style="color:var(--text-dim);text-align:center;padding:2rem;">Keine Einträge gefunden</td></tr>';
      document.getElementById('logs-pager').innerHTML = '';
      return;
    }

    tbody.innerHTML = data.logs.map(l => `
      <tr>
        <td style="font-size:.8rem;color:var(--text-muted);white-space:nowrap;">${fmtDate(l.created_at)}</td>
        <td class="query-cell">${esc(l.query)}</td>
        <td class="answer-preview">${esc(l.answer_preview || l.clarify || '–')}</td>
        <td class="elapsed-cell">${l.elapsed_ms ? (l.elapsed_ms / 1000).toFixed(1) + 's' : '–'}</td>
        <td class="rating-cell">
          ${l.rating == 1 ? '<span class="rating-up">👍</span>'
            : l.rating == -1 ? '<span class="rating-down">👎</span>'
            : '<span class="rating-none">–</span>'}
        </td>
        <td><button class="btn btn-ghost btn-sm" onclick="showDetail(${l.id})">Details</button></td>
      </tr>
    `).join('');

    document.getElementById('logs-pager').innerHTML = `
      <button onclick="logsPage=1;loadLogs();" ${logsPage<=1?'disabled':''}>&laquo;</button>
      <button onclick="logsPage--;loadLogs();" ${logsPage<=1?'disabled':''}>&lsaquo;</button>
      <span class="pager-info">Seite ${data.page} / ${data.total_pages} (${data.total} Einträge)</span>
      <button onclick="logsPage++;loadLogs();" ${logsPage>=data.total_pages?'disabled':''}>&rsaquo;</button>
      <button onclick="logsPage=${data.total_pages};loadLogs();" ${logsPage>=data.total_pages?'disabled':''}>&raquo;</button>
    `;
  } catch (err) { console.error('Logs error:', err); }
}

// ─── Detail Modal ──────────────────────────────

const detailModal = document.getElementById('detail-modal');
document.getElementById('modal-close-btn').addEventListener('click', () => detailModal.classList.remove('open'));
detailModal.addEventListener('click', e => { if (e.target === detailModal) detailModal.classList.remove('open'); });

async function showDetail(id) {
  try {
    const data = await api('chat_detail', { id });
    const l = data.log;

    const qualityLabels = { small: 'Small', medium: 'Medium', large: 'Large 🔑' };
    const lengthLabels  = { short: 'Kurz', normal: 'Normal', detailed: 'Ausführlich' };

    document.getElementById('detail-body').innerHTML = `
      <div class="detail-grid">
        <span class="dl">Datum</span><span class="dv">${fmtDate(l.created_at)}</span>
        <span class="dl">Frage</span><span class="dv">${esc(l.query)}</span>
        <span class="dl">Suchanfrage</span><span class="dv">${esc(l.condensed_query || '–')}</span>
        <span class="dl">Bewertung</span><span class="dv">${l.rating==1?'👍 Positiv':l.rating==-1?'👎 Negativ':'–'}</span>
        ${l.feedback_comment ? `<span class="dl">Kommentar</span><span class="dv">${esc(l.feedback_comment)}</span>` : ''}
        <span class="dl">Antwortzeit</span><span class="dv">${l.elapsed_ms ? (l.elapsed_ms/1000).toFixed(1)+'s' : '–'}</span>
      </div>

      <div class="detail-params">
        <div class="detail-params-title">Gesendete Parameter</div>
        <div class="param-chips">
          <span class="param-chip"><span class="param-key">Gremium</span> ${esc(l.gremium_key || 'Alle')}</span>
          <span class="param-chip"><span class="param-key">Zeitraum</span> ${l.year_from || '–'} – ${l.year_to || '–'}</span>
          <span class="param-chip"><span class="param-key">top_k</span> ${l.top_k}</span>
          <span class="param-chip"><span class="param-key">Länge</span> ${lengthLabels[l.answer_length] || l.answer_length || '–'}</span>
          <span class="param-chip"><span class="param-key">Qualität</span> ${qualityLabels[l.quality] || l.quality || '–'}</span>
          <span class="param-chip"><span class="param-key">Session</span> <code style="font-size:.75rem;">${esc((l.session_id||'').substring(0,12))}…</code></span>
        </div>
      </div>

      ${l.clarify ? `<div style="margin-bottom:1rem;"><strong>Rückfrage:</strong><br>${esc(l.clarify)}</div>` : ''}
      <strong style="font-size:.85rem;color:var(--text-muted);">Antwort:</strong>
      <div class="detail-answer">${formatAnswer(l.answer)}</div>
    `;
    detailModal.classList.add('open');
  } catch (err) { alert('Fehler: ' + err.message); }
}

// ─── Markdown Rendering ────────────────────────

function isTableSepLine(line) {
  const s = line.trim();
  if (!s.includes('-')) return false;
  return /^(\|?\s*:?-{3,}:?\s*)+\|?\s*$/.test(s.replace(/\s+/g, ' '));
}

function splitRow(line) {
  let s = line.trim();
  if (s.startsWith('|')) s = s.slice(1);
  if (s.endsWith('|')) s = s.slice(0, -1);
  return s.split('|').map(c => c.trim());
}

function parseAlignments(sepLine) {
  return splitRow(sepLine).map(c => {
    const left = c.startsWith(':'), right = c.endsWith(':');
    if (left && right) return 'center';
    if (right) return 'right';
    if (left) return 'left';
    return '';
  });
}

function renderTables(text) {
  const lines = text.split('\n');
  const out = [];
  let i = 0;
  while (i < lines.length) {
    if (lines[i].includes('|') && i + 1 < lines.length && isTableSepLine(lines[i + 1])) {
      const header = splitRow(lines[i]);
      const aligns = parseAlignments(lines[i + 1]);
      i += 2;
      const rows = [];
      while (i < lines.length && lines[i].trim() !== '' && lines[i].includes('|')) {
        if (isTableSepLine(lines[i])) break;
        rows.push(splitRow(lines[i]));
        i++;
      }
      const ths = header.map((h, idx) => {
        const a = aligns[idx] ? ` style="text-align:${aligns[idx]}"` : '';
        return `<th${a}>${h}</th>`;
      }).join('');
      const tds = rows.map(r => {
        const cells = header.map((_, idx) => {
          const a = aligns[idx] ? ` style="text-align:${aligns[idx]}"` : '';
          return `<td${a}>${esc((r[idx] ?? '').trim())}</td>`;
        }).join('');
        return `<tr>${cells}</tr>`;
      }).join('');
      out.push(`<div class="md-table-wrap"><table class="md-table"><thead><tr>${ths}</tr></thead><tbody>${tds}</tbody></table></div>`);
      if (i < lines.length && lines[i].trim() === '') i++;
      continue;
    }
    out.push(lines[i]);
    i++;
  }
  return out.join('\n');
}

function formatAnswer(text) {
  if (!text) return '<em style="color:var(--text-dim);">(keine Antwort)</em>';

  text = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

  text = text.replace(/\*\*TL;DR:\*\*/g, '<strong class="tldr-label">TL;DR:</strong>');
  text = text.replace(/^---$/gm, '<hr class="md-hr">');
  text = text.replace(/^####\s+(.+)$/gm, '<h4 class="md-h4">$1</h4>');
  text = text.replace(/^###\s+(.+)$/gm, '<h3 class="md-h3">$1</h3>');
  text = text.replace(/^##\s+(.+)$/gm, '<h2 class="md-h2">$1</h2>');
  text = text.replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>');
  text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
  text = text.replace(/\*(.+?)\*/g, '<em>$1</em>');
  text = text.replace(/`(.+?)`/g, '<code>$1</code>');
  text = text.replace(/\(Protokoll:[^)]+\)/g, m => `<span class="inline-source">${m}</span>`);

  text = renderTables(text);

  text = text.replace(/^(?:    |\t)- (.+)$/gm, '<li class="md-li-nested">$1</li>');
  text = text.replace(/^- (.+)$/gm, '<li class="md-li">$1</li>');
  text = text.replace(/(<li class="md-li(?:-nested)?">[\s\S]*?<\/li>\n?)+/g, match =>
    `<ul class="md-ul">${match}</ul>`
  );
  text = text.replace(/<\/ul>\s*<ul class="md-ul">/g, '');

  text = text.split(/\n{2,}/).map(block => {
    block = block.trim();
    if (!block) return '';
    if (block.match(/^<(h[234]|ul|hr|li|div class="md-table-wrap"|table)/)) return block;
    block = block.replace(/\n/g, '<br>');
    return `<p>${block}</p>`;
  }).join('\n');

  return text;
}

// ─── Init ──────────────────────────────────────
checkAuth();
</script>
</body>
</html>
