<?php
// /umfrage/index.php
// Einstiegsseite mit Einwilligung und Stage 1
declare(strict_types=1);

session_start();
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/questions.php';

if (empty($_SESSION['survey_token'])) {
    $_SESSION['survey_token'] = newSessionToken();
}

if (empty($_SESSION['survey_source'])) {
    $allowedSources = ['banner', 'mail', 'direct'];
    $src = $_GET['src'] ?? 'direct';
    $_SESSION['survey_source'] = in_array($src, $allowedSources, true) ? $src : 'direct';
}

$token = $_SESSION['survey_token'];
$stage1 = stage1Questions();
?>
<!DOCTYPE html>
<html lang="de" data-theme="auto">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>FragEssen – Evaluations-Umfrage</title>
<link rel="stylesheet" href="https://fragessen.stadtstimme.de/fonts.css">
<link rel="stylesheet" href="style.css">
</head>
<body>

<div class="wrap">

<?php
  $surveyHeaderTitle = 'Evaluation von FragEssen';
  $surveyHeaderSub   = 'Masterarbeit · FH Südwestfalen · Angewandte Künstliche Intelligenz';
  include __DIR__ . '/_header.php';
?>

<div class="progress">
  <div class="progress-step is-active">1 · Einwilligung &amp; Angaben</div>
  <div class="progress-step">2 · Optionale Vertiefung</div>
  <div class="progress-step">3 · Abschluss</div>
</div>

<!-- Einwilligung -->
<div class="consent-box" id="consent">
  <?php include __DIR__ . '/../pages/datenschutz_umfrage.html'; ?>

  <label class="consent-checkbox">
    <input type="checkbox" id="consent-check">
    <span>
      Ich habe die Informationen gelesen und willige in die Verarbeitung meiner Antworten
      zum genannten Zweck ein. Die Teilnahme ist freiwillig.
    </span>
  </label>
  <div class="error-msg" id="consent-error" style="display:none;">
    Bitte bestätigen Sie die Einwilligung, um fortzufahren.
  </div>
</div>

<!-- Fragebogen Stage 1 -->
<form id="stage1-form" style="display:none;">

  <div class="card">
    <div class="question">
      <label class="question-label">
        Was beschreibt Sie am besten?
        <span class="question-note">Hilft mir, Ihre Antworten besser einzuordnen.</span>
      </label>
      <div class="radio-group" data-group="role">
        <label class="radio-option"><input type="radio" name="role" value="bv" required> Bezirksvertretung</label>
        <label class="radio-option"><input type="radio" name="role" value="rat"> Rat der Stadt Essen (Ratsleute und Sachkundige Bürger:innen)</label>
        <label class="radio-option"><input type="radio" name="role" value="verwaltung"> Verwaltung der Stadt Essen</label>
        <label class="radio-option"><input type="radio" name="role" value="buergerschaft"> Bürger:in / interessierte Öffentlichkeit</label>
      </div>
    </div>

    <div class="question">
      <label class="question-label">Geschlecht</label>
      <div class="radio-group horizontal" data-group="gender">
        <label class="radio-option"><input type="radio" name="gender" value="weiblich" required> weiblich</label>
        <label class="radio-option"><input type="radio" name="gender" value="maennlich"> männlich</label>
        <label class="radio-option"><input type="radio" name="gender" value="divers"> divers</label>
        <label class="radio-option"><input type="radio" name="gender" value="keine_angabe"> keine Angabe</label>
      </div>
    </div>

    <div class="question" id="ris-familiarity-block" style="display:none;">
      <label class="question-label">
        Kennen Sie das
        <abbr class="glossar" data-tip="Ratsinformationssystem: die offizielle digitale Dokumentenablage der Stadt Essen mit Sitzungsunterlagen und Protokollen.">Ratsinformationssystem (RIS)</abbr>
        der Stadt Essen?
      </label>
      <div class="radio-group" data-group="ris_familiarity">
        <label class="radio-option"><input type="radio" name="ris_familiarity" value="nutze"> Ja, ich nutze es regelmäßig</label>
        <label class="radio-option"><input type="radio" name="ris_familiarity" value="kenne_nur"> Ja, kenne es, nutze es aber kaum</label>
        <label class="radio-option"><input type="radio" name="ris_familiarity" value="kenne_nicht"> Nein, kenne ich nicht</label>
      </div>
    </div>
  </div>

  <div class="card" id="stage1-questions">
    <?php foreach ($stage1 as $q): ?>
      <?php
        $hideForRole = isset($q['hide_for_role']) ? implode(',', $q['hide_for_role']) : '';
      ?>

      <?php if ($q['type'] === 'info'): ?>
        <div class="info-block" style="margin-bottom: 1.5rem;">
          <?= htmlspecialchars($q['label']) ?>
        </div>
        <?php continue; ?>
      <?php endif; ?>

      <div class="question"
           data-key="<?= htmlspecialchars($q['key']) ?>"
           data-type="<?= htmlspecialchars($q['type']) ?>"
           <?= isset($q['conditional']) ? 'data-conditional="'.htmlspecialchars($q['conditional']).'"' : '' ?>
           <?= $hideForRole ? 'data-hide-for-role="'.htmlspecialchars($hideForRole).'"' : '' ?>>

        <label class="question-label"><?= renderLabelWithGlossar($q['label']) ?></label>

        <?php if ($q['type'] === 'likert5'): ?>
          <div class="likert-scale">
            <?php foreach ($q['options'] as $val => $label): ?>
              <label class="likert-option">
                <input type="radio" name="<?= $q['key'] ?>" value="<?= $val ?>">
                <strong><?= $val ?></strong>
                <?php if ($label): ?><span class="likert-option-label"><?= htmlspecialchars($label) ?></span><?php endif; ?>
              </label>
            <?php endforeach; ?>
          </div>

        <?php elseif ($q['type'] === 'likert7'): ?>
          <div class="likert-scale">
            <?php foreach ($q['options'] as $val => $label): ?>
              <label class="likert-option">
                <input type="radio" name="<?= $q['key'] ?>" value="<?= $val ?>">
                <strong><?= $val ?></strong>
                <?php if ($label): ?><span class="likert-option-label"><?= htmlspecialchars($label) ?></span><?php endif; ?>
              </label>
            <?php endforeach; ?>
          </div>

        <?php elseif ($q['type'] === 'nps'): ?>
          <div class="nps-scale">
            <?php for ($i = 0; $i <= 10; $i++): ?>
              <label class="nps-option">
                <input type="radio" name="<?= $q['key'] ?>" value="<?= $i ?>">
                <span><?= $i ?></span>
              </label>
            <?php endfor; ?>
          </div>
          <div class="nps-labels">
            <span>unwahrscheinlich</span>
            <span>sehr wahrscheinlich</span>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="actions between">
    <div style="color: var(--text-muted); font-size: .9rem;">Schritt 1 von 3 · ca. 2–3 Min</div>
    <button type="submit" class="btn-primary" id="btn-stage1-submit">Weiter →</button>
  </div>

  <div class="error-msg" id="submit-error" style="display:none;"></div>
</form>

</div>

<?php include __DIR__ . '/_footer.php'; ?>

<script>
const TOKEN = <?= json_encode($token) ?>;
const consentCheck = document.getElementById('consent-check');
const form = document.getElementById('stage1-form');
const consentBox = document.getElementById('consent');

consentCheck.addEventListener('change', () => {
  if (consentCheck.checked) {
    consentBox.style.opacity = '.7';
    form.style.display = '';
    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
  } else {
    consentBox.style.opacity = '1';
    form.style.display = 'none';
  }
});

document.querySelectorAll('.radio-option, .likert-option, .nps-option').forEach(el => {
  el.addEventListener('click', () => {
    const input = el.querySelector('input[type="radio"]');
    if (!input) return;
    const name = input.name;
    document.querySelectorAll(`input[name="${name}"]`).forEach(i => {
      i.closest('.radio-option, .likert-option, .nps-option')?.classList.remove('is-selected');
    });
    el.classList.add('is-selected');
  });
});

// Glossar: Klick-Support auf Mobile, Close-on-outside-click
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

const risBlock = document.getElementById('ris-familiarity-block');

function updateRoleConditionals() {
  const role = document.querySelector('input[name="role"]:checked')?.value;

  if (role === 'buergerschaft') {
    risBlock.style.display = '';
    risBlock.querySelectorAll('input').forEach(i => i.required = true);
  } else {
    risBlock.style.display = 'none';
    risBlock.querySelectorAll('input').forEach(i => { i.required = false; i.checked = false; });
    risBlock.querySelectorAll('.radio-option').forEach(o => o.classList.remove('is-selected'));
  }

  // Rollenabhängiges Ausblenden von Fragen
  document.querySelectorAll('[data-hide-for-role]').forEach(q => {
    const hideFor = (q.dataset.hideForRole || '').split(',').map(s => s.trim());
    const hide = hideFor.includes(role);
    q.style.display = hide ? 'none' : '';
    if (hide) {
      q.querySelectorAll('input').forEach(i => i.checked = false);
      q.querySelectorAll('.likert-option, .nps-option').forEach(o => o.classList.remove('is-selected'));
    }
  });

  updateConditionalQuestions();
}

document.querySelectorAll('input[name="role"]').forEach(r => {
  r.addEventListener('change', updateRoleConditionals);
});

function risKnown() {
  const role = document.querySelector('input[name="role"]:checked')?.value;
  if (role === 'bv' || role === 'rat' || role === 'verwaltung') return true;
  if (role === 'buergerschaft') {
    const ris = document.querySelector('input[name="ris_familiarity"]:checked')?.value;
    return ris === 'nutze' || ris === 'kenne_nur';
  }
  return false;
}

function updateConditionalQuestions() {
  document.querySelectorAll('[data-conditional]').forEach(q => {
    const cond = q.dataset.conditional;
    if (cond === 'ris_known') {
      if (q.dataset.hideForRole) {
        const role = document.querySelector('input[name="role"]:checked')?.value;
        const hideFor = (q.dataset.hideForRole || '').split(',').map(s => s.trim());
        if (hideFor.includes(role)) { q.style.display = 'none'; return; }
      }
      const show = risKnown();
      q.style.display = show ? '' : 'none';
      q.querySelectorAll('input').forEach(i => { if (!show) i.checked = false; });
      q.querySelectorAll('.likert-option').forEach(o => { if (!show) o.classList.remove('is-selected'); });
    }
  });
}

document.querySelectorAll('input[name="ris_familiarity"]').forEach(r => {
  r.addEventListener('change', updateConditionalQuestions);
});

form.addEventListener('submit', async (e) => {
  e.preventDefault();

  if (!consentCheck.checked) {
    document.getElementById('consent-error').style.display = '';
    return;
  }

  const btn = document.getElementById('btn-stage1-submit');
  const errEl = document.getElementById('submit-error');
  errEl.style.display = 'none';
  btn.disabled = true;
  btn.textContent = 'Wird gesendet …';

  const payload = {
    token: TOKEN,
    consent: true,
    role: document.querySelector('input[name="role"]:checked')?.value,
    gender: document.querySelector('input[name="gender"]:checked')?.value,
    ris_familiarity: document.querySelector('input[name="ris_familiarity"]:checked')?.value || 'na',
    responses: {},
  };

  document.querySelectorAll('#stage1-questions .question').forEach(q => {
    if (q.style.display === 'none') return;
    const key = q.dataset.key;
    const val = document.querySelector(`input[name="${key}"]:checked`)?.value;
    if (val !== undefined) {
      payload.responses[key] = val;
    }
  });

  if (!payload.role || !payload.gender) {
    errEl.textContent = 'Bitte beantworten Sie die Pflichtfragen.';
    errEl.style.display = '';
    btn.disabled = false;
    btn.textContent = 'Weiter →';
    return;
  }

  try {
    const res = await fetch('submit.php?stage=1', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const data = await res.json();

    if (!res.ok || !data.success) {
      if (data.error_code === 'already_submitted') {
        errEl.innerHTML =
          'Diese Umfrage wurde mit der aktuellen Browser-Sitzung bereits abgeschickt. ' +
          'Falls Sie versehentlich auf diesen Fehler stoßen, können Sie ' +
          '<a href="#" id="reset-session-link" style="color:var(--accent);text-decoration:underline;">eine neue Sitzung starten</a>.';
        errEl.style.display = '';
        document.getElementById('reset-session-link')?.addEventListener('click', async (ev) => {
          ev.preventDefault();
          await fetch('clear_session.php', { method: 'POST' });
          location.reload();
        });
      } else {
        errEl.textContent = data.error || 'Fehler beim Speichern. Bitte erneut versuchen.';
        errEl.style.display = '';
      }
      btn.disabled = false;
      btn.textContent = 'Weiter →';
      return;
    }

    window.location.href = 'stage2.php';
  } catch (err) {
    errEl.textContent = 'Verbindungsfehler. Bitte erneut versuchen.';
    errEl.style.display = '';
    btn.disabled = false;
    btn.textContent = 'Weiter →';
  }
});
</script>
</body>
</html>
