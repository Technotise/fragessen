<?php
// /umfrage/danke.php
declare(strict_types=1);

session_start();
$skipped = isset($_GET['skipped']);

unset($_SESSION['survey_token']);
unset($_SESSION['survey_source']);
?>
<!DOCTYPE html>
<html lang="de" data-theme="auto">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>FragEssen – Danke</title>
<link rel="stylesheet" href="https://fragessen.stadtstimme.de/fonts.css">
<link rel="stylesheet" href="style.css">
</head>
<body>

<div class="wrap">

<?php
  $surveyHeaderTitle = 'Vielen Dank!';
  $surveyHeaderSub   = $skipped
    ? 'Ihre Pflichtangaben sind gespeichert.'
    : 'Ihre Antworten sind vollständig gespeichert.';
  include __DIR__ . '/_header.php';
?>

<div class="progress">
  <div class="progress-step">1 · Einwilligung ✓</div>
  <div class="progress-step">2 · Vertiefung <?= $skipped ? '(übersprungen)' : '✓' ?></div>
  <div class="progress-step is-active">3 · Abschluss</div>
</div>

<div class="card thanks">
  <div class="thanks-icon">🙏</div>
  <h1>Vielen Dank!</h1>
  <p>Ihre Antworten sind gespeichert und fließen in die Evaluation von FragEssen ein.</p>

  <p style="margin-top:1.5rem;">
    <a href="https://fragessen.stadtstimme.de" class="btn-primary">Zurück zu FragEssen</a>
  </p>

  <p style="color: var(--text-muted); font-size: .9rem; margin-top: 2rem;">
    Falls Sie Ihre Kolleg:innen motivieren möchten, teilnehmen zu lassen, freue ich mich über jede
    weitere Stimme. Die Umfrage ist unter der gleichen Adresse erreichbar, unter der Sie sie aufgerufen haben.
  </p>
</div>

</div>

<?php include __DIR__ . '/_footer.php'; ?>

</body>
</html>
