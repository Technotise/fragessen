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

function clamp_section(string $s): string
{
  $s = trim($s);
  return in_array($s, ['public', 'non_public', 'unknown'], true) ? $s : 'unknown';
}

/*
  TOP key Varianten
  10
  10.1
  22.A
  13A
  13.A.1
*/
function parse_top_key(string $raw): array
{
  $raw = trim($raw);

  $num = 0;
  $sub = null;
  $suffix = null;

  if ($raw === '') {
    return [$num, $suffix, $sub];
  }

  $m = [];
  if (preg_match('/^\s*(\d+)\s*$/u', $raw, $m)) {
    $num = (int)$m[1];
    return [$num, $suffix, $sub];
  }

  if (preg_match('/^\s*(\d+)\s*[\.]?\s*([A-Za-z])\s*$/u', $raw, $m)) {
    $num = (int)$m[1];
    $suffix = strtoupper($m[2]);
    return [$num, $suffix, $sub];
  }

  if (preg_match('/^\s*(\d+)\s*\.\s*(\d+)\s*$/u', $raw, $m)) {
    $num = (int)$m[1];
    $sub = (string)((int)$m[2]);
    return [$num, $suffix, $sub];
  }

  if (preg_match('/^\s*(\d+)\s*\.\s*([A-Za-z])\s*$/u', $raw, $m)) {
    $num = (int)$m[1];
    $suffix = strtoupper($m[2]);
    return [$num, $suffix, $sub];
  }

  if (preg_match('/^\s*(\d+)\s*\.\s*(\d+)\s*\.\s*(\d+)\s*$/u', $raw, $m)) {
    $num = (int)$m[1];
    $sub = (string)((int)$m[2] . '.' . (int)$m[3]);
    return [$num, $suffix, $sub];
  }

  if (preg_match('/^\s*(\d+)\s*(.*)$/u', $raw, $m)) {
    $num = (int)$m[1];
  }

  return [$num, $suffix, $sub];
}

function top_norm(int $num, ?string $suffix, ?string $sub): string
{
  $s = (string)$num;
  if ($suffix !== null && $suffix !== '') $s .= $suffix;
  if ($sub !== null && $sub !== '') $s .= '.' . $sub;
  return $s;
}

function log_event(PDO $pdo, int $docId, string $level, string $task, string $message, string $actor): void
{
  $level = in_array($level, ['info', 'warning', 'error', 'success'], true) ? $level : 'info';
  $pdo->prepare("
    INSERT INTO document_logs (document_id, level, task, message, actor)
    VALUES (?, ?, ?, ?, ?)
  ")->execute([$docId, $level, $task, $message, $actor]);
}

function set_task_status(PDO $pdo, int $docId, string $task, string $status): void
{
  $allowed = ['geplant', 'gestartet', 'fertig', 'fehlgeschlagen'];
  if (!in_array($status, $allowed, true)) $status = 'geplant';

  $pdo->prepare("
    UPDATE document_state
    SET status=?, updated_at=NOW()
    WHERE document_id=? AND task=?
    LIMIT 1
  ")->execute([$status, $docId, $task]);
}

function next_open_doc_agenda(PDO $pdo, int $gremiumId, int $excludeId = 0): int
{
  $params = [];
  $where = "d.deleted_at IS NULL";

  if ($gremiumId > 0) { $where .= " AND d.gremium_id = ?"; $params[] = $gremiumId; }
  if ($excludeId > 0) { $where .= " AND d.id <> ?"; $params[] = $excludeId; }

  $sql = "
    SELECT d.id
    FROM documents d
    JOIN document_state s
      ON s.document_id=d.id AND s.task='curation_agenda'
    WHERE $where
      AND s.status <> 'fertig'
      AND EXISTS (
        SELECT 1 FROM document_agenda da WHERE da.document_id=d.id
      )
    ORDER BY d.created_at ASC
    LIMIT 1
  ";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return (int)($row['id'] ?? 0);
}

function ensure_export_planned(PDO $pdo, int $docId): void
{
  $pdo->prepare("
    UPDATE document_state
    SET status='geplant', updated_at=NOW()
    WHERE document_id=? AND task='export'
      AND status <> 'fertig'
    LIMIT 1
  ")->execute([$docId]);
}

function decode_extracted_agenda_json($v): array
{
  if (is_string($v) && $v !== '') {
    $d = json_decode($v, true);
    if (is_array($d)) return $d;
  }
  if (is_array($v)) return $v;
  return [];
}

function make_extracted_json_payload(string $top, string $title, ?string $drucksache, string $section, int $pageStart, int $pageEnd): string
{
  $payload = [
    'top' => $top,
    'titel' => $title,
    'drucksache' => $drucksache,
    'section' => $section,
    'page_start' => $pageStart,
    'page_end' => $pageEnd,
  ];
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  return $json === false ? '{}' : $json;
}

$errors = [];
$success = null;

$docId = (int)($_GET['id'] ?? 0);
$gremiumId = (int)($_GET['gremium_id'] ?? 0);

$userId = get_session_user_id();
$userName = get_session_user_name();

if ($docId <= 0) {
  $docId = next_open_doc_agenda($pdo, $gremiumId, 0);
}

if ($docId <= 0) {
  ui_header('Agenda Kuration', 'queue');
  ?>
  <div class="ui-box">
    <h2>Keine offenen Dokumente</h2>
    <div class="ui-muted">Es gibt aktuell keine Dokumente, die eine Agenda Kuration benötigen.</div>
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

register_shutdown_function(function () use ($pdo, $docId, $userId, $hasLock): void {
  if (!$hasLock) return;
  try {
    if ($docId > 0 && $userId > 0 && has_own_lock($pdo, $docId, $userId)) {
      release_lock($pdo, $docId, $userId);
    }
  } catch (Throwable $e) {
  }
});

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
        $nextId = next_open_doc_agenda($pdo, $gremiumId, $docId);
        if ($nextId > 0) {
          $url = 'review_agenda.php?id=' . $nextId;
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

        set_task_status($pdo, $docId, 'curation_agenda', 'gestartet');

        $posArr = $_POST['pos'] ?? [];
        $topKeyArr = $_POST['top_key_raw'] ?? [];
        $titleArr = $_POST['title'] ?? [];
        $dsArr = $_POST['ds_nr_raw'] ?? [];
        $sectionArr = $_POST['section'] ?? [];
        $pStartArr = $_POST['page_start'] ?? [];
        $pEndArr = $_POST['page_end'] ?? [];
        $deleteArr = $_POST['delete'] ?? [];

        $deleteSet = [];
        foreach ((array)$deleteArr as $k) $deleteSet[(string)$k] = true;

        $pdo->prepare("DELETE FROM document_agenda WHERE document_id=?")->execute([$docId]);

        $ins = $pdo->prepare("
          INSERT INTO document_agenda
            (document_id, row_index, extracted_json,
             top_key_curated, title_curated, drucksache_curated, section_curated,
             top_num, top_suffix, top_sub, top_norm,
             extracted_flag, curated_flag, needs_review)
          VALUES
            (?, ?, ?,
             ?, ?, ?, ?,
             ?, ?, ?, ?,
             1, 1, 0)
        ");

        $keys = array_unique(array_merge(
          array_keys((array)$posArr),
          array_keys((array)$topKeyArr),
          array_keys((array)$titleArr),
          array_keys((array)$dsArr),
          array_keys((array)$sectionArr),
          array_keys((array)$pStartArr),
          array_keys((array)$pEndArr)
        ));

        $rowsInserted = 0;

        foreach ($keys as $k) {
          $k = (string)$k;
          if (isset($deleteSet[$k])) continue;

          $pos = (int)($posArr[$k] ?? 0);
          if ($pos <= 0) throw new RuntimeException('Position muss eine positive Zahl sein.');

          $topKeyRaw = trim((string)($topKeyArr[$k] ?? ''));
          if ($topKeyRaw === '') $topKeyRaw = (string)$pos;

          [$topNum, $topSuffix, $topSub] = parse_top_key($topKeyRaw);
          if ($topNum <= 0) $topNum = $pos;

          $title = trim((string)($titleArr[$k] ?? ''));
          if ($title === '') throw new RuntimeException('Titel darf nicht leer sein.');

          $dsNr = trim((string)($dsArr[$k] ?? ''));
          $dsNr = ($dsNr === '') ? null : $dsNr;

          $section = clamp_section((string)($sectionArr[$k] ?? 'unknown'));

          $pageStart = (int)($pStartArr[$k] ?? 1);
          $pageEnd = (int)($pEndArr[$k] ?? $pageStart);
          if ($pageStart <= 0) $pageStart = 1;
          if ($pageEnd <= 0) $pageEnd = $pageStart;
          if ($pageEnd < $pageStart) $pageEnd = $pageStart;

          $topNorm = top_norm($topNum, $topSuffix, $topSub);

          $extractedJson = make_extracted_json_payload($topKeyRaw, $title, $dsNr, $section, $pageStart, $pageEnd);

          $ins->execute([
            $docId,
            $pos,
            $extractedJson,
            $topKeyRaw,
            $title,
            $dsNr,
            $section,
            $topNum,
            $topSuffix,
            $topSub,
            $topNorm,
          ]);

          $rowsInserted++;
        }

        set_task_status($pdo, $docId, 'curation_agenda', 'fertig');
        ensure_export_planned($pdo, $docId);

        log_event($pdo, $docId, 'success', 'curation_agenda', 'Agenda gespeichert. Items: ' . $rowsInserted, $userName);

        $pdo->commit();
        $success = 'Agenda gespeichert. Items: ' . $rowsInserted;

        if ($action === 'save_next') {
          release_lock($pdo, $docId, $userId);

          $url = 'queue.php';
          if ($gremiumId > 0) $url .= '?gremium_id=' . (int)$gremiumId;
          header('Location: ' . $url);
          exit;
        }
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        set_task_status($pdo, $docId, 'curation_agenda', 'fehlgeschlagen');
        log_event($pdo, $docId, 'error', 'curation_agenda', 'Fehler: ' . $e->getMessage(), $userName);
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
  ui_header('Agenda Kuration', 'queue');
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
    id,
    row_index,
    extracted_json,
    top_key_curated,
    title_curated,
    drucksache_curated,
    section_curated,
    top_num,
    top_suffix,
    top_sub,
    top_norm,
    extracted_flag,
    curated_flag,
    needs_review
  FROM document_agenda
  WHERE document_id=?
  ORDER BY row_index ASC, id ASC
");
$listStmt->execute([$docId]);
$list = $listStmt->fetchAll(PDO::FETCH_ASSOC);

if (!$list) {
  $errors[] = 'Keine document_agenda Zeilen vorhanden. Erst extract_agenda laufen lassen.';
}

$allowedSections = [
  'public' => 'öffentlich',
  'non_public' => 'nicht öffentlich',
  'unknown' => 'unbekannt',
];

ui_header('Agenda Kuration', 'queue');
?>

<div class="ui-box">
  <h2>Dokument</h2>
  <div><strong>#<?= (int)$doc['id'] ?></strong></div>
  <div class="ui-muted"><?= ui_h((string)$doc['gremium_name']) ?>, <?= ui_h((string)$doc['original_filename']) ?></div>

  <div class="ui-actions" style="margin-top:0.8rem;flex-wrap:wrap;">
    <a class="ui-link" href="download.php?id=<?= (int)$docId ?>" target="_blank" rel="noopener">PDF öffnen</a>
    <a class="ui-link" href="review_attendance.php?id=<?= (int)$docId ?><?= $gremiumId > 0 ? '&gremium_id=' . (int)$gremiumId : '' ?>">Zurück zu Attendance</a>
    <a class="ui-link" href="queue.php<?= $gremiumId > 0 ? '?gremium_id=' . (int)$gremiumId : '' ?>">Zur Queue</a>
    <a class="ui-link" href="logs.php<?= $gremiumId > 0 ? '?gremium_id=' . (int)$gremiumId . '&document_id=' . (int)$docId : '?document_id=' . (int)$docId ?>">Logs</a>
  </div>

  <?php if (!$hasLock): ?>
    <div class="ui-err" style="margin-top:0.9rem;">
      Dieses Dokument wird gerade von <?= ui_h((string)($lockInfo['locked_by_name'] ?? 'jemand anderem')) ?> bearbeitet.
    </div>

    <form method="post" action="api/unlock.php" class="ui-actions" style="margin-top:0.7rem;flex-wrap:wrap;">
      <input type="hidden" name="document_id" value="<?= (int)$docId ?>">
      <input type="hidden" name="back" value="<?= ui_h('review_agenda.php?id=' . (int)$docId . ($gremiumId > 0 ? '&gremium_id=' . (int)$gremiumId : '')) ?>">
      <button class="ui-btn" type="submit">Entsperren</button>
      <span class="ui-muted">Nur nutzen, wenn du sicher bist, dass niemand mehr daran arbeitet.</span>
    </form>
  <?php endif; ?>
</div>

<div class="ui-box">
  <h2>Agenda</h2>

  <?php foreach ($errors as $e): ?>
    <div class="ui-err" style="margin-top:0.6rem;"><?= ui_h($e) ?></div>
  <?php endforeach; ?>

  <?php if ($success): ?>
    <div class="ui-ok" style="margin-top:0.6rem;"><?= ui_h($success) ?></div>
  <?php endif; ?>

  <form method="post" style="margin-top:0.9rem;">
    <input type="hidden" name="document_id" value="<?= (int)$docId ?>">
    <input type="hidden" name="gremium_id" value="<?= (int)$gremiumId ?>">

    <div class="ui-actions" style="margin-top:0.2rem;flex-wrap:wrap;">
      <?php if ($hasLock): ?>
        <button class="ui-btn" type="button" id="btnAddAgendaRow">Punkt hinzufügen</button>
        <span class="ui-muted">Fügt am Ende hinzu. Für zwischen zwei Positionen nutze das Plus neben der Position.</span>
      <?php else: ?>
        <span class="ui-muted">Ohne Lock sind Änderungen deaktiviert.</span>
      <?php endif; ?>
    </div>

    <div class="ui-table-wrap" style="margin-top:0.7rem;">
      <table class="ui-table">
        <thead>
          <tr>
            <th>Position</th>
            <th>TOP</th>
            <th>Titel</th>
            <th>Drucksache</th>
            <th>Teil</th>
            <th>Seiten</th>
            <th>Entfernen</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($list as $i => $it): ?>
            <?php
              $k = (string)$i;

              $pos = (int)($it['row_index'] ?? 0);

              $ex = decode_extracted_agenda_json($it['extracted_json'] ?? '');
              $exTop = trim((string)($ex['top'] ?? ''));
              $exTitle = trim((string)($ex['titel'] ?? ''));
              $exDs = (string)($ex['drucksache'] ?? '');
              $exSec = clamp_section((string)($ex['section'] ?? 'unknown'));
              $pStart = (int)($ex['page_start'] ?? 1);
              $pEnd = (int)($ex['page_end'] ?? $pStart);

              $top = trim((string)($it['top_key_curated'] ?? ''));
              $title = trim((string)($it['title_curated'] ?? ''));
              $ds = (string)($it['drucksache_curated'] ?? '');
              $sec = clamp_section((string)($it['section_curated'] ?? ''));

              if ($top === '') $top = ($exTop !== '' ? $exTop : (string)$pos);
              if ($title === '') $title = $exTitle;
              if ($ds === '') $ds = $exDs;
              if ($sec === 'unknown' && $exSec !== 'unknown') $sec = $exSec;

              if ($pStart <= 0) $pStart = 1;
              if ($pEnd <= 0) $pEnd = $pStart;
              if ($pEnd < $pStart) $pEnd = $pStart;
            ?>
            <tr>
              <td style="width:170px;">
                <div style="display:flex;gap:8px;align-items:center;">
                  <input class="ui-input" type="number" name="pos[<?= ui_h($k) ?>]" value="<?= (int)$pos ?>" min="1" step="1" <?= $hasLock ? '' : 'disabled' ?> style="width:110px;">
                  <?php if ($hasLock): ?>
                    <button type="button" class="ui-btn btnInsertBelow" style="padding:0.25rem 0.55rem;min-width:2.2rem;" title="Danach einfügen">+</button>
                  <?php endif; ?>
                </div>
              </td>

              <td style="width:170px;">
                <input class="ui-input" type="text" name="top_key_raw[<?= ui_h($k) ?>]" value="<?= ui_h($top) ?>" <?= $hasLock ? '' : 'disabled' ?>>
                <div class="ui-muted" style="margin-top:0.3rem;">
                  Nummer <?= (int)($it['top_num'] ?? 0) ?>
                  <?php if (!empty($it['top_suffix'])): ?> · Suffix <?= ui_h((string)$it['top_suffix']) ?><?php endif; ?>
                  <?php if (!empty($it['top_sub'])): ?> · Sub <?= ui_h((string)$it['top_sub']) ?><?php endif; ?>
                  <?php if (!empty($it['top_norm'])): ?> · Norm <?= ui_h((string)$it['top_norm']) ?><?php endif; ?>
                </div>
              </td>

              <td style="min-width:420px;">
                <input class="ui-input"
                       type="text"
                       name="title[<?= ui_h($k) ?>]"
                       value="<?= ui_h($title) ?>"
                       <?= $hasLock ? '' : 'disabled' ?>>
              </td>

              <td style="width:150px;">
                <input class="ui-input"
                       type="text"
                       name="ds_nr_raw[<?= ui_h($k) ?>]"
                       value="<?= ui_h($ds) ?>"
                       placeholder="1543/2025"
                       <?= $hasLock ? '' : 'disabled' ?>>
              </td>

              <td style="width:170px;">
                <select class="ui-select" name="section[<?= ui_h($k) ?>]" <?= $hasLock ? '' : 'disabled' ?>>
                  <?php foreach ($allowedSections as $val => $lbl): ?>
                    <option value="<?= ui_h($val) ?>" <?= ($sec === $val ? 'selected' : '') ?>><?= ui_h($lbl) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>

              <td style="width:160px;">
                <div style="display:flex;gap:8px;align-items:center;">
                  <input class="ui-input" style="width:70px;" type="number" name="page_start[<?= ui_h($k) ?>]" value="<?= (int)$pStart ?>" min="1" step="1" <?= $hasLock ? '' : 'disabled' ?>>
                  <span class="ui-muted">bis</span>
                  <input class="ui-input" style="width:70px;" type="number" name="page_end[<?= ui_h($k) ?>]" value="<?= (int)$pEnd ?>" min="1" step="1" <?= $hasLock ? '' : 'disabled' ?>>
                </div>
              </td>

              <td style="width:120px;">
                <label class="ui-muted">
                  <input type="checkbox" name="delete[]" value="<?= ui_h($k) ?>" <?= $hasLock ? '' : 'disabled' ?>>
                  entfernen
                </label>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <template id="tplAgendaRow">
      <tr data-rowkey="__KEY__">
        <td style="width:170px;">
          <div style="display:flex;gap:8px;align-items:center;">
            <input class="ui-input" type="number" name="pos[__KEY__]" value="__POS__" min="1" step="1" style="width:110px;">
            <button type="button" class="ui-btn btnInsertBelow" style="padding:0.25rem 0.55rem;min-width:2.2rem;" title="Danach einfügen">+</button>
          </div>
        </td>

        <td style="width:170px;">
          <input class="ui-input" type="text" name="top_key_raw[__KEY__]" value="">
          <div class="ui-muted" style="margin-top:0.3rem;">Nummer wird beim Speichern aus TOP oder Position abgeleitet</div>
        </td>

        <td style="min-width:420px;">
          <input class="ui-input" type="text" name="title[__KEY__]" value="">
        </td>

        <td style="width:150px;">
          <input class="ui-input" type="text" name="ds_nr_raw[__KEY__]" value="" placeholder="1543/2025">
        </td>

        <td style="width:170px;">
          <select class="ui-select" name="section[__KEY__]">
            <option value="public">öffentlich</option>
            <option value="non_public">nicht öffentlich</option>
            <option value="unknown" selected>unbekannt</option>
          </select>
        </td>

        <td style="width:160px;">
          <div style="display:flex;gap:8px;align-items:center;">
            <input class="ui-input" style="width:70px;" type="number" name="page_start[__KEY__]" value="1" min="1" step="1">
            <span class="ui-muted">bis</span>
            <input class="ui-input" style="width:70px;" type="number" name="page_end[__KEY__]" value="1" min="1" step="1">
          </div>
        </td>

        <td style="width:120px;">
          <label class="ui-muted">
            <input type="checkbox" name="delete[]" value="__KEY__">
            entfernen
          </label>
        </td>
      </tr>
    </template>

    <div class="ui-actions" style="margin-top:1rem;flex-wrap:wrap;">
      <button class="ui-btn" type="submit" name="action" value="save" <?= $hasLock ? '' : 'disabled' ?>>Speichern</button>
      <button class="ui-btn" type="submit" name="action" value="save_next" <?= $hasLock ? '' : 'disabled' ?>>Speichern und zur Queue</button>
      <button class="ui-btn" type="submit" name="action" value="skip" <?= $hasLock ? '' : 'disabled' ?>>Überspringen</button>

      <span class="ui-muted">
        Nächstes offenes Dokument: <a class="ui-link" href="review_agenda.php<?= $gremiumId > 0 ? '?gremium_id=' . (int)$gremiumId : '' ?>">öffnen</a>
      </span>
    </div>
  </form>
</div>

<script>
(function () {
  const hasLock = <?= $hasLock ? 'true' : 'false' ?>;
  if (!hasLock) return;

  const tbody = document.querySelector('.ui-table tbody');
  const tpl = document.getElementById('tplAgendaRow');
  const btnAddEnd = document.getElementById('btnAddAgendaRow');

  if (!tbody || !tpl) return;

  let seq = 0;

  function nextPosGuess() {
    const inputs = tbody.querySelectorAll('input[name^="pos["]');
    let max = 0;
    inputs.forEach(i => {
      const v = parseInt(i.value || '0', 10);
      if (v > max) max = v;
    });
    return max + 1;
  }

  function bumpPositions(fromPos) {
    const inputs = tbody.querySelectorAll('input[name^="pos["]');
    inputs.forEach(i => {
      const v = parseInt(i.value || '0', 10);
      if (v >= fromPos) i.value = v + 1;
    });
  }

  function createRowHtml(key, pos) {
    return tpl.innerHTML
      .replaceAll('__KEY__', key)
      .replaceAll('__POS__', String(pos));
  }

  function appendRowAtEnd() {
    seq++;
    const key = 'new_' + Date.now() + '_' + seq;
    const pos = nextPosGuess();

    const tmp = document.createElement('tbody');
    tmp.innerHTML = createRowHtml(key, pos).trim();
    const row = tmp.firstElementChild;

    tbody.appendChild(row);

    const titleInput = row.querySelector('input[name="title[' + key + ']"]');
    if (titleInput) titleInput.focus();
  }

  function insertRowAfter(tr) {
    const posInput = tr.querySelector('input[name^="pos["]');
    const basePos = parseInt(posInput && posInput.value ? posInput.value : '0', 10);
    if (basePos <= 0) return;

    const insertPos = basePos + 1;

    seq++;
    const key = 'new_' + Date.now() + '_' + seq;

    bumpPositions(insertPos);

    const tmp = document.createElement('tbody');
    tmp.innerHTML = createRowHtml(key, insertPos).trim();
    const row = tmp.firstElementChild;

    tr.insertAdjacentElement('afterend', row);

    const titleInput = row.querySelector('input[name="title[' + key + ']"]');
    if (titleInput) titleInput.focus();
  }

  if (btnAddEnd) {
    btnAddEnd.addEventListener('click', function () {
      appendRowAtEnd();
    });
  }

  tbody.addEventListener('click', function (e) {
    const btn = e.target.closest('.btnInsertBelow');
    if (!btn) return;

    const tr = btn.closest('tr');
    if (!tr) return;

    insertRowAfter(tr);
  });
})();
</script>

<?php ui_footer(); ?>
