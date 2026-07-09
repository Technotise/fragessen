<?php
declare(strict_types=1);

require __DIR__ . '/src/db.php';
require __DIR__ . '/src/auth.php';
require __DIR__ . '/src/util.php';
require __DIR__ . '/ui/layout.php';

require_login();

$pdo = db();

$gremiumId = (int)($_GET['gremium_id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$gremien = $pdo->query("
  SELECT id, name
  FROM gremien
  WHERE aktiv = 1
  ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

$params = [];
$where = "d.deleted_at IS NULL";
if ($gremiumId > 0) {
  $where .= " AND d.gremium_id = ?";
  $params[] = $gremiumId;
}

$stCount = $pdo->prepare("SELECT COUNT(*) FROM documents d WHERE $where");
$stCount->execute($params);
$total = (int)($stCount->fetchColumn() ?: 0);
$totalPages = max(1, (int)ceil($total / $perPage));

$sql = "
  SELECT
    d.id,
    d.created_at,
    d.original_filename,
    d.gremium_id,
    g.name AS gremium_name,
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
  JOIN gremien g ON g.id = d.gremium_id
  LEFT JOIN document_state ds ON ds.document_id = d.id
  WHERE $where
  GROUP BY d.id, d.created_at, d.original_filename, d.gremium_id, g.name, d.extraction_retry_allowed
  ORDER BY d.id DESC
  LIMIT $perPage OFFSET $offset
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

function any_planned_automated(array $r): bool {
  $keys = ['t_slice','t_get_json','t_extract_core','t_extract_attendance','t_extract_agenda'];
  foreach ($keys as $k) {
    if (s($r, $k) === 'geplant') return true;
  }
  return false;
}

function worker_state(array $r): string {
  $anyStarted = (int)($r['any_started'] ?? 0);
  $retryAllowed = (int)($r['extraction_retry_allowed'] ?? 0);

  $autoDone = s($r, 't_slice') === 'fertig'
    && s($r, 't_get_json') === 'fertig'
    && all_done_extraction($r);

  if ($anyStarted === 1) return 'started';
  if ($autoDone) return 'done';
  if (any_failed_automated($r) && $retryAllowed === 0) return 'failed';
  return 'planned';
}

function curation_state(array $r): string {
  if (!all_done_extraction($r)) return 'not_possible';
  return all_done_curation($r) ? 'done' : 'pending';
}

function export_state(array $r): string {
  if (!all_done_curation($r)) return 'not_possible';
  return (s($r, 't_export') === 'fertig') ? 'done' : 'pending';
}

function worker_label_from_state(string $s): string {
  if ($s === 'started') return 'Gestartet';
  if ($s === 'failed') return 'Fehlgeschlagen';
  if ($s === 'done') return 'Fertig';
  return 'Geplant';
}

function simple_label(string $s): string {
  if ($s === 'done') return 'Fertig';
  if ($s === 'pending') return 'Ausstehend';
  return 'Nicht möglich';
}

function pill_class(string $state): string {
  return ($state === 'done') ? 'ui-pill is-done' : 'ui-pill is-pending';
}

ui_header('Dokument Queue', 'queue', ['ui/queue.css']);
?>

<div class="ui-box">
  <div class="ui-muted" style="margin-top:0.25rem;">
    Verarbeitung läuft über Cron. Neustart setzt die Freigabe in documents.extraction_retry_allowed.
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

    <input type="hidden" name="page" value="1">
    <button class="ui-btn" type="submit">Filter anwenden</button>
  </form>

  <div class="q-list" id="qList">
    <?php foreach ($rows as $r): ?>
      <?php
        $id = (int)$r['id'];

        $wState = worker_state($r);
        $cState = curation_state($r);
        $eState = export_state($r);

        $canCurate = ($cState !== 'not_possible');
        $canExport = ($eState !== 'not_possible');

        $retryAllowed = (int)($r['extraction_retry_allowed'] ?? 0);
        $canRestart = ($retryAllowed === 0);
      ?>
      <div class="q-item" data-doc-id="<?= $id ?>">
        <div class="q-main">
          <div class="q-row1">
            <div class="q-id">#<?= $id ?></div>
            <div class="q-file"><?= ui_h((string)$r['original_filename']) ?></div>
            <div class="q-gremium"><?= ui_h((string)$r['gremium_name']) ?></div>
          </div>

          <div class="q-row2">
			<span class="<?= pill_class($wState) ?> js-worker" data-state="<?= ui_h($wState) ?>">
			  <?= 'Worker: ' . worker_label_from_state($wState) ?>
			</span>

			<span class="<?= pill_class($cState) ?> js-curation" data-state="<?= ui_h($cState) ?>">
			  <?= 'Kuration: ' . simple_label($cState) ?>
			</span>

			<span class="<?= pill_class($eState) ?> js-export" data-state="<?= ui_h($eState) ?>">
			  <?= 'Export: ' . simple_label($eState) ?>
			</span>
          </div>
        </div>

        <div class="q-actions">
          <form method="post" action="api/retry_extract_doc.php" class="js-retry" style="<?= $canRestart ? '' : 'opacity:.55;pointer-events:none;' ?>">
            <input type="hidden" name="document_id" value="<?= $id ?>">
            <input type="hidden" name="gremium_id" value="<?= $gremiumId ?>">
            <button class="ui-btn" type="submit">Worker Neustart</button>
          </form>

          <a class="ui-link js-curate"
             href="review_core.php?id=<?= $id ?><?= $gremiumId > 0 ? '&gremium_id=' . $gremiumId : '' ?>"
             style="<?= $canCurate ? '' : 'pointer-events:none;opacity:.45;' ?>"
             aria-disabled="<?= $canCurate ? 'false' : 'true' ?>">Kuration</a>

          <a class="ui-link js-export-link"
             href="export.php?id=<?= $id ?><?= $gremiumId > 0 ? '&gremium_id=' . $gremiumId : '' ?>"
             style="<?= $canExport ? '' : 'pointer-events:none;opacity:.45;' ?>"
             aria-disabled="<?= $canExport ? 'false' : 'true' ?>">Export</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="ui-actions" style="margin-top:1rem; justify-content:space-between;">
    <div class="ui-muted">
      <?= (int)$total ?> Dokumente, Seite <?= (int)$page ?> von <?= (int)$totalPages ?>
    </div>

    <div class="ui-actions-inline">
      <?php if ($page > 1): ?>
        <a class="ui-link" href="queue.php<?= q(['page' => $page - 1]) ?>">Zurück</a>
      <?php endif; ?>
      <?php if ($page < $totalPages): ?>
        <a class="ui-link" href="queue.php<?= q(['page' => $page + 1]) ?>">Weiter</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
(function () {
  const POLL_MS = 3000;
  const gremiumId = <?= (int)$gremiumId ?>;

  function s(v) {
    const x = String(v || '').trim();
    return x !== '' ? x : 'geplant';
  }

  function allDoneExtraction(r) {
    return s(r.t_extract_core) === 'fertig'
      && s(r.t_extract_attendance) === 'fertig'
      && s(r.t_extract_agenda) === 'fertig';
  }

  function allDoneCuration(r) {
    return s(r.t_curation_core) === 'fertig'
      && s(r.t_curation_attendance) === 'fertig'
      && s(r.t_curation_agenda) === 'fertig';
  }

  function anyFailedAutomated(r) {
    const keys = ['t_slice','t_get_json','t_extract_core','t_extract_attendance','t_extract_agenda'];
    for (const k of keys) {
      if (s(r[k]) === 'fehlgeschlagen') return true;
    }
    return false;
  }

  function computeStates(r) {
    const anyStarted = Number(r.any_started || 0);
    const retryAllowed = Number(r.extraction_retry_allowed || 0);

    const autoDone = (s(r.t_slice) === 'fertig' && s(r.t_get_json) === 'fertig' && allDoneExtraction(r));

    let workerState = 'planned';
    if (anyStarted === 1) workerState = 'started';
    else if (autoDone) workerState = 'done';
    else if (anyFailedAutomated(r) && retryAllowed === 0) workerState = 'failed';

    let curationState = 'not_possible';
    if (allDoneExtraction(r)) curationState = allDoneCuration(r) ? 'done' : 'pending';

    let exportState = 'not_possible';
    if (allDoneCuration(r)) exportState = (s(r.t_export) === 'fertig') ? 'done' : 'pending';

    return { workerState, curationState, exportState, canRestart: (retryAllowed === 0) };
  }

  function workerLabel(x){
    if (x === 'started') return 'Gestartet';
    if (x === 'failed') return 'Fehlgeschlagen';
    if (x === 'done') return 'Fertig';
    return 'Geplant';
  }
  function simpleLabel(x){
    if (x === 'done') return 'Fertig';
    if (x === 'pending') return 'Ausstehend';
    return 'Nicht möglich';
  }
  function pillClass(x){
    return (x === 'done') ? 'ui-pill is-done' : 'ui-pill is-pending';
  }
  function setPill(el, state, text){
    if (!el) return;
    el.className = pillClass(state);
    el.dataset.state = state;
    el.textContent = text;
  }
  function setEnabled(a, enabled){
    if (!a) return;
    if (enabled) {
      a.style.pointerEvents = '';
      a.style.opacity = '';
      a.setAttribute('aria-disabled', 'false');
    } else {
      a.style.pointerEvents = 'none';
      a.style.opacity = '0.45';
      a.setAttribute('aria-disabled', 'true');
    }
  }
  function setFormEnabled(form, enabled){
    if (!form) return;
    if (enabled) {
      form.style.opacity = '';
      form.style.pointerEvents = '';
    } else {
      form.style.opacity = '0.55';
      form.style.pointerEvents = 'none';
    }
  }

async function poll(){
  try {
    const url = 'api/queue_status.php?gremium_id=' + encodeURIComponent(String(gremiumId));
    const res = await fetch(url, { cache: 'no-store' });
    if (!res.ok) return;

    const data = await res.json();
    const rows = Array.isArray(data.rows) ? data.rows : [];

    for (const r of rows) {
      const id = String(r.id || '');
      const box = document.querySelector('.q-item[data-doc-id="' + id + '"]');
      if (!box) continue;

      const st = computeStates(r);

      setPill(
        box.querySelector('.js-worker'),
        st.workerState,
        'Worker: ' + workerLabel(st.workerState)
      );

      setPill(
        box.querySelector('.js-curation'),
        st.curationState,
        'Kuration: ' + simpleLabel(st.curationState)
      );

      setPill(
        box.querySelector('.js-export'),
        st.exportState,
        'Export: ' + simpleLabel(st.exportState)
      );

      setEnabled(box.querySelector('.js-curate'), st.curationState !== 'not_possible');
      setEnabled(box.querySelector('.js-export-link'), st.exportState !== 'not_possible');
      setFormEnabled(box.querySelector('.js-retry'), st.canRestart);
    }
  } catch (e) {}
}

  poll();
  setInterval(poll, POLL_MS);
})();
</script>

<?php ui_footer(); ?>
