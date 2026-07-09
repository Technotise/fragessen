<?php
session_start();

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/ratelimit.php';

$pdo = getPdo($config);

if (empty($_SESSION['kommrag_session_id'])) {
    $_SESSION['kommrag_session_id'] = bin2hex(random_bytes(16));
} else {
    // Seiten-Reload soll den Gesprächsverlauf zurücksetzen: History auf System 2
    // verwerfen, session_id (und damit den Unlock-Status) aber behalten.
    $ch = curl_init($config['api_base'] . '/session?' . http_build_query([
        'session_id' => $_SESSION['kommrag_session_id'],
    ]));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_HTTPHEADER     => ['X-API-Key: ' . $config['api_key']],
    ]);
    curl_exec($ch);
    curl_close($ch);
}
$session_id  = $_SESSION['kommrag_session_id'];
$is_unlocked = isSessionUnlocked($pdo, $session_id);

$gremien_ttl = $config['gremien_cache_ttl'] ?? 600;

$cache_valid = !empty($_SESSION['gremien_cache'])
    && isset($_SESSION['gremien_cache_ts'])
    && (time() - $_SESSION['gremien_cache_ts']) < $gremien_ttl;

if (!$cache_valid) {
    $ch = curl_init($config['api_base'] . '/gremien');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => ['X-API-Key: ' . $config['api_key']],
    ]);
    $gr_raw = curl_exec($ch);
    curl_close($ch);

    $decoded = $gr_raw ? json_decode($gr_raw, true) : null;
    if (is_array($decoded)) {
        $_SESSION['gremien_cache']    = $decoded;
        $_SESSION['gremien_cache_ts'] = time();
    }
    // bei Fehler: alten Cache behalten, kein Überschreiben mit []
}
$gremien = $_SESSION['gremien_cache'] ?? [];
?>
<!DOCTYPE html>
<html lang="de" data-theme="auto">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>FragEssen – Kommunale Ratsprotokolle durchsuchen</title>

<link rel="stylesheet" href="fonts.css">
<link rel="stylesheet" href="style.css">

<style>
.stream-status {
  opacity: .78;
  margin-bottom: .5rem;
  display: flex;
  align-items: center;
  gap: .35rem;
}

.stream-status-dots {
  display: inline-flex;
  align-items: center;
  min-width: 1.6em;
  letter-spacing: 0.08em;
}

.stream-status-dots span {
  opacity: .2;
  animation: kommragBlink 1.2s infinite ease-in-out;
}

.stream-status-dots span:nth-child(2) {
  animation-delay: .2s;
}

.stream-status-dots span:nth-child(3) {
  animation-delay: .4s;
}

@keyframes kommragBlink {
  0%, 80%, 100% {
    opacity: .2;
    transform: translateY(0);
  }
  40% {
    opacity: 1;
    transform: translateY(-1px);
  }
}

.stream-answer.is-live {
  white-space: pre-wrap;
  word-break: break-word;
}

.meta-row {
  display: flex;
  gap: .6rem;
  align-items: center;
  flex-wrap: wrap;
}

.message-pending .message-bubble {
  position: relative;
}

.pending-jump {
  margin-top: .65rem;
}

.pending-jump .btn-jump {
  border: 1px solid rgba(255,255,255,.14);
  background: transparent;
  color: inherit;
  border-radius: 999px;
  padding: .35rem .7rem;
  cursor: pointer;
  font-size: .9rem;
  opacity: .82;
}

.pending-jump .btn-jump:hover {
  opacity: 1;
}

.message-assistant.is-ready {
  animation: fadeInAnswer .22s ease-out;
}

@keyframes fadeInAnswer {
  from {
    opacity: .45;
    transform: translateY(4px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

/* Legend icon colors (matching style.css: segment=green, attendance=blue, chunk=muted) */
.source-icon-segment { color: var(--green, #1BBE6F); }
.source-icon-chunk   { color: var(--text-muted); }
.source-icon-attendance { color: var(--blue, #82D0F4); }

.meta-condensed-wrap {
  margin-top: .4rem;
}
.meta-condensed-toggle {
  cursor: pointer;
  font-size: .82rem;
  opacity: .65;
  user-select: none;
}
.meta-condensed-toggle:hover { opacity: .9; }
.meta-condensed-toggle::marker { font-size: .7em; }
.meta-condensed-content {
  font-size: .82rem;
  opacity: .7;
  margin-top: .15rem;
}

.topic-change-hint {
  display: flex;
  align-items: center;
  gap: .4rem;
  padding: .45rem .75rem;
  margin: .3rem 0 .5rem;
  font-size: .84rem;
  opacity: .72;
  background: rgba(128,128,128,.08);
  border-radius: .5rem;
  border-left: 3px solid rgba(128,128,128,.25);
}
.topic-change-hint .btn-new-chat {
  background: none;
  border: 1px solid rgba(128,128,128,.3);
  border-radius: 999px;
  padding: .2rem .55rem;
  cursor: pointer;
  color: inherit;
  font-size: .82rem;
  white-space: nowrap;
}
.topic-change-hint .btn-new-chat:hover { opacity: .8; }

.filter-persist-row {
  margin-top: .8rem;
  padding-top: .6rem;
  border-top: 1px solid rgba(128,128,128,.15);
}
.filter-persist-toggle {
  display: flex;
  align-items: center;
  gap: .45rem;
  cursor: pointer;
  font-size: .84rem;
  opacity: .75;
  user-select: none;
}
.filter-persist-toggle:hover { opacity: 1; }
.filter-persist-toggle input[type="checkbox"] {
  accent-color: var(--green, #1BBE6F);
  width: 1rem;
  height: 1rem;
  cursor: pointer;
}

/* Footer links */
.footer-links {
  display: flex;
  justify-content: center;
  gap: 1.2rem;
  padding: .4rem 0 .2rem;
  font-size: .78rem;
  opacity: .5;
}
.footer-links a {
  color: inherit;
  text-decoration: none;
}
.footer-links a:hover {
  opacity: .8;
  text-decoration: underline;
}

/* Modal pages (Impressum, Datenschutz, Filter-Hilfe) */
.page-modal {
  position: fixed;
  inset: 0;
  z-index: 200;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0,0,0,.5);
}
.page-modal[hidden] { display: none; }
.page-modal-card {
  background: var(--bg-card, #fff);
  color: var(--text, #222);
  border-radius: .75rem;
  max-width: 640px;
  width: 92vw;
  max-height: 85vh;
  overflow-y: auto;
  padding: 1.5rem 1.8rem;
  box-shadow: 0 8px 32px rgba(0,0,0,.25);
}
[data-theme="dark"] .page-modal-card,
.page-modal-card {
  background: var(--bg-card, #fff);
  color: var(--text, #222);
}
.page-modal-card h2 {
  margin: 0 0 .8rem;
  font-size: 1.2rem;
}
.page-modal-card h3 {
  margin: 1rem 0 .4rem;
  font-size: 1rem;
}
.page-modal-card p,
.page-modal-card ul {
  font-size: .9rem;
  line-height: 1.55;
  margin: .4rem 0;
}
.page-modal-card ul {
  padding-left: 1.2rem;
}
.page-modal-close {
  display: block;
  margin: 1rem auto 0;
  padding: .4rem 1.4rem;
  border: 1px solid rgba(128,128,128,.3);
  background: transparent;
  color: inherit;
  border-radius: 999px;
  cursor: pointer;
  font-size: .9rem;
}
.page-modal-close:hover { opacity: .7; }
</style>
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <a href="./" class="brand" style="text-decoration:none;color:inherit">
      <span class="brand-icon">⚖</span>
      <span class="brand-name">FragEssen</span>
      <span class="brand-sub">Kommunale Sitzungsprotokolle</span>
    </a>
    <nav class="topbar-actions">
      <button class="btn-icon" id="btn-theme" title="Design wechseln" aria-label="Hell/Dunkel wechseln">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="5"/>
          <line x1="12" y1="1" x2="12" y2="3"/>
          <line x1="12" y1="21" x2="12" y2="23"/>
          <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
          <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
          <line x1="1" y1="12" x2="3" y2="12"/>
          <line x1="21" y1="12" x2="23" y2="12"/>
          <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
          <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
        </svg>
      </button>
      <button class="btn-unlock <?= $is_unlocked ? 'is-unlocked' : '' ?>" id="btn-unlock">
        <?= $is_unlocked ? '🔓 Freigeschaltet' : '🔑 Zugangscode' ?>
      </button>
    </nav>
  </div>
</header>

<div class="layout">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-section">
      <h3 class="sidebar-title">Filter</h3>

      <label class="filter-label">Gremium</label>
      <select class="filter-select" id="filter-gremium">
        <option value="">Alle Gremien</option>
        <?php foreach ($gremien as $g): ?>
        <option value="<?= htmlspecialchars($g['key']) ?>">
          <?= htmlspecialchars($g['name']) ?> (<?= (int)$g['doc_count'] ?>)
        </option>
        <?php endforeach; ?>
      </select>
      <p class="filter-hint">Für präzise Antworten das passende Gremium wählen. „Alle Gremien" mischt die Ausschüsse und kann die Trefferqualität senken.</p>

      <label class="filter-label">Zeitraum</label>
      <div class="filter-row">
        <select class="filter-select" id="filter-year-from">
          <option value="">Von</option>
          <?php for ($y = 2004; $y <= date('Y'); $y++): ?>
          <option value="<?= $y ?>"><?= $y ?></option>
          <?php endfor; ?>
        </select>
        <select class="filter-select" id="filter-year-to">
          <option value="">Bis</option>
          <?php for ($y = date('Y'); $y >= 2004; $y--): ?>
          <option value="<?= $y ?>"><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </div>

      <label class="filter-label">Antwortlänge</label>
      <div class="filter-pills">
        <button class="pill" data-value="short">Kurz</button>
        <button class="pill is-active" data-value="normal">Normal</button>
        <button class="pill" data-value="detailed">Ausführlich</button>
      </div>

      <label class="filter-label">Suchmodus</label>
      <div class="filter-pills" id="search-mode-pills">
        <button class="pill is-active" data-search-mode="relevant" title="Standard: Relevanz + leichter Aktualitäts-Boost">Relevant</button>
        <button class="pill" data-search-mode="recent" title="Neuere Protokolle werden stärker bevorzugt">Neuste</button>
        <button class="pill" data-search-mode="breadth" title="Mehr Quellen aus unterschiedlichen Dokumenten">Breit</button>
      </div>

      <label class="filter-label">Qualität</label>
      <div class="filter-pills" id="quality-pills">
        <button class="pill is-active" data-value="small">Small</button>
        <button class="pill" data-value="medium">Medium</button>
        <?php if ($is_unlocked): ?>
        <button class="pill" data-value="large">Large 🔑</button>
        <?php endif; ?>
      </div>

      <label class="filter-label">Anzahl Quellen (top_k)</label>
      <div class="topk-row">
        <input type="range" id="filter-topk" min="3" max="20" value="15" step="1">
        <span class="topk-val" id="topk-display">15</span>
      </div>

      <div class="filter-persist-row">
        <label class="filter-persist-toggle">
          <input type="checkbox" id="filter-persist-check">
          <span class="filter-persist-label">Filter merken</span>
        </label>
      </div>
    </div>

    <div class="sidebar-section sidebar-info">
      <p class="info-text">
        Fragen und Antworten werden zur Qualitätssicherung gespeichert.<br>
        <strong>Bitte keine persönlichen Daten eingeben.</strong>
      </p>
      <p class="info-text muted">
        <?= $is_unlocked ? '✓ Unbegrenzte Anfragen aktiv' : "Limit: {$config['rate_limit_requests']} Anfragen/Stunde" ?>
      </p>
    </div>
  </aside>

  <main class="chat-area" id="chat-area">
    <div class="empty-state" id="empty-state">
      <div class="empty-icon">📜</div>
      <h2 class="empty-title">Was möchtest du wissen?</h2>
      <p class="empty-sub">Stelle eine Frage zu den Sitzungsniederschriften ausgewählter Gremien der Stadt Essen.</p>
      <div class="example-queries">
        <button class="example-btn" data-gremium="bv_iv_essen" data-query="Was wurde zur Radwegplanung in den letzten Jahren beschlossen?">Radwegplanung</button>
        <button class="example-btn" data-gremium="bv_iv_essen" data-query="Wie hat sich die Parkplatzsituation an der Pollstraße entwickelt?">Pollstraße Parkplätze</button>
        <button class="example-btn" data-gremium="arso_essen" data-query="Welche Themen wurden im Ausschuss für Recht, öffentliche Sicherheit und Ordnung zuletzt behandelt?">Ausschuss Recht &amp; Ordnung</button>
        <button class="example-btn" data-gremium="bv_iv_essen" data-query="Welche Beschlüsse gab es zur Ardelshütte?">Ardelshütte</button>
      </div>

      <?php if (!empty($gremien)): ?>
      <details class="gremien-status">
        <summary>Abgedeckte Gremien (<?= count($gremien) ?>)</summary>
        <ul class="gremien-status-list">
          <?php foreach ($gremien as $g): ?>
          <li>
            <span class="gremien-status-name"><?= htmlspecialchars($g['name']) ?></span>
            <span class="gremien-status-meta">
              <?= (int)$g['doc_count'] ?> Dok.<?php if (!empty($g['last_date'])): ?> · Stand <?= htmlspecialchars($g['last_date']) ?><?php endif; ?>
            </span>
          </li>
          <?php endforeach; ?>
        </ul>
      </details>
      <?php endif; ?>
    </div>

    <div class="messages" id="messages"></div>
  </main>
</div>

<div class="input-bar">
  <div class="input-bar-inner">
    <button class="btn-sidebar-toggle" id="btn-sidebar" title="Filter ein-/ausblenden">⚙</button>
    <div class="input-wrap">
      <textarea
        id="chat-input"
        class="chat-input"
        placeholder="Frage zu den Sitzungsprotokollen…"
        rows="1"
        maxlength="1000"
      ></textarea>
      <button class="btn-send" id="btn-send" title="Senden">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <line x1="22" y1="2" x2="11" y2="13"/>
          <polygon points="22 2 15 22 11 13 2 9 22 2"/>
        </svg>
      </button>
    </div>
    <button class="btn-reset" id="btn-reset" title="Gespräch zurücksetzen">↺</button>
  </div>
  <div class="footer-links">
    <a href="/umfrage/?src=banner">Umfrage zur Masterarbeit</a>
    <a href="#" id="link-impressum">Impressum</a>
    <a href="#" id="link-datenschutz">Datenschutz</a>
    <a href="#" id="link-filter-help">Filter-Hilfe</a>
  </div>
</div>

<!-- Impressum -->
<div class="page-modal" id="modal-impressum" hidden>
  <div class="page-modal-card">
    <?php include __DIR__ . '/pages/impressum.html'; ?>
    <button class="page-modal-close" onclick="this.closest('.page-modal').hidden=true">Schließen</button>
  </div>
</div>

<!-- Datenschutz -->
<div class="page-modal" id="modal-datenschutz" hidden>
  <div class="page-modal-card">
    <?php include __DIR__ . '/pages/datenschutz_fragessen.html'; ?>
    <button class="page-modal-close" onclick="this.closest('.page-modal').hidden=true">Schließen</button>
  </div>
</div>

<!-- Filter-Hilfe -->
<div class="page-modal" id="modal-filter-help" hidden>
  <div class="page-modal-card">
    <?php include __DIR__ . '/pages/filter-hilfe.html'; ?>
    <button class="page-modal-close" onclick="this.closest('.page-modal').hidden=true">Schließen</button>
  </div>
</div>

<div class="modal" id="modal-unlock" hidden>
  <div class="modal-backdrop" id="modal-backdrop"></div>
  <div class="modal-card">
    <h2 class="modal-title">🔑 Zugangscode eingeben</h2>
    <p class="modal-sub">Mit einem gültigen Code erhältst du unbegrenzte Anfragen.</p>
    <input type="text" class="modal-input" id="unlock-code" placeholder="XXXX-XXXX-XXXX" autocomplete="off" spellcheck="false">
    <div class="modal-error" id="unlock-error" hidden></div>
    <div class="modal-actions">
      <button class="btn-modal-cancel" id="btn-modal-cancel">Abbrechen</button>
      <button class="btn-modal-ok" id="btn-modal-ok">Einlösen</button>
    </div>
  </div>
</div>

<script>
const STATE = {
  answerLength: 'normal',
  quality: 'small',
  searchMode: 'relevant',
  loading: false,
  persistentCode: localStorage.getItem('kommrag_access_code') || null,
  messageCount: 0,
};

(function initTheme() {
  const saved = localStorage.getItem('kommrag_theme') || 'auto';
  applyTheme(saved);
})();

function applyTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
  localStorage.setItem('kommrag_theme', theme);
}

document.getElementById('btn-theme').addEventListener('click', () => {
  const cur = document.documentElement.getAttribute('data-theme') || 'auto';
  const next = cur === 'light' ? 'dark' : 'light';
  applyTheme(next);
});

// Footer links → Modals
document.getElementById('link-impressum').addEventListener('click', e => {
  e.preventDefault();
  document.getElementById('modal-impressum').hidden = false;
});
document.getElementById('link-datenschutz').addEventListener('click', e => {
  e.preventDefault();
  document.getElementById('modal-datenschutz').hidden = false;
});
document.getElementById('link-filter-help').addEventListener('click', e => {
  e.preventDefault();
  document.getElementById('modal-filter-help').hidden = false;
});
// Close page modals on backdrop click
document.querySelectorAll('.page-modal').forEach(modal => {
  modal.addEventListener('click', e => {
    if (e.target === modal) modal.hidden = true;
  });
});

const sidebar = document.getElementById('sidebar');
const isMobile = () => window.innerWidth <= 768;

document.getElementById('btn-sidebar').addEventListener('click', () => {
  if (isMobile()) {
    sidebar.classList.toggle('is-open');
    sidebar.classList.remove('is-hidden');
  } else {
    sidebar.classList.toggle('is-hidden');
    sidebar.classList.remove('is-open');
  }
});

document.querySelectorAll('.filter-pills .pill[data-value]').forEach(btn => {
  btn.addEventListener('click', () => {
    btn.closest('.filter-pills').querySelectorAll('.pill').forEach(p => p.classList.remove('is-active'));
    btn.classList.add('is-active');
    if (btn.closest('#quality-pills')) {
      STATE.quality = btn.dataset.value;
    } else {
      STATE.answerLength = btn.dataset.value;
    }
    saveFiltersIfEnabled();
  });
});

document.querySelectorAll('#search-mode-pills .pill[data-search-mode]').forEach(btn => {
  btn.addEventListener('click', () => {
    btn.closest('.filter-pills').querySelectorAll('.pill').forEach(p => p.classList.remove('is-active'));
    btn.classList.add('is-active');
    STATE.searchMode = btn.dataset.searchMode;
    saveFiltersIfEnabled();
  });
});

const topkSlider  = document.getElementById('filter-topk');
const topkDisplay = document.getElementById('topk-display');
topkSlider.addEventListener('input', () => {
  topkDisplay.textContent = topkSlider.value;
  saveFiltersIfEnabled();
});

// ─── Filter-Persistenz (LocalStorage) ───────────────────
const FILTER_STORAGE_KEY = 'kommrag_filters';
const FILTER_PERSIST_KEY = 'kommrag_filters_persist';
const filterPersistCheck = document.getElementById('filter-persist-check');

function saveFiltersIfEnabled() {
  if (!filterPersistCheck.checked) return;
  saveFilters();
}

function saveFilters() {
  const filters = {
    gremium:      document.getElementById('filter-gremium').value,
    year_from:    document.getElementById('filter-year-from').value,
    year_to:      document.getElementById('filter-year-to').value,
    answerLength: STATE.answerLength,
    quality:      STATE.quality,
    searchMode:   STATE.searchMode,
    topk:         topkSlider.value,
  };
  localStorage.setItem(FILTER_STORAGE_KEY, JSON.stringify(filters));
}

function restoreFilters() {
  const raw = localStorage.getItem(FILTER_STORAGE_KEY);
  if (!raw) return;
  try {
    const f = JSON.parse(raw);

    // Gremium
    if (f.gremium != null) {
      const sel = document.getElementById('filter-gremium');
      if (sel.querySelector(`option[value="${f.gremium}"]`)) {
        sel.value = f.gremium;
      }
    }

    // Zeitraum
    if (f.year_from) document.getElementById('filter-year-from').value = f.year_from;
    if (f.year_to) document.getElementById('filter-year-to').value = f.year_to;

    // Antwortlänge
    if (f.answerLength) {
      const pills = document.querySelectorAll('.filter-pills:not(#quality-pills):not(#search-mode-pills) .pill');
      pills.forEach(p => {
        p.classList.toggle('is-active', p.dataset.value === f.answerLength);
      });
      STATE.answerLength = f.answerLength;
    }

    // Qualität
    if (f.quality) {
      const qPills = document.querySelectorAll('#quality-pills .pill');
      const target = document.querySelector(`#quality-pills [data-value="${f.quality}"]`);
      if (target) {
        qPills.forEach(p => p.classList.remove('is-active'));
        target.classList.add('is-active');
        STATE.quality = f.quality;
      }
    }

    // Suchmodus
    if (f.searchMode) {
      const sPills = document.querySelectorAll('#search-mode-pills .pill');
      const target = document.querySelector(`#search-mode-pills [data-search-mode="${f.searchMode}"]`);
      if (target) {
        sPills.forEach(p => p.classList.remove('is-active'));
        target.classList.add('is-active');
        STATE.searchMode = f.searchMode;
      }
    }

    // top_k
    if (f.topk) {
      topkSlider.value = f.topk;
      topkDisplay.textContent = f.topk;
    }
  } catch (_) {}
}

filterPersistCheck.addEventListener('change', () => {
  if (filterPersistCheck.checked) {
    localStorage.setItem(FILTER_PERSIST_KEY, '1');
    saveFilters();
  } else {
    localStorage.removeItem(FILTER_PERSIST_KEY);
    localStorage.removeItem(FILTER_STORAGE_KEY);
  }
});

// Beim Laden: Toggle-Status + Filter wiederherstellen
if (localStorage.getItem(FILTER_PERSIST_KEY) === '1') {
  filterPersistCheck.checked = true;
  restoreFilters();
}

// Dropdowns auch bei Änderung speichern
document.getElementById('filter-gremium').addEventListener('change', saveFiltersIfEnabled);
document.getElementById('filter-year-from').addEventListener('change', saveFiltersIfEnabled);
document.getElementById('filter-year-to').addEventListener('change', saveFiltersIfEnabled);

document.querySelectorAll('.example-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const g = document.getElementById('filter-gremium');
    if (btn.dataset.gremium && [...g.options].some(o => o.value === btn.dataset.gremium)) {
      g.value = btn.dataset.gremium;
      saveFiltersIfEnabled();
    }
    document.getElementById('chat-input').value = btn.dataset.query;
    sendMessage();
  });
});

const chatInput = document.getElementById('chat-input');

chatInput.addEventListener('input', () => {
  chatInput.style.height = 'auto';
  chatInput.style.height = Math.min(chatInput.scrollHeight, 160) + 'px';
});

chatInput.addEventListener('keydown', e => {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    sendMessage();
  }
});

document.getElementById('btn-send').addEventListener('click', sendMessage);

document.getElementById('btn-reset').addEventListener('click', () => {
  fetch('clear_session.php', { method: 'POST' });
  document.getElementById('messages').innerHTML = '';
  document.getElementById('empty-state').style.display = '';
  STATE.messageCount = 0;
});

function getRequestPayload(query) {
  const payload = {
    query,
    top_k:         parseInt(topkSlider.value, 10),
    gremium_key:   document.getElementById('filter-gremium').value || null,
    year_from:     parseInt(document.getElementById('filter-year-from').value, 10) || null,
    year_to:       parseInt(document.getElementById('filter-year-to').value, 10) || null,
    answer_length: STATE.answerLength,
    quality:       STATE.quality,
    search_mode:   STATE.searchMode,
  };
  // Persistent-Code mitsenden für Usage-Tracking + Freischaltung
  if (STATE.persistentCode) {
    payload.access_code = STATE.persistentCode;
  }
  return payload;
}

function appendMessage(role, text) {
  const messages = document.getElementById('messages');
  const el = document.createElement('div');
  el.className = `message message-${role}`;
  el.innerHTML = `<div class="message-bubble">${escHtml(text)}</div>`;
  messages.appendChild(el);

  if (role === 'user') {
    scrollToBottom();
  }

  return el;
}

function statusHtml(text) {
  return `
    <span class="stream-status-text">${escHtml(text || '')}</span>
    <span class="stream-status-dots" aria-hidden="true">
      <span>.</span><span>.</span><span>.</span>
    </span>
  `;
}

function scrollToBottom() {
  const area = document.getElementById('chat-area');
  area.scrollTop = area.scrollHeight;
}

function scrollMessageIntoView(el, behavior = 'smooth') {
  if (!el) return;
  el.scrollIntoView({ behavior, block: 'start' });
}

function startPhaseRotator(setStatus) {
  const phases = [
    'Frage wird übermittelt',
    'Frage wird eingeordnet',
    'Passende Dokumente werden gesucht',
    'Treffer werden ausgewertet',
    'Antwort wird erstellt',
    'Quellen werden aufbereitet'
  ];

  let idx = 0;
  setStatus(phases[idx]);

  const timer = setInterval(() => {
    idx = Math.min(idx + 1, phases.length - 1);
    setStatus(phases[idx]);
  }, 2200);

  return {
    stop(finalText = null) {
      clearInterval(timer);
      if (finalText) setStatus(finalText);
    }
  };
}

function appendPendingAnswer() {
  const messages = document.getElementById('messages');
  const el = document.createElement('div');
  el.className = 'message message-assistant message-pending';
  el.innerHTML = `
    <div class="message-bubble">
      <div class="stream-status">${statusHtml('Frage wird übermittelt')}</div>
      <div class="stream-answer"></div>
      <div class="stream-sources"></div>
      <div class="stream-feedback"></div>
      <div class="message-meta"></div>
      <div class="pending-jump" hidden>
        <button class="btn-jump" type="button">Zum Anfang der Antwort springen</button>
      </div>
    </div>
  `;
  messages.appendChild(el);

  const statusEl   = el.querySelector('.stream-status');
  const answerEl   = el.querySelector('.stream-answer');
  const sourcesEl  = el.querySelector('.stream-sources');
  const feedbackEl = el.querySelector('.stream-feedback');
  const metaEl     = el.querySelector('.message-meta');
  const jumpWrap   = el.querySelector('.pending-jump');
  const jumpBtn    = el.querySelector('.btn-jump');

  jumpBtn.addEventListener('click', () => {
    scrollMessageIntoView(el, 'smooth');
  });

  return {
    el,
    setStatus(text) {
      statusEl.innerHTML = statusHtml(text || 'Arbeite');
    },
    finalize(result, originalQuery) {
      statusEl.remove();

      answerEl.innerHTML = formatAnswer(result.answer || '', buildSourceLinkMap(result.sources));
      el.classList.remove('message-pending');
      el.classList.add('is-ready');

      const sources = Array.isArray(result.sources) ? result.sources : [];
      if (sources.length) {
        sourcesEl.innerHTML = renderSources(sources);
      }

      if (result.log_id) {
        feedbackEl.innerHTML = `
          <div class="feedback-row" data-log="${result.log_id}">
            <button class="feedback-btn" data-rating="1" title="Hilfreich">👍</button>
            <button class="feedback-btn" data-rating="-1" title="Nicht hilfreich">👎</button>
          </div>
        `;
        feedbackEl.querySelectorAll('.feedback-btn').forEach(btn => {
          btn.addEventListener('click', () => sendFeedback(result.log_id, parseInt(btn.dataset.rating, 10), btn));
        });
      }

      const condensed = result.condensed_query || null;
      const elapsed = result.elapsed_ms || null;

      const condensedNote = condensed && condensed !== originalQuery
        ? `<details class="meta-condensed-wrap"><summary class="meta-condensed-toggle">Zusammengefasste Suchanfrage</summary><div class="meta-condensed-content">${escHtml(condensed)}</div></details>`
        : '';
      const timeNote = `<span class="meta-time">${elapsed ? (elapsed / 1000).toFixed(1) + 's' : ''}</span>`;

      metaEl.innerHTML = `<div class="meta-row">${condensedNote}${timeNote}</div>`;

      // Show topic-change hint from the 2nd message onwards
      STATE.messageCount++;
      if (STATE.messageCount >= 2) {
        const hintEl = document.createElement('div');
        hintEl.className = 'topic-change-hint';
        hintEl.innerHTML = '💡 Anderes Thema? Für bessere Ergebnisse: <button class="btn-new-chat" onclick="document.getElementById(\'btn-reset\').click()">Neues Gespräch starten</button>';
        metaEl.appendChild(hintEl);
      }

      jumpWrap.hidden = false;
    },
    clarify(text) {
      statusEl.remove();
      answerEl.innerHTML = `⚠️ ${escHtml(text || 'Bitte präzisieren.')}<div class="topic-change-hint">💡 Möchtest du ein anderes Thema besprechen? <button class="btn-new-chat" onclick="document.getElementById('btn-reset').click()">Neues Gespräch</button></div>`;
      jumpWrap.hidden = false;
    },
    fail(text, isRateLimit = false) {
      statusEl.remove();
      const extra = isRateLimit
        ? ` <button class="btn-unlock-inline" onclick="document.getElementById('btn-unlock').click()">Zugangscode eingeben</button>`
        : '';
      answerEl.innerHTML = `⚠ ${escHtml(text || 'Fehler bei der Verarbeitung.')}${extra}`;
      jumpWrap.hidden = false;
    },
    remove() {
      el.remove();
    }
  };
}

async function sendMessage() {
  const query = chatInput.value.trim();
  if (!query || STATE.loading) return;

  document.getElementById('empty-state').style.display = 'none';
  chatInput.value = '';
  chatInput.style.height = 'auto';

  const userMsgEl = appendMessage('user', query);

  STATE.loading = true;
  document.getElementById('btn-send').disabled = true;

  const pendingState = appendPendingAnswer();
  const rotator = startPhaseRotator(pendingState.setStatus);

  requestAnimationFrame(() => {
    scrollMessageIntoView(userMsgEl, 'smooth');
  });

  try {
    const res = await fetch('api_bridge.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(getRequestPayload(query)),
    });

    if (res.status === 429) {
      let data = {};
      try { data = await res.json(); } catch (_) {}
      rotator.stop();
      pendingState.fail(data.error || 'Anfragelimit erreicht.', true);
      return;
    }

    let result = null;
    try {
      result = await res.json();
    } catch (_) {
      rotator.stop();
      pendingState.fail('Ungültige Serverantwort.');
      return;
    }

    if (!res.ok) {
      rotator.stop();
      pendingState.fail(result?.error || 'Fehler bei der Verarbeitung. Bitte versuche es erneut.');
      return;
    }

    if (result?.clarify) {
      rotator.stop('Präzisierung erforderlich');
      pendingState.clarify(result.clarify);
      return;
    }

    rotator.stop('Antwort wird angezeigt');
    pendingState.finalize(result, query);

    requestAnimationFrame(() => {
      scrollMessageIntoView(userMsgEl, 'smooth');
    });

  } catch (err) {
    rotator.stop();
    pendingState.fail('Verbindungsfehler. Bitte versuche es erneut.');
  } finally {
    STATE.loading = false;
    document.getElementById('btn-send').disabled = false;
    chatInput.focus();
  }
}

function renderSources(sources) {
  const items = sources.map(s => {
    const icon  = s.type === 'attendance' ? '◆' : s.type === 'segment' ? '✦' : '○';
    const top   = s.top_key ? `TOP ${s.top_key}` : '';
    const title = s.top_title ? ` – ${escHtml(s.top_title)}` : '';
    const page  = s.page_from ? ` · S. ${s.page_from}` : '';
    const nr    = s.niederschrift_nr ? ` · Nr. ${s.niederschrift_nr}` : '';

    const infoInner = `${top}${title}${page}${nr}`;

    if (s.download_id) {
      const pageAnchor = s.page_from ? `#page=${s.page_from}` : '';
      const href = `https://ingest.stadtstimme.de/download.php?id=${s.download_id}${pageAnchor}`;
      return `<li class="source-item source-${s.type}">
        <span class="source-icon">${icon}</span>
        <span class="source-date">${escHtml(s.date || '–')}</span>
        <a class="source-info source-link" href="${href}" target="_blank" rel="noopener" title="Protokoll öffnen">${infoInner} <span class="source-link-icon">↗</span></a>
      </li>`;
    }

    return `<li class="source-item source-${s.type}">
      <span class="source-icon">${icon}</span>
      <span class="source-date">${escHtml(s.date || '–')}</span>
      <span class="source-info">${infoInner}</span>
    </li>`;
  }).join('');

  return `
    <details class="sources-block">
      <summary class="sources-toggle">
        ${sources.length} Quellen <span class="sources-legend"><span class="source-icon-segment">✦</span> Segment · <span class="source-icon-chunk">○</span> Chunk · <span class="source-icon-attendance">◆</span> Anwesenheit</span>
      </summary>
      <ul class="sources-list">${items}</ul>
    </details>
  `;
}

function appendClarify(text) {
  const messages = document.getElementById('messages');
  const el = document.createElement('div');
  el.className = 'message message-assistant message-clarify';
  el.innerHTML = `<div class="message-bubble">⚠️ ${escHtml(text)}</div>`;
  messages.appendChild(el);
  scrollToBottom();
}

function appendError(text, isRateLimit = false) {
  const messages = document.getElementById('messages');
  const el = document.createElement('div');
  el.className = 'message message-error';
  const extra = isRateLimit
    ? ` <button class="btn-unlock-inline" onclick="document.getElementById('btn-unlock').click()">Zugangscode eingeben</button>`
    : '';
  el.innerHTML = `<div class="message-bubble">⚠ ${escHtml(text)}${extra}</div>`;
  messages.appendChild(el);
  scrollToBottom();
}

async function sendFeedback(logId, rating, btn) {
  const row = btn.closest('.feedback-row');
  row.querySelectorAll('.feedback-btn').forEach(b => b.disabled = true);
  btn.classList.add('is-active');
  await fetch('feedback.php', {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify({ log_id: logId, rating }),
  });
}

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
  const cols = splitRow(sepLine);
  return cols.map(c => {
    const left  = c.startsWith(':');
    const right = c.endsWith(':');
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
    const line = lines[i];

    if (line.includes('|') && i + 1 < lines.length && isTableSepLine(lines[i + 1])) {
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
          const val = escHtml((r[idx] ?? '').trim());
          return `<td${a}>${val}</td>`;
        }).join('');
        return `<tr>${cells}</tr>`;
      }).join('');

      out.push(
        `<div class="md-table-wrap"><table class="md-table">` +
        `<thead><tr>${ths}</tr></thead>` +
        `<tbody>${tds}</tbody>` +
        `</table></div>`
      );

      if (i < lines.length && lines[i].trim() === '') i++;
      continue;
    }

    out.push(line);
    i++;
  }

  return out.join('\n');
}

function buildSourceLinkMap(sources) {
  const map = {};
  (sources || []).forEach(s => {
    if (!s.download_id || !s.date) return;
    if (!(s.date in map)) map[s.date] = s.download_id;
    else if (map[s.date] !== s.download_id) map[s.date] = null; // mehrdeutig (z. B. zwei Gremien am selben Tag)
  });
  return map;
}

function isoDateFromCitation(m) {
  let d = m.match(/(\d{4})-(\d{2})-(\d{2})/);
  if (d) return `${d[1]}-${d[2]}-${d[3]}`;
  d = m.match(/(\d{2})\.(\d{2})\.(\d{4})/);
  if (d) return `${d[3]}-${d[2]}-${d[1]}`;
  return null;
}

function sourceLinkForCitation(m, sourceMap) {
  if (!sourceMap) return null;
  const iso = isoDateFromCitation(m);
  if (!iso) return null;
  const id = sourceMap[iso];
  if (!id) return null; // null/undefined = mehrdeutig oder nicht vorhanden
  const pageMatch = m.match(/Seite\s+(\d+)/i) || m.match(/S\.\s*(\d+)/i);
  const anchor = pageMatch ? `#page=${pageMatch[1]}` : '';
  return `https://ingest.stadtstimme.de/download.php?id=${id}${anchor}`;
}

function formatAnswer(text, sourceMap) {
  text = text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');

  text = text.replace(/\*\*TL;DR:\*\*/g, '<strong class="tldr-label">TL;DR:</strong>');
  text = text.replace(/^---$/gm, '<hr class="md-hr">');
  text = text.replace(/^####\s+(.+)$/gm, '<h4 class="md-h4">$1</h4>');
  text = text.replace(/^###\s+(.+)$/gm,  '<h3 class="md-h3">$1</h3>');
  text = text.replace(/^##\s+(.+)$/gm,   '<h2 class="md-h2">$1</h2>');
  text = text.replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>');
  text = text.replace(/\*\*(.+?)\*\*/g,     '<strong>$1</strong>');
  text = text.replace(/\*(.+?)\*/g,         '<em>$1</em>');
  text = text.replace(/`(.+?)`/g,           '<code>$1</code>');
  text = text.replace(/\(Protokoll:[^)]+\)/g, m => {
    const link = sourceLinkForCitation(m, sourceMap);
    return link
      ? `<a class="inline-source inline-source-link" href="${link}" target="_blank" rel="noopener" title="Protokoll öffnen">${m}</a>`
      : `<span class="inline-source">${m}</span>`;
  });

  text = renderTables(text);

  text = text.replace(/^(?:    |\t)- (.+)$/gm, '<li class="md-li-nested">$1</li>');
  text = text.replace(/^- (.+)$/gm, '<li class="md-li">$1</li>');

  text = text.replace(/(<li class="md-li(?:-nested)?">[\s\S]*?<\/li>\n?)+/g, match => {
    return `<ul class="md-ul">${match}</ul>`;
  });
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

function escHtml(str) {
  return String(str)
    .replace(/&/g,'&amp;')
    .replace(/</g,'&lt;')
    .replace(/>/g,'&gt;');
}

const modalUnlock = document.getElementById('modal-unlock');
document.getElementById('btn-unlock').addEventListener('click', () => {
  // Wenn persistent freigeschaltet: Abmelden anbieten
  if (STATE.persistentCode) {
    if (confirm('Persistent-Code aktiv. Möchtest du dich abmelden?')) {
      localStorage.removeItem('kommrag_access_code');
      STATE.persistentCode = null;
      document.getElementById('btn-unlock').textContent = '🔑 Zugangscode';
      document.getElementById('btn-unlock').classList.remove('is-unlocked');
      // Large-Button entfernen
      const largeBtn = document.querySelector('#quality-pills [data-value="large"]');
      if (largeBtn) largeBtn.remove();
      // Auf small zurücksetzen falls large aktiv war
      if (STATE.quality === 'large') {
        STATE.quality = 'small';
        document.querySelector('#quality-pills [data-value="small"]')?.classList.add('is-active');
      }
    }
    return;
  }
  modalUnlock.hidden = false;
});
document.getElementById('modal-backdrop').addEventListener('click', () => { modalUnlock.hidden = true; });
document.getElementById('btn-modal-cancel').addEventListener('click', () => { modalUnlock.hidden = true; });

document.getElementById('btn-modal-ok').addEventListener('click', async () => {
  const code = document.getElementById('unlock-code').value.trim();
  const errEl = document.getElementById('unlock-error');
  errEl.hidden = true;

  if (!code) return;

  const res  = await fetch('redeem_code.php', {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify({ code }),
  });
  const data = await res.json();

  if (data.success) {
    modalUnlock.hidden = true;

    // Persistent-Code im LocalStorage speichern
    if (data.code_type === 'persistent') {
      localStorage.setItem('kommrag_access_code', data.code);
      STATE.persistentCode = data.code;
      document.getElementById('btn-unlock').textContent = '🔓 Persistent';
    } else {
      document.getElementById('btn-unlock').textContent = '🔓 Freigeschaltet';
    }
    document.getElementById('btn-unlock').classList.add('is-unlocked');

    // Large-Option einblenden
    const qp = document.getElementById('quality-pills');
    if (qp && !qp.querySelector('[data-value="large"]')) {
      const largeBtn = document.createElement('button');
      largeBtn.className = 'pill';
      largeBtn.dataset.value = 'large';
      largeBtn.textContent = 'Large 🔑';
      largeBtn.addEventListener('click', () => {
        qp.querySelectorAll('.pill').forEach(p => p.classList.remove('is-active'));
        largeBtn.classList.add('is-active');
        STATE.quality = 'large';
      });
      qp.appendChild(largeBtn);
    }

    document.getElementById('unlock-code').value = '';
  } else {
    errEl.textContent = data.error || 'Ungültiger Code.';
    errEl.hidden = false;
  }
});

// ─── Persistent-Code beim Seitenaufruf wiederherstellen ───
(function restorePersistentCode() {
  const savedCode = localStorage.getItem('kommrag_access_code');
  if (!savedCode) return;

  STATE.persistentCode = savedCode;
  document.getElementById('btn-unlock').textContent = '🔓 Persistent';
  document.getElementById('btn-unlock').classList.add('is-unlocked');

  // Large-Option einblenden (falls PHP es nicht schon getan hat)
  const qp = document.getElementById('quality-pills');
  if (qp && !qp.querySelector('[data-value="large"]')) {
    const largeBtn = document.createElement('button');
    largeBtn.className = 'pill';
    largeBtn.dataset.value = 'large';
    largeBtn.textContent = 'Large 🔑';
    largeBtn.addEventListener('click', () => {
      qp.querySelectorAll('.pill').forEach(p => p.classList.remove('is-active'));
      largeBtn.classList.add('is-active');
      STATE.quality = 'large';
    });
    qp.appendChild(largeBtn);
  }

  // Info-Text aktualisieren
  const infoEl = document.querySelector('.sidebar-info .muted');
  if (infoEl) infoEl.textContent = '✓ Unbegrenzte Anfragen aktiv';
})();
</script>

<!-- ==========================================================
     UMFRAGE-BANNER (Masterarbeits-Evaluation)
     Erscheint nach 2 abgeschlossenen Antworten, dismissible.
     ========================================================== -->
<div id="survey-banner" class="survey-banner" hidden>
  <div class="survey-banner-inner">
    <div class="survey-banner-text">
      <strong>Hilf mit, FragEssen zu evaluieren</strong>
      <span class="survey-banner-sub">
        Diese Umfrage läuft im Rahmen einer Masterarbeit an der FH Südwestfalen.
        Anonym, ca. 3 Minuten.
      </span>
    </div>
    <div class="survey-banner-actions">
      <a href="/umfrage/?src=banner" class="survey-banner-btn">Teilnehmen →</a>
      <button class="survey-banner-dismiss" id="survey-banner-dismiss" title="Ausblenden">✕</button>
    </div>
  </div>
</div>

<style>
.survey-banner {
  position: fixed;
  /* Sitzt immer über der Eingabeleiste (input-bar). --inputbar-h wird
     per JS auf die echte Höhe gesetzt; Fallback 96px. */
  bottom: calc(var(--inputbar-h, 96px) + 16px + env(safe-area-inset-bottom, 0px));
  left: 50%;
  transform: translateX(-50%);
  max-width: 560px;
  width: calc(100% - 2rem);
  background: var(--bg-card, #fff);
  color: var(--text, #222);
  border: 1px solid rgba(123, 67, 151, 0.3);
  border-radius: 12px;
  box-shadow: 0 8px 24px rgba(0,0,0,0.12);
  z-index: 150;                 /* über input-bar (90), unter Modal (200) */
  padding: .9rem 1.1rem;
  animation: surveyBannerIn .35s ease-out;
}
@keyframes surveyBannerIn {
  from { opacity: 0; transform: translate(-50%, 12px); }
  to   { opacity: 1; transform: translate(-50%, 0); }
}
.survey-banner-inner {
  display: flex;
  align-items: center;
  gap: 1rem;
  justify-content: space-between;
}
.survey-banner-text {
  display: flex;
  flex-direction: column;
  gap: .2rem;
  min-width: 0;
}
.survey-banner-text strong { font-size: .95rem; line-height: 1.3; }
.survey-banner-sub { font-size: .82rem; opacity: .72; line-height: 1.35; }
.survey-banner-actions { display: flex; align-items: center; gap: .4rem; flex-shrink: 0; }
.survey-banner-btn {
  background: #7b4397;
  color: #fff;
  padding: .55rem 1rem;
  border-radius: 999px;
  text-decoration: none;
  font-size: .9rem;
  font-weight: 500;
  white-space: nowrap;
  transition: background .15s;
}
.survey-banner-btn:hover { background: #5f3375; }
.survey-banner-dismiss {
  background: transparent;
  border: none;
  color: inherit;
  opacity: .5;
  cursor: pointer;
  font-size: 1.1rem;
  padding: .3rem .5rem;
  border-radius: 4px;
}
.survey-banner-dismiss:hover { opacity: 1; background: rgba(0,0,0,.05); }
@media (max-width: 600px) {
  .survey-banner {
    bottom: calc(var(--inputbar-h, 110px) + 12px + env(safe-area-inset-bottom, 0px));
    padding: .75rem .9rem;
  }
  .survey-banner-inner { flex-direction: column; align-items: stretch; gap: .6rem; }
  .survey-banner-actions { justify-content: space-between; }
  .survey-banner-btn { flex: 1; text-align: center; }
}

/* Filter-Hinweis */
.filter-hint {
  font-size: .8rem;
  opacity: .7;
  margin: .35rem 0 0;
  line-height: 1.35;
}

/* Abgedeckte Gremien (Empty-State) */
.gremien-status {
  margin-top: 1.4rem;
  font-size: .85rem;
  opacity: .85;
}
.gremien-status > summary {
  cursor: pointer;
  opacity: .8;
}
.gremien-status > summary:hover { opacity: 1; }
.gremien-status-list {
  list-style: none;
  margin: .6rem 0 0;
  padding: 0;
  text-align: left;
  display: inline-block;
}
.gremien-status-list li {
  display: flex;
  justify-content: space-between;
  gap: 1.2rem;
  padding: .25rem 0;
}
.gremien-status-meta { opacity: .65; white-space: nowrap; }

/* Klickbare Quellenzeile */
.source-link {
  color: inherit;
  text-decoration: none;
  cursor: pointer;
}
.source-link:hover { text-decoration: underline; }
.source-link-icon { opacity: .55; font-size: .85em; }

/* Verlinkter Inline-Beleg im Antworttext */
.inline-source-link {
  color: inherit;
  text-decoration: none;
  border-bottom: 1px dotted currentColor;
  cursor: pointer;
}
.inline-source-link:hover { border-bottom-style: solid; }
</style>

<script>
(function syncInputBarHeight() {
  const inputBar = document.querySelector('.input-bar');
  if (!inputBar) return;
  function update() {
    document.documentElement.style.setProperty('--inputbar-h', inputBar.offsetHeight + 'px');
  }
  update();
  window.addEventListener('resize', update);
  if (window.ResizeObserver) new ResizeObserver(update).observe(inputBar);
  const chatInput = document.getElementById('chat-input');
  if (chatInput) chatInput.addEventListener('input', update);
})();

(function() {
  const STORAGE_KEY_COUNT = 'fragessen_answer_count';
  const STORAGE_KEY_DISMISSED = 'fragessen_survey_dismissed';
  const THRESHOLD = 2;

  const banner = document.getElementById('survey-banner');
  const dismissBtn = document.getElementById('survey-banner-dismiss');
  if (!banner) return;

  const isDismissed = () => localStorage.getItem(STORAGE_KEY_DISMISSED) === '1';
  const getCount    = () => parseInt(localStorage.getItem(STORAGE_KEY_COUNT) || '0', 10);

  function maybeShow() {
    if (isDismissed()) return;
    if (getCount() >= THRESHOLD) banner.hidden = false;
  }

  maybeShow();

  const messagesEl = document.getElementById('messages');
  if (messagesEl) {
    const observer = new MutationObserver(mutations => {
      for (const m of mutations) {
        if (m.type === 'attributes' && m.attributeName === 'class') {
          const target = m.target;
          if (target.classList.contains('message-assistant') &&
              target.classList.contains('is-ready') &&
              !target.dataset.surveyCounted) {
            target.dataset.surveyCounted = '1';
            const n = getCount() + 1;
            localStorage.setItem(STORAGE_KEY_COUNT, String(n));
            maybeShow();
          }
        }
      }
    });
    observer.observe(messagesEl, {
      subtree: true,
      attributes: true,
      attributeFilter: ['class']
    });
  }

  dismissBtn.addEventListener('click', () => {
    localStorage.setItem(STORAGE_KEY_DISMISSED, '1');
    banner.hidden = true;
  });
})();
</script>

</body>
</html>