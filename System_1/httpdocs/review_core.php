<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/src/db.php';
require __DIR__ . '/src/auth.php';
require __DIR__ . '/src/locks.php';
require __DIR__ . '/ui/layout.php';

require_login();

$pdo = db();

function parse_date_ymd(string $s): ?string
{
  $s = trim($s);
  if ($s === '') return null;
  $dt = DateTime::createFromFormat('Y-m-d', $s);
  if (!$dt) return null;
  return $dt->format('Y-m-d');
}

function parse_time_hhmm_to_time(string $s): ?string
{
  $s = trim($s);
  if ($s === '') return null;
  if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $s, $m)) return null;
  return sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]);
}

function parse_int_nullable(string $s): ?int
{
  $s = trim($s);
  if ($s === '') return null;
  if (!preg_match('/^\d{1,6}$/', $s)) return null;
  return (int)$s;
}

function json_assoc(string $raw): array
{
  $raw = trim($raw);
  if ($raw === '') return [];
  $d = json_decode($raw, true);
  return is_array($d) ? $d : [];
}

function extracted_core_from_json(array $j): array
{
  $ort = trim((string)($j['ort'] ?? ''));
  $datum = trim((string)($j['datum'] ?? ''));
  $uhrzeit = trim((string)($j['uhrzeit'] ?? ''));
  $nr = trim((string)($j['niederschrift_nr'] ?? ''));

  if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $uhrzeit)) {
    $uhrzeit = substr($uhrzeit, 0, 5);
  }

  return [
    'ort' => $ort,
    'datum' => $datum,
    'uhrzeit' => $uhrzeit,
    'niederschrift_nr' => $nr,
  ];
}

function actor_string(int $userId): string
{
  if ($userId > 0) return 'user:' . (string)$userId;
  return 'ui';
}

function log_doc(PDO $pdo, int $docId, string $level, string $task, string $message, string $actor): void
{
  $pdo->prepare("
    INSERT INTO document_logs (document_id, level, task, message, actor, created_at)
    VALUES (?, ?, ?, ?, ?, NOW())
  ")->execute([$docId, $level, $task, $message, $actor]);
}

function set_task_status(PDO $pdo, int $docId, string $task, int $taskOrder, string $status): void
{
  $pdo->prepare("
    INSERT INTO document_state (document_id, task, task_order, status, updated_at, created_at)
    VALUES (?, ?, ?, ?, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
      status = VALUES(status),
      task_order = VALUES(task_order),
      updated_at = NOW()
  ")->execute([$docId, $task, $taskOrder, $status]);
}

function next_open_doc_core(PDO $pdo, int $gremiumId, int $excludeId = 0): int
{
  $params = [];
  $where = "d.deleted_at IS NULL";

  $where .= " AND COALESCE(ex.status,'') = 'fertig'";
  $where .= " AND COALESCE(cur.status,'') <> 'fertig'";

  if ($gremiumId > 0) { $where .= " AND d.gremium_id = ?"; $params[] = $gremiumId; }
  if ($excludeId > 0) { $where .= " AND d.id <> ?"; $params[] = $excludeId; }

  $sql = "
    SELECT d.id
    FROM documents d
    LEFT JOIN document_state ex
      ON ex.document_id = d.id AND ex.task = 'extract_core'
    LEFT JOIN document_state cur
      ON cur.document_id = d.id AND cur.task = 'curation_core'
    WHERE $where
    ORDER BY d.created_at ASC
    LIMIT 1
  ";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return (int)($row['id'] ?? 0);
}

$errors = [];
$success = null;

$docId = (int)($_GET['id'] ?? 0);
$gremiumId = (int)($_GET['gremium_id'] ?? 0);

$userId = get_session_user_id();
$userName = get_session_user_name();
$actor = actor_string($userId);

if ($docId <= 0) {
  $docId = next_open_doc_core($pdo, $gremiumId, 0);
}

if ($docId <= 0) {
  ui_header('Core Kuration', 'queue');
  ?>
  <div class="ui-box">
    <h2>Keine offenen Dokumente</h2>
    <div class="ui-muted">Es gibt aktuell keine Dokumente mit fertiger Core Extraktion, die eine Core Kuration benötigen.</div>
    <div class="ui-actions" style="margin-top:0.9rem;">
      <a class="ui-link" href="queue.php<?= $gremiumId > 0 ? '?gremium_id=' . (int)$gremiumId : '' ?>">Zur Queue</a>
    </div>
  </div>
  <?php
  ui_footer();
  exit;
}

$lockInfo = acquire_lock($pdo, $docId, $userId, $userName);
$hasLock = (bool)($lockInfo['ok'] ?? false);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $docId = (int)($_POST['document_id'] ?? 0);
  $gremiumId = (int)($_POST['gremium_id'] ?? 0);
  $action = (string)($_POST['action'] ?? '');

  if ($docId <= 0) {
    $errors[] = 'Ungültige Dokument ID.';
  } else {
    if (!has_own_lock($pdo, $docId, $userId)) {
      $errors[] = 'Dieses Dokument ist gesperrt. Bitte entsperren oder später erneut versuchen.';
    } else {
      refresh_lock($pdo, $docId, $userId);

      if ($action === 'skip') {
        try {
          log_doc($pdo, $docId, 'info', 'curation_core', 'Core Kuration übersprungen', $actor);
        } catch (Throwable $e) {
        }

        release_lock($pdo, $docId, $userId);
        $nextId = next_open_doc_core($pdo, $gremiumId, $docId);

        if ($nextId > 0) {
          $url = 'review_core.php?id=' . $nextId;
          if ($gremiumId > 0) $url .= '&gremium_id=' . (int)$gremiumId;
          header('Location: ' . $url);
          exit;
        }

        $url = 'queue.php';
        if ($gremiumId > 0) $url .= '?gremium_id=' . (int)$gremiumId;
        header('Location: ' . $url);
        exit;
      }

      try {
        $curDate = parse_date_ymd((string)($_POST['curated_sitzungsdatum'] ?? ''));
        if (isset($_POST['curated_sitzungsdatum']) && trim((string)$_POST['curated_sitzungsdatum']) !== '' && !$curDate) {
          throw new RuntimeException('Ungültiges Datum. Format muss YYYY-MM-DD sein.');
        }

        $curTime = null;
        if (isset($_POST['curated_uhrzeit_start'])) {
          $raw = (string)$_POST['curated_uhrzeit_start'];
          if (trim($raw) !== '') {
            $curTime = parse_time_hhmm_to_time($raw);
            if ($curTime === null) throw new RuntimeException('Ungültige Uhrzeit. Format muss HH:MM sein.');
          }
        }

        $curNr = null;
        if (isset($_POST['curated_niederschrift_nr'])) {
          $raw = (string)$_POST['curated_niederschrift_nr'];
          if (trim($raw) !== '') {
            $curNr = parse_int_nullable($raw);
            if ($curNr === null) throw new RuntimeException('Ungültige Niederschrift Nummer.');
          }
        }

        $curOrt = trim((string)($_POST['curated_ort'] ?? ''));
        $curOrt = ($curOrt === '') ? null : $curOrt;

        $curTyp = trim((string)($_POST['curated_sitzungstyp'] ?? ''));
        $allowedTypes = ['regulaer', 'sonder', 'dringlich'];
        if ($curTyp === '') $curTyp = 'regulaer';
        if (!in_array($curTyp, $allowedTypes, true)) {
          throw new RuntimeException('Ungültiger Sitzungstyp.');
        }

        $curPeriode = trim((string)($_POST['curated_periodenbezug'] ?? ''));
        $curPeriode = ($curPeriode === '') ? null : $curPeriode;

        $pdo->beginTransaction();

        $pdo->prepare("
          INSERT IGNORE INTO document_core (document_id, extracted_json)
          VALUES (?, JSON_OBJECT())
        ")->execute([$docId]);

        $pdo->prepare("
          UPDATE document_core
          SET curated_sitzungsdatum = ?,
              curated_uhrzeit_start = ?,
              curated_ort = ?,
              curated_sitzungstyp = ?,
              curated_periodenbezug = ?,
              curated_niederschrift_nr = ?
          WHERE document_id = ?
        ")->execute([$curDate, $curTime, $curOrt, $curTyp, $curPeriode, $curNr, $docId]);

        set_task_status($pdo, $docId, 'curation_core', 60, 'fertig');
        log_doc($pdo, $docId, 'success', 'curation_core', 'Core Kuration gespeichert', $actor);

        $pdo->commit();

        $success = 'Gespeichert.';

        if ($action === 'save_next') {
          release_lock($pdo, $docId, $userId);
          $url = 'review_attendance.php?id=' . $docId;
          if ($gremiumId > 0) $url .= '&gremium_id=' . (int)$gremiumId;
          header('Location: ' . $url);
          exit;
        }
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();

        try {
          set_task_status($pdo, $docId, 'curation_core', 60, 'fehlgeschlagen');
          log_doc($pdo, $docId, 'error', 'curation_core', 'Speichern fehlgeschlagen: ' . $e->getMessage(), $actor);
        } catch (Throwable $ignored) {
        }

        $errors[] = $e->getMessage();
      }
    }
  }
}

$stmt = $pdo->prepare("
  SELECT
    d.id,
    d.gremium_id,
    d.original_filename,
    g.name AS gremium_name,
    c.extracted_json,
    c.curated_sitzungsdatum,
    c.curated_uhrzeit_start,
    c.curated_ort,
    c.curated_sitzungstyp,
    c.curated_periodenbezug,
    c.curated_niederschrift_nr
  FROM documents d
  JOIN gremien g ON g.id = d.gremium_id
  LEFT JOIN document_core c ON c.document_id = d.id
  WHERE d.id = ?
    AND d.deleted_at IS NULL
  LIMIT 1
");
$stmt->execute([$docId]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
  ui_header('Core Kuration', 'queue');
  ?>
  <div class="ui-box">
    <h2>Dokument nicht gefunden</h2>
    <a class="ui-link" href="queue.php<?= $gremiumId > 0 ? '?gremium_id=' . (int)$gremiumId : '' ?>">Zur Queue</a>
  </div>
  <?php
  ui_footer();
  exit;
}

if ($gremiumId <= 0) {
  $gremiumId = (int)($doc['gremium_id'] ?? 0);
}

$exJson = json_assoc((string)($doc['extracted_json'] ?? ''));
$ex = extracted_core_from_json($exJson);

$prefDate = (string)($doc['curated_sitzungsdatum'] ?? '');
if ($prefDate === '') $prefDate = (string)($ex['datum'] ?? '');

$prefTime = (string)($doc['curated_uhrzeit_start'] ?? '');
if ($prefTime === '') $prefTime = (string)($ex['uhrzeit'] ?? '');
if ($prefTime !== '' && preg_match('/^\d{2}:\d{2}:\d{2}$/', $prefTime)) $prefTime = substr($prefTime, 0, 5);

$prefOrt = (string)($doc['curated_ort'] ?? '');
if ($prefOrt === '') $prefOrt = (string)($ex['ort'] ?? '');

$prefTyp = (string)($doc['curated_sitzungstyp'] ?? '');
if ($prefTyp === '') $prefTyp = 'regulaer';

$prefPer = (string)($doc['curated_periodenbezug'] ?? '');

$prefNr = (string)($doc['curated_niederschrift_nr'] ?? '');
if ($prefNr === '') $prefNr = (string)($ex['niederschrift_nr'] ?? '');

ui_header('Core Kuration', 'queue');
?>

<div class="ui-box">
  <h2>Dokument</h2>
  <div><strong>#<?= (int)$doc['id'] ?></strong></div>
  <div class="ui-muted"><?= ui_h((string)$doc['gremium_name']) ?>, <?= ui_h((string)$doc['original_filename']) ?></div>

  <div class="ui-actions" style="margin-top:0.8rem;">
    <a class="ui-link" href="download.php?id=<?= (int)$doc['id'] ?>" target="_blank" rel="noopener">PDF öffnen</a>
    <a class="ui-link" href="queue.php<?= $gremiumId > 0 ? '?gremium_id=' . (int)$gremiumId : '' ?>">Zur Queue</a>
    <a class="ui-link" href="logs.php<?= $gremiumId > 0 ? '?gremium_id=' . (int)$gremiumId . '&document_id=' . (int)$doc['id'] : '?document_id=' . (int)$doc['id'] ?>">Logs</a>
  </div>

  <?php if (!$hasLock): ?>
    <div class="ui-err" style="margin-top:0.9rem;">
      Dieses Dokument wird gerade von <?= ui_h((string)($lockInfo['locked_by_name'] ?? 'jemand anderem')) ?> bearbeitet.
    </div>

    <form method="post" action="api/unlock.php" class="ui-actions" style="margin-top:0.7rem;">
      <input type="hidden" name="document_id" value="<?= (int)$doc['id'] ?>">
      <input type="hidden" name="back" value="<?= ui_h('review_core.php?id=' . (int)$doc['id'] . ($gremiumId > 0 ? '&gremium_id=' . (int)$gremiumId : '')) ?>">
      <button class="ui-btn" type="submit">Entsperren</button>
      <span class="ui-muted">Nur nutzen, wenn du sicher bist, dass niemand mehr daran arbeitet.</span>
    </form>
  <?php endif; ?>
</div>

<div class="ui-box">
  <h2>Extraktion aus JSON</h2>

  <div class="ui-table-wrap">
    <table class="ui-table">
      <tbody>
        <tr><th style="width:220px;">Niederschrift Nr</th><td><?= ui_h((string)($ex['niederschrift_nr'] ?? '')) ?></td></tr>
        <tr><th>Datum</th><td><?= ui_h((string)($ex['datum'] ?? '')) ?></td></tr>
        <tr><th>Uhrzeit</th><td><?= ui_h((string)($ex['uhrzeit'] ?? '')) ?></td></tr>
        <tr><th>Ort</th><td><?= ui_h((string)($ex['ort'] ?? '')) ?></td></tr>
      </tbody>
    </table>
  </div>

  <div class="ui-muted" style="margin-top:0.7rem;">
    Hinweis: Schlüssel erwartet sind ort, datum, uhrzeit, niederschrift_nr.
  </div>
</div>

<div class="ui-box">
  <h2>Kuration</h2>

  <?php if ($success): ?>
    <div class="ui-ok" style="margin-top:0.6rem;"><?= ui_h($success) ?></div>
  <?php endif; ?>

  <?php foreach ($errors as $err): ?>
    <div class="ui-err" style="margin-top:0.6rem;"><?= ui_h($err) ?></div>
  <?php endforeach; ?>

  <form method="post" style="margin-top:0.8rem;">
    <input type="hidden" name="document_id" value="<?= (int)$doc['id'] ?>">
    <input type="hidden" name="gremium_id" value="<?= (int)$gremiumId ?>">

    <div class="ui-actions" style="gap:1rem;flex-wrap:wrap;">
      <div style="flex:1;min-width:240px;">
        <label class="ui-label" for="curated_sitzungsdatum">Sitzungsdatum</label>
        <input class="ui-input" type="date" id="curated_sitzungsdatum" name="curated_sitzungsdatum" value="<?= ui_h((string)$prefDate) ?>" <?= $hasLock ? '' : 'disabled' ?>>
      </div>

      <div style="flex:1;min-width:220px;">
        <label class="ui-label" for="curated_uhrzeit_start">Uhrzeit</label>
        <input class="ui-input" type="text" id="curated_uhrzeit_start" name="curated_uhrzeit_start" placeholder="HH:MM" value="<?= ui_h((string)$prefTime) ?>" <?= $hasLock ? '' : 'disabled' ?>>
      </div>

      <div style="flex:1;min-width:220px;">
        <label class="ui-label" for="curated_niederschrift_nr">Niederschrift Nr</label>
        <input class="ui-input" type="text" id="curated_niederschrift_nr" name="curated_niederschrift_nr" value="<?= ui_h((string)$prefNr) ?>" <?= $hasLock ? '' : 'disabled' ?>>
      </div>

      <div style="flex:1;min-width:240px;">
        <label class="ui-label" for="curated_sitzungstyp">Sitzungstyp</label>
        <select class="ui-select" id="curated_sitzungstyp" name="curated_sitzungstyp" <?= $hasLock ? '' : 'disabled' ?>>
          <?php foreach (['regulaer' => 'regulaer', 'sonder' => 'sonder', 'dringlich' => 'dringlich'] as $k => $lbl): ?>
            <option value="<?= ui_h($k) ?>" <?= ($prefTyp === $k ? 'selected' : '') ?>><?= ui_h($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div style="margin-top:0.9rem;">
      <label class="ui-label" for="curated_ort">Ort</label>
      <input class="ui-input" type="text" id="curated_ort" name="curated_ort" value="<?= ui_h((string)$prefOrt) ?>" <?= $hasLock ? '' : 'disabled' ?>>
    </div>

    <div style="margin-top:0.9rem;">
      <label class="ui-label" for="curated_periodenbezug">Periodenbezug</label>
      <input class="ui-input" type="text" id="curated_periodenbezug" name="curated_periodenbezug" value="<?= ui_h((string)$prefPer) ?>" <?= $hasLock ? '' : 'disabled' ?>>
    </div>

    <div class="ui-actions" style="margin-top:1rem;flex-wrap:wrap;">
      <button class="ui-btn" type="submit" name="action" value="save" <?= $hasLock ? '' : 'disabled' ?>>
        Speichern
      </button>

      <button class="ui-btn" type="submit" name="action" value="save_next" <?= $hasLock ? '' : 'disabled' ?>>
        Speichern und weiter
      </button>

      <button class="ui-btn" type="submit" name="action" value="skip" <?= $hasLock ? '' : 'disabled' ?>>
        Überspringen
      </button>

      <span class="ui-muted">
        Nächstes offenes Dokument: <a class="ui-link" href="review_core.php<?= $gremiumId > 0 ? '?gremium_id=' . (int)$gremiumId : '' ?>">öffnen</a>
      </span>
    </div>
  </form>
</div>

<?php ui_footer(); ?>
