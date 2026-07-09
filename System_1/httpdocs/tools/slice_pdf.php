<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

/*
  slice_pdf.php
  CLI: php tools/slice_pdf.php --doc_id=123

  Output:
    <docId>_slice.pdf
    <docId>_slice.meta.json
  direkt neben dem Original PDF
*/

require __DIR__ . '/../src/db.php';
$cfg = require __DIR__ . '/../src/config.php';
require __DIR__ . '/../vendor/autoload.php';

/* ===================== helpers ===================== */

function arg_doc_id(array $argv): int {
  foreach ($argv as $a) {
    if (preg_match('/^--doc_id=(\d+)$/', (string)$a, $m)) return (int)$m[1];
  }
  return 0;
}

function abs_pdf_path(array $cfg, string $storagePath): string {
  $baseDir = rtrim((string)$cfg['storage']['base_dir'], '/');
  return $baseDir . '/' . ltrim($storagePath, '/');
}

function ensure_dir(string $dir): void {
  if (is_dir($dir)) return;
  if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
    throw new RuntimeException('Konnte Zielordner nicht erstellen: ' . $dir);
  }
}

function run_python(
  string $pythonBin,
  string $scriptPath,
  array $args,
  ?string $pyPkgsDir
): array {

  if (!is_file($scriptPath)) {
    throw new RuntimeException("Python Script fehlt: $scriptPath");
  }

  $env = $_ENV;
  if ($pyPkgsDir !== null && is_dir($pyPkgsDir)) {
    $env['PYTHONPATH'] =
      $pyPkgsDir .
      (isset($env['PYTHONPATH']) ? (PATH_SEPARATOR . $env['PYTHONPATH']) : '');
  }

  $cmd = $pythonBin . ' ' . escapeshellarg($scriptPath);
  foreach ($args as $a) {
    $cmd .= ' ' . escapeshellarg((string)$a);
  }

  $descs = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
  ];

  $proc = @proc_open($cmd, $descs, $pipes, __DIR__ . '/..', $env);
  if (!is_resource($proc)) {
    throw new RuntimeException("proc_open fehlgeschlagen: $cmd");
  }

  fclose($pipes[0]);
  $stdout = stream_get_contents($pipes[1]) ?: '';
  $stderr = stream_get_contents($pipes[2]) ?: '';
  fclose($pipes[1]);
  fclose($pipes[2]);
  $code = proc_close($proc);

  $raw = trim($stdout);
  $data = json_decode($raw, true);

  if (!is_array($data)) {
    $p = strpos($raw, '{');
    if ($p !== false) {
      $data = json_decode(substr($raw, $p), true);
    }
  }

  if (!is_array($data)) {
    $hint = mb_substr($raw, 0, 800, 'UTF-8');
    $errHint = trim($stderr) !== '' ? ("\nPY STDERR:\n" . mb_substr($stderr, 0, 800, 'UTF-8')) : '';
    throw new RuntimeException("Ungültiges JSON aus Python.\n$hint$errHint");
  }

  if (isset($data['error'])) {
    $msg = (string)($data['message'] ?? '');
    throw new RuntimeException("Python error=" . (string)$data['error'] . ($msg !== '' ? (" msg=" . $msg) : ''));
  }

  if ($code !== 0) {
    $msg = trim($stderr) !== '' ? trim($stderr) : "Python exit=$code";
    throw new RuntimeException($msg);
  }

  return $data;
}

/* ===================== main ===================== */

$docId = arg_doc_id($argv);
if ($docId <= 0) {
  fwrite(STDERR, "Fehlendes --doc_id\n");
  exit(2);
}

$baseDir = rtrim((string)($cfg['storage']['base_dir'] ?? ''), '/');
if ($baseDir === '') {
  fwrite(STDERR, "storage.base_dir fehlt\n");
  exit(2);
}

try {

  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $st = $pdo->prepare("SELECT storage_path FROM documents WHERE id=? AND deleted_at IS NULL");
  $st->execute([$docId]);
  $rel = (string)$st->fetchColumn();

  if ($rel === '' || $rel === 'PENDING') {
    throw new RuntimeException('storage_path fehlt für doc_id=' . $docId);
  }

  $srcAbs = abs_pdf_path($cfg, $rel);
  if (!is_file($srcAbs)) {
    throw new RuntimeException('PDF nicht gefunden: ' . $srcAbs);
  }

  $dir = dirname($srcAbs);
  $dstAbs  = $dir . '/' . $docId . '_slice.pdf';
  $metaAbs = $dir . '/' . $docId . '_slice.meta.json';

  if (is_file($dstAbs)) {
    echo "Slice existiert bereits\n";
    exit(0);
  }

  $kw = [
    'bericht erstattet',
    'beschließt',
    'beschliesst',
    'nimmt kenntnis',
    'die bezirksvertretung',
    'der rat',
    'der ausschuss',
    'abstimmung',
  ];

  $pythonBin = is_file(__DIR__ . '/../.venv/bin/python3')
    ? (__DIR__ . '/../.venv/bin/python3')
    : 'python3';

  $pyScript  = __DIR__ . '/slice_pdf.py';
  $pyPkgsDir = is_dir(__DIR__ . '/../py_pkgs') ? (__DIR__ . '/../py_pkgs') : null;

  $scanN = 25;

  /* ---------- 1) detect ---------- */

  $detectArgs = ['detect', '--pdf', $srcAbs, '--scan_pages', (string)$scanN];
  foreach ($kw as $k) {
    $detectArgs[] = '--kw';
    $detectArgs[] = $k;
  }

  $detect = run_python($pythonBin, $pyScript, $detectArgs, $pyPkgsDir);

  $total = (int)($detect['pages_total'] ?? 0);
  if ($total <= 0) {
    throw new RuntimeException('detect lieferte pages_total=0');
  }

  $agendaPage = $detect['agenda_page'] ?? null;
  $top1Page   = $detect['top1_page'] ?? null;
  $top1Line   = $detect['top1_line'] ?? null;
  $kwHits     = $detect['kw_hits'] ?? [];

  /* ---------- 2) sliceEnd berechnen ---------- */

  $sliceEnd = null;
  $contaminated = false;

  if ($agendaPage !== null && $top1Page !== null) {

    $top1PageI = (int)$top1Page;
    $top1LineI = (int)($top1Line ?? 0);

    if ($top1LineI > 0 && $top1LineI <= 15) {
      $sliceEnd = $top1PageI - 1;
    } else {
      $sliceEnd = $top1PageI;
      $contaminated = true;
    }

  } else {
    $sliceEnd = min($total, 12);
    $contaminated = true;
  }

  $sliceEnd = max(1, min((int)$sliceEnd, $total));

  /* ---------- 3) slice ---------- */

  ensure_dir(dirname($dstAbs));

  $slice = run_python(
    $pythonBin,
    $pyScript,
    [$srcAbs, $dstAbs, (string)$sliceEnd],   // alter Slice-Modus bleibt kompatibel
    $pyPkgsDir
  );

  /* ---------- 4) meta ---------- */

  $meta = [
    'doc_id' => $docId,
    'source_pdf' => $srcAbs,
    'slice_pdf' => $dstAbs,
    'created_at' => date('c'),
    'pages_total' => $total,
    'pages_scanned' => min($total, $scanN),
    'agenda_page' => $agendaPage,
    'top1_page' => $top1Page,
    'top1_line_index' => $top1Line,
    'top1_keyword_hits' => $kwHits,
    'slice_end_page' => $sliceEnd,
    'contaminated' => $contaminated,
    'python_detect' => $detect,
    'python_slice' => $slice,
    'notes' => 'detect + slice via python (pypdf). no smalot/pdfparser.',
  ];

  @file_put_contents(
    $metaAbs,
    json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
  );

  echo "OK slice_end_page=" . $sliceEnd . " contaminated=" . ($contaminated ? '1' : '0') . "\n";
  exit(0);

} catch (Throwable $e) {
  fwrite(STDERR, $e->getMessage() . "\n");
  exit(1);
}
