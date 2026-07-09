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

function norm_name(string $s): string {
  $s = trim($s);

  if (class_exists('Normalizer')) {
    $s = Normalizer::normalize($s, Normalizer::FORM_C) ?? $s;
  }

  $s = mb_strtolower($s, 'UTF-8');
  $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
  $s = preg_replace('/[^\p{L}\p{N} ]/u', '', $s) ?? $s;
  return trim($s);
}

function json_get_str(array $arr, string $key): string {
  $v = $arr[$key] ?? '';
  if ($v === null) return '';
  if (is_string($v) || is_numeric($v)) return trim((string)$v);
  return '';
}

function json_get_first(array $arr, array $keys): string {
  foreach ($keys as $k) {
    $v = json_get_str($arr, (string)$k);
    if ($v !== '') return $v;
  }
  return '';
}

function clamp_role(string $role): string {
  $role = trim($role);
  $role = mb_strtolower($role, 'UTF-8');

  $map = [
    'vorsitz' => 'vorsitz',
    'mitglied' => 'mitglied',
    'mitglieder' => 'mitglied',
    'rat' => 'rat',
    'mitglied des rates' => 'rat',
    'mitglieder des rates' => 'rat',
    'verwaltung' => 'verwaltung',
    'schriftführer' => 'schriftfuehrung',
    'schriftfuehrer' => 'schriftfuehrung',
    'schriftfuehrerin' => 'schriftfuehrung',
    'schriftführerin' => 'schriftfuehrung',
    'gast' => 'gast',
    'gäste' => 'gast',
    'sonstige' => 'sonstige',
    'fehlt: entschuldigt' => 'fehlt_entschuldigt',
    'fehlt entschuldigt' => 'fehlt_entschuldigt',
    'entschuldigt' => 'fehlt_entschuldigt',
    'fehlt: unentschuldigt' => 'fehlt_unentschuldigt',
    'fehlt unentschuldigt' => 'fehlt_unentschuldigt',
    'unentschuldigt' => 'fehlt_unentschuldigt',
  ];

  if (isset($map[$role])) return $map[$role];

  $allowed = [
    'vorsitz',
    'mitglied',
    'rat',
    'verwaltung',
    'schriftfuehrung',
    'gast',
    'sonstige',
    'fehlt_entschuldigt',
    'fehlt_unentschuldigt',
  ];

  return in_array($role, $allowed, true) ? $role : 'sonstige';
}

function log_event(PDO $pdo, int $docId, string $level, string $task, string $message, string $actor): void {
  $level = in_array($level, ['info', 'warning', 'error', 'success'], true) ? $level : 'info';
  $pdo->prepare("
    INSERT INTO document_logs (document_id, level, task, message, actor)
    VALUES (?, ?, ?, ?, ?)
  ")->execute([$docId, $level, $task, $message, $actor]);
}

function set_task_status(PDO $pdo, int $docId, string $task, string $status): void {
  $allowed = ['geplant', 'gestartet', 'fertig', 'fehlgeschlagen'];
  if (!in_array($status, $allowed, true)) $status = 'geplant';

  $pdo->prepare("
    UPDATE document_state
    SET status=?, updated_at=NOW()
    WHERE document_id=? AND task=?
    LIMIT 1
  ")->execute([$status, $docId, $task]);
}

function next_open_doc_attendance(PDO $pdo, int $gremiumId, int $excludeId = 0): int {
  $params = [];
  $where = "d.deleted_at IS NULL";

  if ($gremiumId > 0) { $where .= " AND d.gremium_id = ?"; $params[] = $gremiumId; }
  if ($excludeId > 0) { $where .= " AND d.id <> ?"; $params[] = $excludeId; }

  $sql = "
    SELECT d.id
    FROM documents d
    JOIN document_state s
      ON s.document_id=d.id AND s.task='curation_attendance'
    WHERE $where
      AND s.status <> 'fertig'
      AND EXISTS (
        SELECT 1 FROM document_attendance a
        WHERE a.document_id=d.id
      )
    ORDER BY d.created_at ASC
    LIMIT 1
  ";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return (int)($row['id'] ?? 0);
}

function next_attendance_row_index(PDO $pdo, int $docId): int {
  $st = $pdo->prepare("SELECT COALESCE(MAX(row_index), 0) FROM document_attendance WHERE document_id=?");
  $st->execute([$docId]);
  return (int)($st->fetchColumn() ?: 0) + 1;
}

$errors = [];
$success = null;

$docId = (int)($_GET['id'] ?? 0);
$gremiumId = (int)($_GET['gremium_id'] ?? 0);

$userId = get_session_user_id();
$userName = get_session_user_name();

$roleLabels = [
  'vorsitz' => 'Vorsitz',
  'mitglied' => 'Mitglieder',
  'rat' => 'Mitglieder des Rates',
  'verwaltung' => 'Verwaltung',
  'schriftfuehrung' => 'Schriftführung',
  'gast' => 'Gäste',
  'sonstige' => 'Sonstige',
  'fehlt_entschuldigt' => 'Fehlt: entschuldigt',
  'fehlt_unentschuldigt' => 'Fehlt: unentschuldigt',
];
$allowedRoles = array_keys($roleLabels);

$allowedSalutations = ['Herr', 'Frau', 'Ratsherr', 'Ratsfrau'];

if ($docId <= 0) {
  $docId = next_open_doc_attendance($pdo, $gremiumId, 0);
}

if ($docId <= 0) {
  ui_header('Attendance Kuration', 'queue');
  ?>
  <div class="ui-box">
    <h2>Keine offenen Dokumente</h2>
    <div class="ui-muted">Es gibt aktuell keine Dokumente, die eine Attendance Kuration benötigen.</div>
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
  $action = (string)($_POST['action'] ?? 'save');

  if ($docId <= 0) {
    $errors[] = 'Ungültige Dokument ID.';
  } else {
    if (!has_own_lock($pdo, $docId, $userId)) {
      $errors[] = 'Dieses Dokument ist gesperrt. Bitte entsperren oder später erneut versuchen.';
      $hasLock = false;
    } else {
      refresh_lock($pdo, $docId, $userId);

      if ($action === 'skip') {
        release_lock($pdo, $docId, $userId);
        $nextId = next_open_doc_attendance($pdo, $gremiumId, $docId);
        if ($nextId > 0) {
          $url = 'review_attendance.php?id=' . $nextId;
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
        $pdo->beginTransaction();

        set_task_status($pdo, $docId, 'curation_attendance', 'gestartet');

        $roles = $_POST['role'] ?? [];
        $salutations = $_POST['salutation'] ?? [];
        $titles = $_POST['title'] ?? [];
        $lastNames = $_POST['last_name'] ?? [];
        $factions = $_POST['faction_raw'] ?? [];
        $freeTexts = $_POST['free_text'] ?? [];
        $delete = $_POST['delete'] ?? [];

        $deleteSet = [];
        foreach ((array)$delete as $rid) {
          $deleteSet[(string)$rid] = true;
        }

        $upd = $pdo->prepare("
          UPDATE document_attendance
          SET
            role_curated=?,
            salutation_curated=?,
            title_curated=?,
            last_name_curated=?,
            faction_raw_curated=?,
            free_text_curated=?,
            name_norm=?,
            base_norm=?,
            curated_flag=1,
            needs_review=0
          WHERE document_id=? AND row_index=?
        ");

        $rowsTouched = 0;

        foreach ((array)$roles as $rowIndexRaw => $roleRaw) {
          $rowIndex = (int)$rowIndexRaw;
          if ($rowIndex <= 0) continue;

          if (isset($deleteSet[(string)$rowIndex])) {
            $pdo->prepare("
              DELETE FROM document_attendance
              WHERE document_id=? AND row_index=?
            ")->execute([$docId, $rowIndex]);
            continue;
          }

          $role = clamp_role((string)$roleRaw);
          if (!in_array($role, $allowedRoles, true)) $role = 'sonstige';

          $sal = trim((string)($salutations[$rowIndexRaw] ?? ''));
          if ($sal !== '' && !in_array($sal, $allowedSalutations, true)) {
            throw new RuntimeException('Ungültige Anrede in Zeile ' . $rowIndex);
          }

          $title = trim((string)($titles[$rowIndexRaw] ?? ''));
          $last = trim((string)($lastNames[$rowIndexRaw] ?? ''));
          if ($last === '') {
            throw new RuntimeException('Nachname darf nicht leer sein in Zeile ' . $rowIndex);
          }

          $factionRaw = trim((string)($factions[$rowIndexRaw] ?? ''));
          $factionRaw = $factionRaw === '' ? null : $factionRaw;

          $freeText = trim((string)($freeTexts[$rowIndexRaw] ?? ''));
          $freeText = $freeText === '' ? null : $freeText;

          $display = trim(($sal !== '' ? $sal . ' ' : '') . ($title !== '' ? $title . ' ' : '') . $last);
          $nameNorm = norm_name($display);
          $baseNorm = norm_name($last);

          $upd->execute([
            $role,
            $sal !== '' ? $sal : null,
            $title !== '' ? $title : null,
            $last,
            $factionRaw,
            $freeText,
            $nameNorm !== '' ? $nameNorm : null,
            $baseNorm !== '' ? $baseNorm : null,
            $docId,
            $rowIndex
          ]);

          $rowsTouched++;
        }

        $addLast = trim((string)($_POST['add_last_name'] ?? ''));
        if ($addLast !== '') {
          $addRole = clamp_role((string)($_POST['add_role'] ?? 'sonstige'));
          if (!in_array($addRole, $allowedRoles, true)) $addRole = 'sonstige';

          $addSal = trim((string)($_POST['add_salutation'] ?? ''));
          if ($addSal !== '' && !in_array($addSal, $allowedSalutations, true)) {
            throw new RuntimeException('Ungültige Anrede bei neuer Zeile');
          }

          $addTitle = trim((string)($_POST['add_title'] ?? ''));

          $addFaction = trim((string)($_POST['add_faction_raw'] ?? ''));
          $addFaction = $addFaction === '' ? null : $addFaction;

          $addFree = trim((string)($_POST['add_free_text'] ?? ''));
          $addFree = $addFree === '' ? null : $addFree;

          $nextRow = next_attendance_row_index($pdo, $docId);

          $display = trim(($addSal !== '' ? $addSal . ' ' : '') . ($addTitle !== '' ? $addTitle . ' ' : '') . $addLast);
          $nameNorm = norm_name($display);
          $baseNorm = norm_name($addLast);

          $extractedJson = json_encode([
            'rolle' => $addRole,
            'anrede' => $addSal !== '' ? $addSal : null,
            'titel' => $addTitle !== '' ? $addTitle : null,
            'nachname' => $addLast,
            'fraktion_partei' => $addFaction,
            'freitext' => $addFree,
          ], JSON_UNESCAPED_UNICODE);

          $pdo->prepare("
            INSERT INTO document_attendance
              (document_id, row_index, extracted_json,
               role_curated, salutation_curated, title_curated, last_name_curated,
               faction_raw_curated, free_text_curated,
               name_norm, base_norm,
               extracted_flag, curated_flag, needs_review)
            VALUES
              (?, ?, CAST(? AS JSON),
               ?, ?, ?, ?,
               ?, ?,
               ?, ?,
               0, 1, 0)
          ")->execute([
            $docId, $nextRow, $extractedJson,
            $addRole,
            $addSal !== '' ? $addSal : null,
            $addTitle !== '' ? $addTitle : null,
            $addLast,
            $addFaction,
            $addFree,
            $nameNorm !== '' ? $nameNorm : null,
            $baseNorm !== '' ? $baseNorm : null,
          ]);
        }

        set_task_status($pdo, $docId, 'curation_attendance', 'fertig');
        log_event($pdo, $docId, 'success', 'curation_attendance', 'Attendance gespeichert', $userName);

        $pdo->commit();
        $success = 'Attendance gespeichert.';

        if ($action === 'save_next') {
          $url = 'review_agenda.php?id=' . $docId;
          if ($gremiumId > 0) $url .= '&gremium_id=' . (int)$gremiumId;
          header('Location: ' . $url);
          exit;
        }
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        set_task_status($pdo, $docId, 'curation_attendance', 'fehlgeschlagen');
        log_event($pdo, $docId, 'error', 'curation_attendance', 'Fehler: ' . $e->getMessage(), $userName);
        $errors[] = $e->getMessage();
      }
    }
  }
}

$docStmt = $pdo->prepare("
  SELECT d.id, d.gremium_id, d.original_filename, g.name AS gremium_name
  FROM documents d
  JOIN gremien g ON g.id=d.gremium_id
  WHERE d.id=? AND d.deleted_at IS NULL
  LIMIT 1
");
$docStmt->execute([$docId]);
$doc = $docStmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
  ui_header('Attendance Kuration', 'queue');
  ?>
  <div class="ui-box">
    <h2>Dokument nicht gefunden</h2>
    <a class="ui-link" href="queue.php<?= $gremiumId > 0 ? '?gremium_id=' . (int)$gremiumId : '' ?>">Zur Queue</a>
  </div>
  <?php
  ui_footer();
  exit;
}

if ($gremiumId <= 0) $gremiumId = (int)($doc['gremium_id'] ?? 0);

$listStmt = $pdo->prepare("
  SELECT
    row_index,
    extracted_json,
    role_curated,
    salutation_curated,
    title_curated,
    last_name_curated,
    faction_raw_curated,
    free_text_curated,
    curated_flag,
    needs_review
  FROM document_attendance
  WHERE document_id=?
  ORDER BY row_index ASC
");
$listStmt->execute([$docId]);
$list = $listStmt->fetchAll(PDO::FETCH_ASSOC);

ui_header('Attendance Kuration', 'queue');
?>

<div class="ui-box">
  <h2>Dokument</h2>
  <div><strong>#<?= (int)$doc['id'] ?></strong></div>
  <div class="ui-muted"><?= ui_h((string)$doc['gremium_name']) ?>, <?= ui_h((string)$doc['original_filename']) ?></div>

  <div class="ui-actions" style="margin-top:0.8rem;flex-wrap:wrap;">
    <a class="ui-link" href="download.php?id=<?= (int)$docId ?>" target="_blank" rel="noopener">PDF öffnen</a>
    <a class="ui-link" href="review_core.php?id=<?= (int)$docId ?><?= $gremiumId > 0 ? '&gremium_id=' . (int)$gremiumId : '' ?>">Zurück zu Core</a>
    <a class="ui-link" href="queue.php<?= $gremiumId > 0 ? '?gremium_id=' . (int)$gremiumId : '' ?>">Zur Queue</a>
    <a class="ui-link" href="logs.php<?= $gremiumId > 0 ? '?gremium_id=' . (int)$gremiumId . '&document_id=' . (int)$docId : '?document_id=' . (int)$docId ?>">Logs</a>
  </div>

  <?php if (!$hasLock): ?>
    <div class="ui-err" style="margin-top:0.9rem;">
      Dieses Dokument wird gerade von <?= ui_h((string)($lockInfo['locked_by_name'] ?? 'jemand anderem')) ?> bearbeitet.
    </div>

    <form method="post" action="api/unlock.php" class="ui-actions" style="margin-top:0.7rem;flex-wrap:wrap;">
      <input type="hidden" name="document_id" value="<?= (int)$docId ?>">
      <input type="hidden" name="back" value="<?= ui_h('review_attendance.php?id=' . (int)$docId . ($gremiumId > 0 ? '&gremium_id=' . (int)$gremiumId : '')) ?>">
      <button class="ui-btn" type="submit">Entsperren</button>
      <span class="ui-muted">Nur nutzen, wenn du sicher bist, dass niemand mehr daran arbeitet.</span>
    </form>
  <?php endif; ?>
</div>

<div class="ui-box">
  <h2>Attendance</h2>

  <?php foreach ($errors as $e): ?>
    <div class="ui-err" style="margin-top:0.6rem;"><?= ui_h($e) ?></div>
  <?php endforeach; ?>

  <?php if ($success): ?>
    <div class="ui-ok" style="margin-top:0.6rem;"><?= ui_h($success) ?></div>
  <?php endif; ?>

  <form method="post" style="margin-top:0.9rem;">
    <input type="hidden" name="document_id" value="<?= (int)$docId ?>">
    <input type="hidden" name="gremium_id" value="<?= (int)$gremiumId ?>">

    <div class="ui-table-wrap">
      <table class="ui-table">
        <thead>
          <tr>
            <th style="width:80px;">Zeile</th>
            <th>Name</th>
            <th>Rolle</th>
            <th>Fraktion</th>
            <th>Freitext</th>
            <th>Entfernen</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($list as $it): ?>
            <?php
              $rowIndex = (int)$it['row_index'];
              $ex = [];
              $raw = (string)($it['extracted_json'] ?? '');
              if ($raw !== '') {
                $tmp = json_decode($raw, true);
                if (is_array($tmp)) $ex = $tmp;
              }

              $role = (string)($it['role_curated'] ?? '');
              if ($role === '') $role = json_get_first($ex, ['rolle', 'role']);
              $role = clamp_role($role);

              $sal = (string)($it['salutation_curated'] ?? '');
              if ($sal === '') $sal = json_get_first($ex, ['anrede', 'salutation']);

              $title = (string)($it['title_curated'] ?? '');
              if ($title === '') $title = json_get_first($ex, ['titel', 'title']);

              $last = (string)($it['last_name_curated'] ?? '');
              if ($last === '') $last = json_get_first($ex, ['nachname', 'lastname', 'last_name']);

              $faction = (string)($it['faction_raw_curated'] ?? '');
              if ($faction === '') $faction = json_get_first($ex, ['fraktion_partei', 'fraktion', 'partei', 'fraktion_party']);

              $free = (string)($it['free_text_curated'] ?? '');
              if ($free === '') $free = json_get_first($ex, ['freitext', 'free_text', 'freitext_rolle']);
            ?>
            <tr>
              <td class="ui-muted"><?= (int)$rowIndex ?></td>
				<td>
				  <div style="display:flex;gap:6px;align-items:center;">
					<select class="ui-select"
							name="salutation[<?= $rowIndex ?>]"
							style="width:80px;"
							<?= $hasLock ? '' : 'disabled' ?>>
					  <option value="">keine</option>
					  <?php foreach ($allowedSalutations as $a): ?>
						<option value="<?= ui_h($a) ?>" <?= ($sal === $a ? 'selected' : '') ?>>
						  <?= ui_h($a) ?>
						</option>
					  <?php endforeach; ?>
					</select>

					<input class="ui-input"
						   type="text"
						   name="title[<?= $rowIndex ?>]"
						   value="<?= ui_h($title) ?>"
						   placeholder="Titel"
						   style="width:90px;"
						   <?= $hasLock ? '' : 'disabled' ?>>

					<input class="ui-input"
						   type="text"
						   name="last_name[<?= $rowIndex ?>]"
						   value="<?= ui_h($last) ?>"
						   placeholder="Nachname"
						   style="width:150px;"
						   <?= $hasLock ? '' : 'disabled' ?>>
				  </div>
				</td>


              <td style="width:220px;">
                <select class="ui-select" name="role[<?= $rowIndex ?>]" <?= $hasLock ? '' : 'disabled' ?>>
                  <?php foreach ($allowedRoles as $r): ?>
                    <option value="<?= ui_h($r) ?>" <?= ($role === $r ? 'selected' : '') ?>>
                      <?= ui_h($roleLabels[$r] ?? $r) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </td>

              <td style="min-width:220px;">
                <input class="ui-input" type="text" name="faction_raw[<?= $rowIndex ?>]" value="<?= ui_h($faction) ?>" placeholder="Fraktion oder Partei" <?= $hasLock ? '' : 'disabled' ?>>
              </td>

              <td style="min-width:260px;">
                <input class="ui-input" type="text" name="free_text[<?= $rowIndex ?>]" value="<?= ui_h($free) ?>" placeholder="Freitext, z. B. FB 61" <?= $hasLock ? '' : 'disabled' ?>>
              </td>

              <td style="width:120px;">
                <label class="ui-muted">
                  <input type="checkbox" name="delete[]" value="<?= (int)$rowIndex ?>" <?= $hasLock ? '' : 'disabled' ?>>
                  entfernen
                </label>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="ui-box" style="margin-top:1rem;">
      <h2>Zeile hinzufügen</h2>

      <div class="ui-actions" style="gap:0.6rem;flex-wrap:wrap;align-items:flex-start;">
        <select class="ui-select" name="add_salutation" style="width:140px;" <?= $hasLock ? '' : 'disabled' ?>>
          <option value="">keine</option>
          <?php foreach ($allowedSalutations as $a): ?>
            <option value="<?= ui_h($a) ?>"><?= ui_h($a) ?></option>
          <?php endforeach; ?>
        </select>

        <input class="ui-input" type="text" name="add_title" placeholder="Titel" style="width:140px;" <?= $hasLock ? '' : 'disabled' ?>>

        <input class="ui-input" type="text" name="add_last_name" placeholder="Nachname" style="min-width:260px;flex:1;" <?= $hasLock ? '' : 'disabled' ?>>

        <select class="ui-select" name="add_role" style="min-width:220px;" <?= $hasLock ? '' : 'disabled' ?>>
          <?php foreach ($allowedRoles as $r): ?>
            <option value="<?= ui_h($r) ?>"><?= ui_h($roleLabels[$r] ?? $r) ?></option>
          <?php endforeach; ?>
        </select>

        <input class="ui-input" type="text" name="add_faction_raw" placeholder="Fraktion oder Partei optional" style="min-width:220px;" <?= $hasLock ? '' : 'disabled' ?>>
        <input class="ui-input" type="text" name="add_free_text" placeholder="Freitext optional" style="min-width:260px;flex:1;" <?= $hasLock ? '' : 'disabled' ?>>
      </div>
    </div>

    <div class="ui-actions" style="margin-top:1rem;flex-wrap:wrap;">
      <button class="ui-btn" type="submit" name="action" value="save" <?= $hasLock ? '' : 'disabled' ?>>Speichern</button>
      <button class="ui-btn" type="submit" name="action" value="save_next" <?= $hasLock ? '' : 'disabled' ?>>Speichern und weiter</button>
      <button class="ui-btn" type="submit" name="action" value="skip" <?= $hasLock ? '' : 'disabled' ?>>Überspringen</button>

      <span class="ui-muted">
        Nächstes offenes Dokument:
        <a class="ui-link" href="review_attendance.php<?= $gremiumId > 0 ? '?gremium_id=' . (int)$gremiumId : '' ?>">öffnen</a>
      </span>
    </div>
  </form>
</div>

<?php ui_footer(); ?>
