<?php
// /umfrage/_header.php
// Gemeinsamer Header mit Titel, Subtitle und Theme-Toggle-Button.
// Nutzung: $surveyHeaderTitle und $surveyHeaderSub vorher setzen, dann include.

$title = $surveyHeaderTitle ?? 'Evaluation von FragEssen';
$sub   = $surveyHeaderSub   ?? 'Masterarbeit · FH Südwestfalen · Angewandte Künstliche Intelligenz';
?>
<header class="header">
  <div class="header-text">
    <h1><?= htmlspecialchars($title) ?></h1>
    <div class="subtitle"><?= htmlspecialchars($sub) ?></div>
  </div>
  <button class="btn-theme" id="btn-theme" title="Design wechseln" aria-label="Hell/Dunkel wechseln">
    <svg viewBox="0 0 24 24" stroke-width="2">
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
</header>
<script>
(function initSurveyTheme() {
  const saved = localStorage.getItem('kommrag_theme') || 'auto';
  document.documentElement.setAttribute('data-theme', saved);
  const btn = document.getElementById('btn-theme');
  if (btn) {
    btn.addEventListener('click', () => {
      const cur = document.documentElement.getAttribute('data-theme') || 'auto';
      const next = cur === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      localStorage.setItem('kommrag_theme', next);
    });
  }
})();
</script>
