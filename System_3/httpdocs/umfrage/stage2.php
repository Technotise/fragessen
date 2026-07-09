<?php
// /umfrage/stage2.php
// Optionaler Vertiefungsteil
declare(strict_types=1);

session_start();
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/questions.php';

if (empty($_SESSION['survey_token'])) {
    header('Location: index.php');
    exit;
}

$pdo = surveyPdo();
$stmt = $pdo->prepare("SELECT stage1_completed_at FROM survey_participants WHERE session_token = ?");
$stmt->execute([$_SESSION['survey_token']]);
$row = $stmt->fetch();

if (!$row || !$row['stage1_completed_at']) {
    header('Location: index.php');
    exit;
}

$token = $_SESSION['survey_token'];
$stage2 = stage2Questions();
?>
<!DOCTYPE html>
<html lang="de" data-theme="auto">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>FragEssen – Optionale Vertiefung</title>
<link rel="stylesheet" href="https://fragessen.stadtstimme.de/fonts.css">
<link rel="stylesheet" href="style.css">
</head>
<body>

<div class="wrap">

<?php
  $surveyHeaderTitle = 'Vielen Dank – helfen Sie mir noch kurz weiter?';
  $surveyHeaderSub   = 'Ihre Pflichtangaben sind gespeichert. Die folgenden Fragen sind optional.';
  include __DIR__ . '/_header.php';
?>

<div class="progress">
  <div class="progress-step">1 · Einwilligung &amp; Angaben ✓</div>
  <div class="progress-step is-active">2 · Optionale Vertiefung</div>
  <div class="progress-step">3 · Abschluss</div>
</div>

<div class="info-block">
  <strong>Zeitbedarf:</strong> ca. 3–5 Minuten zusätzlich. Sie können den Teil jederzeit überspringen.
  Ihre bisherigen Antworten bleiben erhalten.
</div>

<form id="stage2-form">

  <?php
  $trustQuestions = array_filter($stage2, fn($q) => str_starts_with($q['key'], 'trust_'));
  if ($trustQuestions):
  ?>
  <div class="card">
    <h3 style="margin-top:0;">Vertrauen &amp; Transparenz</h3>
    <?php foreach ($trustQuestions as $q): ?>
      <div class="question" data-key="<?= htmlspecialchars($q['key']) ?>">
        <label class="question-label"><?= renderLabelWithGlossar($q['label']) ?></label>
        <div class="likert-scale">
          <?php foreach ($q['options'] as $val => $label): ?>
            <label class="likert-option">
              <input type="radio" name="<?= $q['key'] ?>" value="<?= $val ?>">
              <strong><?= $val ?></strong>
              <?php if ($label): ?><span class="likert-option-label"><?= htmlspecialchars($label) ?></span><?php endif; ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php
  $textQuestions = array_filter($stage2, fn($q) => $q['type'] === 'textarea');
  if ($textQuestions):
  ?>
  <div class="card">
    <h3 style="margin-top:0;">Ihre Eindrücke</h3>
    <?php foreach ($textQuestions as $q): ?>
      <div class="question">
        <label class="question-label"><?= renderLabelWithGlossar($q['label']) ?></label>
        <textarea name="<?= $q['key'] ?>" maxlength="2000" placeholder="Optional – Sie müssen nichts schreiben."></textarea>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php
  $susQuestions = array_filter($stage2, fn($q) => isset($q['sus_item']));
  $susIntro = current(array_filter($stage2, fn($q) => $q['key'] === 'sus_intro'));
  if ($susQuestions):
  ?>
  <div class="card">
    <h3 style="margin-top:0;">System Usability Scale (SUS)</h3>
    <?php if ($susIntro): ?>
      <div class="info-block" style="margin-bottom:1rem;">
        <?= htmlspecialchars($susIntro['label']) ?>
      </div>
    <?php endif; ?>
    <?php foreach ($susQuestions as $q): ?>
      <div class="question" data-key="<?= htmlspecialchars($q['key']) ?>">
        <label class="question-label"><?= $q['sus_item'] ?>. <?= renderLabelWithGlossar($q['label']) ?></label>
        <div class="likert-scale">
          <?php foreach ($q['options'] as $val => $label): ?>
            <label class="likert-option">
              <input type="radio" name="<?= $q['key'] ?>" value="<?= $val ?>">
              <strong><?= $val ?></strong>
              <?php if ($label): ?><span class="likert-option-label"><?= htmlspecialchars($label) ?></span><?php endif; ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="actions between">
    <button type="button" class="btn-secondary" id="btn-skip">Überspringen &amp; abschließen</button>
    <button type="submit" class="btn-primary" id="btn-stage2-submit">Antworten speichern →</button>
  </div>

  <div class="error-msg" id="submit-error" style="display:none;"></div>
</form>

</div>

<!-- Bestätigungs-Dialog fürs Überspringen -->
<div class="confirm-backdrop" id="confirm-skip" hidden>
  <div class="confirm-card">
    <h3>Wirklich überspringen?</h3>
    <p>Die Angaben, die Sie bisher in diesem Teil gemacht haben, gehen dabei verloren.</p>
    <div class="actions">
      <button type="button" class="btn-secondary" id="btn-skip-cancel">Weiter ausfüllen</button>
      <button type="button" class="btn-primary" id="btn-skip-confirm">Ja, überspringen</button>
    </div>
  </div>
</div>

<?php include __DIR__ . '/_footer.php'; ?>

<script>
const TOKEN = <?= json_encode($token) ?>;

document.querySelectorAll('.likert-option').forEach(el => {
  el.addEventListener('click', () => {
    const input = el.querySelector('input[type="radio"]');
    if (!input) return;
    document.querySelectorAll(`input[name="${input.name}"]`).forEach(i => {
      i.closest('.likert-option')?.classList.remove('is-selected');
    });
    el.classList.add('is-selected');
  });
});

// Glossar: Klick-Support auf Mobile
document.addEventListener('click', e => {
  const glossar = e.target.closest('abbr.glossar');
  document.querySelectorAll('abbr.glossar.is-active').forEach(a => {
    if (a !== glossar) a.classList.remove('is-active');
  });
  if (glossar) {
    glossar.classList.toggle('is-active');
    e.preventDefault();
  }
});

// Überspringen-Bestätigung
const confirmSkip = document.getElementById('confirm-skip');
document.getElementById('btn-skip').addEventListener('click', () => {
  confirmSkip.hidden = false;
});
document.getElementById('btn-skip-cancel').addEventListener('click', () => {
  confirmSkip.hidden = true;
});
document.getElementById('btn-skip-confirm').addEventListener('click', () => {
  window.location.href = 'danke.php?skipped=1';
});

document.getElementById('stage2-form').addEventListener('submit', async (e) => {
  e.preventDefault();

  const btn = document.getElementById('btn-stage2-submit');
  const errEl = document.getElementById('submit-error');
  errEl.style.display = 'none';
  btn.disabled = true;
  btn.textContent = 'Wird gesendet …';

  const responses = {};

  document.querySelectorAll('#stage2-form input[type="radio"]:checked').forEach(i => {
    responses[i.name] = i.value;
  });

  document.querySelectorAll('#stage2-form textarea').forEach(t => {
    const v = t.value.trim();
    if (v) responses[t.name] = v;
  });

  try {
    const res = await fetch('submit.php?stage=2', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: TOKEN, responses }),
    });
    const data = await res.json();

    if (!res.ok || !data.success) {
      errEl.textContent = data.error || 'Fehler beim Speichern.';
      errEl.style.display = '';
      btn.disabled = false;
      btn.textContent = 'Antworten speichern →';
      return;
    }

    window.location.href = 'danke.php';
  } catch (err) {
    errEl.textContent = 'Verbindungsfehler.';
    errEl.style.display = '';
    btn.disabled = false;
    btn.textContent = 'Antworten speichern →';
  }
});
</script>
</body>
</html>
