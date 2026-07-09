<?php
declare(strict_types=1);

require __DIR__ . '/../src/db.php';
require __DIR__ . '/../src/auth.php';

require_login();

$pdo = db();

$gremiumId = (int)($_GET['gremium_id'] ?? 0);

$params = [];
$where = "d.deleted_at IS NULL";
if ($gremiumId > 0) {
  $where .= " AND d.gremium_id = ?";
  $params[] = $gremiumId;
}

$sql = "
  SELECT
    d.id,
    COALESCE(d.extraction_retry_allowed, 0) AS extraction_retry_allowed,

    MAX(CASE WHEN ds.task='slice' THEN ds.status END) AS t_slice,
    MAX(CASE WHEN ds.task='get_json' THEN ds.status END) AS t_get_json,
    MAX(CASE WHEN ds.task='extract_core' THEN ds.status END) AS t_extract_core,
    MAX(CASE WHEN ds.task='extract_attendance' THEN ds.status END) AS t_extract_attendance,
    MAX(CASE WHEN ds.task='extract_agenda' THEN ds.status END) AS t_extract_agenda,

    MAX(CASE WHEN ds.task='curation_core' THEN ds.status END) AS t_curation_core,
    MAX(CASE WHEN ds.task='curation_attendance' THEN ds.status END) AS t_curation_attendance,
    MAX(CASE WHEN ds.task='curation_agenda' THEN ds.status END) AS t_curation_agenda,

    MAX(CASE WHEN ds.task='export' THEN ds.status END) AS t_export,

    MAX(CASE WHEN ds.status='gestartet' THEN 1 ELSE 0 END) AS any_started
  FROM documents d
  LEFT JOIN document_state ds ON ds.document_id = d.id
  WHERE $where
  GROUP BY d.id, d.extraction_retry_allowed
  ORDER BY d.id DESC
  LIMIT 400
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

function s(array $r, string $k): string {
  $v = (string)($r[$k] ?? '');
  return $v !== '' ? $v : 'geplant';
}

function all_done_extraction(array $r): bool {
  return s($r, 't_extract_core') === 'fertig'
    && s($r, 't_extract_attendance') === 'fertig'
    && s($r, 't_extract_agenda') === 'fertig';
}

function all_done_curation(array $r): bool {
  return s($r, 't_curation_core') === 'fertig'
    && s($r, 't_curation_attendance') === 'fertig'
    && s($r, 't_curation_agenda') === 'fertig';
}

function any_failed_automated(array $r): bool {
  $keys = ['t_slice','t_get_json','t_extract_core','t_extract_attendance','t_extract_agenda'];
  foreach ($keys as $k) {
    if (s($r, $k) === 'fehlgeschlagen') return true;
  }
  return false;
}

foreach ($rows as &$r) {
  $anyStarted = (int)($r['any_started'] ?? 0);
  $retryAllowed = (int)($r['extraction_retry_allowed'] ?? 0);

  $autoDone = (s($r, 't_slice') === 'fertig'
    && s($r, 't_get_json') === 'fertig'
    && all_done_extraction($r));

  $workerState = 'planned';
  if ($anyStarted === 1) $workerState = 'started';
  else if ($autoDone) $workerState = 'done';
  else if (any_failed_automated($r) && $retryAllowed === 0) $workerState = 'failed';

  $curState = 'not_possible';
  if (all_done_extraction($r)) $curState = all_done_curation($r) ? 'done' : 'pending';

  $exportState = 'not_possible';
  if (all_done_curation($r)) $exportState = (s($r, 't_export') === 'fertig') ? 'done' : 'pending';

  $r['worker_state'] = $workerState;
  $r['curation_state'] = $curState;
  $r['export_state'] = $exportState;
}
unset($r);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['rows' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
