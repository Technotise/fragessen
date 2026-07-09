<?php
declare(strict_types=1);

require __DIR__ . '/src/db.php';
require __DIR__ . '/src/auth.php';
require __DIR__ . '/src/util.php';
require __DIR__ . '/ui/layout.php';

require_login();

$cfg = require __DIR__ . '/src/config.php';
$pdo = db();

$gremiumId = (int)($_GET['gremium_id'] ?? 0);
$docId = (int)($_GET['document_id'] ?? 0);
$task = (string)($_GET['task'] ?? '');

$allowedTask = ['', 'slice', 'get_json', 'extract_core', 'extract_attendance', 'extract_agenda', 'curation_core', 'curation_attendance', 'curation_agenda', 'export', 'upload'];
if (!in_array($task, $allowedTask, true)) $task = '';

$gremien = $pdo->query("
  SELECT id, name
  FROM gremien
  WHERE aktiv = 1
  ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

$where = "d.deleted_at IS NULL";
$params = [];

if ($gremiumId > 0) {
  $where .= " AND d.gremium_id = ?";
  $params[] = $gremiumId;
}

if ($docId > 0) {
  $where .= " AND l.document_id = ?";
  $params[] = $docId;
}

if ($task !== '') {
  $where .= " AND COALESCE(l.task,'') = ?";
  $params[] = $task;
}

$sql = "
  SELECT
    l.id,
    l.document_id,
    l.created_at,
    l.level,
    COALESCE(l.task,'') AS task,
    COALESCE(l.message,'') AS message,
    COALESCE(l.actor,'') AS actor,

    d.original_filename,
    g.name AS gremium_name
  FROM document_logs l
  JOIN documents d ON d.id = l.document_id
  JOIN gremien g ON g.id = d.gremium_id
  WHERE $where
  ORDER BY l.id DESC
  LIMIT 300
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

function q(array $extra = []): string {
  $base = $_GET;
  foreach ($extra as $k => $v) {
    if ($v === null) unset($base[$k]);
    else $base[$k] = (string)$v;
  }
  $qs = http_build_query($base);
  return $qs ? ('?' . $qs) : '';
}

function tail_lines(string $path, int $maxLines = 80): string {
  if (!is_file($path) || !is_readable($path)) return 'Datei nicht lesbar: ' . $path;
  $lines = @file($path, FILE_IGNORE_NEW_LINES);
  if (!is_array($lines)) return 'Konnte Datei nicht lesen: ' . $path;
  $slice = array_slice($lines, max(0, count($lines) - $maxLines));
  return implode("\n", $slice);
}

function list_log_files(string $dir, string $pattern): array {
  if ($dir === '' || !is_dir($dir)) return [];
  $files = glob(rtrim($dir, '/') . '/' . $pattern);
  if (!is_array($files)) return [];
  usort($files, function ($a, $b) { return (int)@filemtime($b) <=> (int)@filemtime($a); });
  return $files;
}

function level_label(string $lvl): array {
  $s = strtolower(trim($lvl));
  if ($s === 'success') return ['OK', 'ui-ok'];
  if ($s === 'error') return ['Fehler', 'ui-err'];
  if ($s === 'warning') return ['Warnung', 'ui-warn'];
  return ['Info', 'ui-muted'];
}

$logDir = rtrim((string)($cfg['logs']['dir'] ?? (__DIR__ . '/var/logs')), '/');

ui_header('Logs', 'logs', ['ui/logs.css']);
?>

<div class="ui-box">

  <div class="ui-muted" style="margin-top:0.25rem;">
    Events aus document_logs und optionale Tail Auszüge aus den Worker Logfiles.
  </div>

  <form method="get" class="ui-actions" style="align-items:flex-end;margin-top:0.75rem;">
    <div style="flex:1;min-width:260px;">
      <label class="ui-label" for="gremium_id">Gremium</label>
      <select class="ui-select" name="gremium_id" id="gremium_id">
        <option value="0">Alle Gremien</option>
        <?php foreach ($gremien as $g): ?>
          <option value="<?= (int)$g['id'] ?>" <?= ((int)$g['id'] === $gremiumId) ? 'selected' : '' ?>>
            <?= ui_h((string)$g['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div style="width:220px;">
      <label class="ui-label" for="document_id">Dokument ID</label>
      <input class="ui-input" type="number" name="document_id" id="document_id" value="<?= (int)$docId ?>" min="0">
    </div>

    <div style="width:220px;">
      <label class="ui-label" for="task">Task</label>
      <select class="ui-select" name="task" id="task">
        <option value="" <?= $task === '' ? 'selected' : '' ?>>Alle</option>
        <option value="upload" <?= $task === 'upload' ? 'selected' : '' ?>>Upload</option>
        <option value="slice" <?= $task === 'slice' ? 'selected' : '' ?>>Slice</option>
        <option value="get_json" <?= $task === 'get_json' ? 'selected' : '' ?>>JSON</option>
        <option value="extract_core" <?= $task === 'extract_core' ? 'selected' : '' ?>>Extract Core</option>
        <option value="extract_attendance" <?= $task === 'extract_attendance' ? 'selected' : '' ?>>Extract Attendance</option>
        <option value="extract_agenda" <?= $task === 'extract_agenda' ? 'selected' : '' ?>>Extract Agenda</option>
        <option value="curation_core" <?= $task === 'curation_core' ? 'selected' : '' ?>>Curation Core</option>
        <option value="curation_attendance" <?= $task === 'curation_attendance' ? 'selected' : '' ?>>Curation Attendance</option>
        <option value="curation_agenda" <?= $task === 'curation_agenda' ? 'selected' : '' ?>>Curation Agenda</option>
        <option value="export" <?= $task === 'export' ? 'selected' : '' ?>>Export</option>
      </select>
    </div>

    <button class="ui-btn" type="submit">Filter anwenden</button>
    <a class="ui-link" href="queue.php<?= q(['probe_php' => null]) ?>">Zur Queue</a>
  </form>

  <?php
    $docLogs = [];
    if ($docId > 0) {
      $docLogs = array_merge(
        list_log_files($logDir, 'slice_' . $docId . '_*.log'),
        list_log_files($logDir, 'mistral_' . $docId . '_*.log'),
        list_log_files($logDir, 'extract_core_' . $docId . '_*.log'),
        list_log_files($logDir, 'extract_attendance_' . $docId . '_*.log'),
        list_log_files($logDir, 'extract_agenda_' . $docId . '_*.log')
      );
      usort($docLogs, function ($a, $b) { return (int)@filemtime($b) <=> (int)@filemtime($a); });
    }
  ?>

  <?php if ($docId > 0): ?>
    <div class="ui-box" style="margin-top:1rem;">
      <h3 style="margin-top:0;">Worker Logs für Dokument <?= (int)$docId ?></h3>

      <?php if (!is_dir($logDir)): ?>
        <div class="ui-err">Logs Verzeichnis existiert nicht. Prüfe cfg logs dir oder Rechte.</div>
      <?php elseif (count($docLogs) === 0): ?>
        <div class="ui-muted">Keine Worker Logs für dieses Dokument gefunden.</div>
      <?php else: ?>
        <?php foreach (array_slice($docLogs, 0, 6) as $path): ?>
          <div class="ui-box" style="margin-top:0.8rem;">
            <div class="ui-muted">
              Datei <?= ui_h(basename($path)) ?>, Zeit <?= ui_h(date('Y-m-d H:i:s', (int)@filemtime($path))) ?>
            </div>
            <pre class="log-pre"><?= ui_h(tail_lines($path, 180)) ?></pre>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="ui-table-wrap" style="margin-top:0.9rem;">
    <table class="ui-table log-table" id="logTable">
      <thead>
        <tr>
          <th>Zeit</th>
          <th>Level</th>
          <th>Task</th>
          <th>Message</th>
          <th>Dokument</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
            [$label, $cls] = level_label((string)$r['level']);
            $rid = (int)($r['id'] ?? 0);
            $msg = (string)($r['message'] ?? '');
          ?>
          <tr class="log-main" data-row-id="<?= $rid ?>" tabindex="0" role="button" aria-expanded="false">
            <td><?= ui_h((string)$r['created_at']) ?></td>
            <td class="<?= ui_h($cls) ?>"><?= ui_h($label) ?></td>
            <td><?= ui_h((string)$r['task']) ?></td>
            <td class="ui-col-message"><?= ui_h($msg) ?></td>
            <td>
              <?= (int)$r['document_id'] ?><br>
              <span class="ui-muted"><?= ui_h((string)$r['original_filename']) ?></span>
            </td>
          </tr>

          <tr class="log-details" data-for-id="<?= $rid ?>">
            <td colspan="5">
              <div class="ui-muted">
                <strong>Gremium:</strong> <?= ui_h((string)$r['gremium_name']) ?>
                &nbsp;|&nbsp;
                <strong>Actor:</strong> <?= ui_h((string)$r['actor']) ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</div>

<script>
(function(){
  const table = document.getElementById('logTable');
  if (!table) return;

  function toggleRow(tr){
    const id = tr.getAttribute('data-row-id');
    if (!id) return;
    const details = table.querySelector('tr.log-details[data-for-id="' + id + '"]');
    if (!details) return;

    const isOpen = details.classList.contains('open');
    if (isOpen) {
      details.classList.remove('open');
      tr.setAttribute('aria-expanded', 'false');
    } else {
      details.classList.add('open');
      tr.setAttribute('aria-expanded', 'true');
    }
  }

  table.addEventListener('click', function(e){
    const tr = e.target.closest('tr.log-main');
    if (!tr) return;
    toggleRow(tr);
  });

  table.addEventListener('keydown', function(e){
    const tr = e.target.closest('tr.log-main');
    if (!tr) return;
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      toggleRow(tr);
    }
  });
})();
</script>

<?php ui_footer(); ?>
