<?php
declare(strict_types=1);

/*
  cron/worker.php

  Ablauf:
  - Worker claimed genau EIN Dokument exklusiv
  - Arbeitet alle Tasks dieses Dokuments der Reihe nach ab
  - Danach extraction_retry_allowed = 0
  - Dann nächstes Dokument

  Mehrere Worker möglich durch Dokument Lock in documents:
    processing_token
    processing_locked_at
    processing_lock_until

  Erwartete Task Namen in document_state.task:
    slice
    get_json
    extract_core
    extract_attendance
    extract_agenda
*/

$ROOT = realpath(__DIR__ . '/..');
if ($ROOT === false) {
  echo "ROOT nicht gefunden\n";
  exit(1);
}

$HTTPDOCS = $ROOT . '/httpdocs';

/* =========================================================
   Logging
========================================================= */

$LOG_DIR = $HTTPDOCS . '/var/logs';
if (!is_dir($LOG_DIR)) {
  @mkdir($LOG_DIR, 0775, true);
}
$logFile = $LOG_DIR . '/cron_worker.log';

function logline(string $msg): void
{
  global $logFile;
  $ts = date('Y-m-d H:i:s');
  @file_put_contents($logFile, "[$ts] $msg\n", FILE_APPEND);
}

/* =========================================================
   DB + Config
========================================================= */

require $HTTPDOCS . '/src/db.php';
$cfg = require $HTTPDOCS . '/src/config.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$mistralKey = (string)($cfg['mistral_api_key'] ?? '');
if ($mistralKey === '') {
  logline("FATAL: mistral_api_key fehlt");
  exit(1);
}

/* =========================================================
   Runner Helper
========================================================= */

/*
  PHP CLI Binary und INI Scan Dir sind hosting-spezifisch
  und kommen aus der Config. Fallback: das Binary, das diesen
  Worker ausführt.

  config.php:
    'cli' => [
      'php'          => '/usr/local/php85/bin/php',
      'ini_scan_dir' => '/usr/local/php85/etc/conf.d',
    ],
*/
$php = (string)($cfg['cli']['php'] ?? PHP_BINARY);

$envBase = [
  'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
];

$iniScanDir = (string)($cfg['cli']['ini_scan_dir'] ?? '');
if ($iniScanDir !== '') {
  $envBase['PHP_INI_SCAN_DIR'] = $iniScanDir;
}

function run(array $cmd, array $env, int $timeoutSec): array
{
  $cmdStr = implode(' ', array_map(static function ($x) {
    $x = (string)$x;
    return preg_match('/\s/', $x) ? escapeshellarg($x) : $x;
  }, $cmd));
  logline("CMD: $cmdStr");

  $spec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
  ];

  $proc = @proc_open($cmd, $spec, $pipes, null, $env);
  if (!is_resource($proc)) {
    return ['exit' => 127, 'out' => '', 'err' => 'proc_open fehlgeschlagen'];
  }

  fclose($pipes[0]);
  stream_set_blocking($pipes[1], false);
  stream_set_blocking($pipes[2], false);

  $out = '';
  $err = '';
  $start = time();

  while (true) {
    $out .= (string)stream_get_contents($pipes[1]);
    $err .= (string)stream_get_contents($pipes[2]);

    $st = proc_get_status($proc);
    if (!$st['running']) {
      break;
    }

    if (time() - $start > $timeoutSec) {
      $err .= "\nTimeout nach {$timeoutSec}s\n";
      @proc_terminate($proc);
      break;
    }

    usleep(200000);
  }

  $out .= (string)stream_get_contents($pipes[1]);
  $err .= (string)stream_get_contents($pipes[2]);

  fclose($pipes[1]);
  fclose($pipes[2]);

  $exit = proc_close($proc);

  if (trim($out) !== '') logline("OUT: " . trim($out));
  if (trim($err) !== '') logline("ERR: " . trim($err));
  logline("EXIT: " . (string)$exit);

  return ['exit' => (int)$exit, 'out' => $out, 'err' => $err];
}

/* =========================================================
   Tool Paths
========================================================= */

$tools = [
  'slice'              => $HTTPDOCS . '/tools/slice_pdf.php',
  'get_json'           => $HTTPDOCS . '/api/mistral_docai.php',
  'extract_core'       => $HTTPDOCS . '/tools/extract_core.php',
  'extract_attendance' => $HTTPDOCS . '/tools/extract_attendance.php',
  'extract_agenda'     => $HTTPDOCS . '/tools/extract_agenda.php',
];

foreach ($tools as $k => $p) {
  if (!is_file($p)) {
    logline("FATAL: Tool fehlt: $k ($p)");
    exit(1);
  }
}

/*
  Schema Pfad
  Muss existieren, weil api/mistral_docai.php zwingend --schema= erwartet
*/
$schemaPath = $HTTPDOCS . '/src/schema/protokoll_extraktion.json';
if (!is_file($schemaPath)) {
  logline("FATAL: Schema fehlt: $schemaPath");
  exit(1);
}

/* =========================================================
   Slice Pfad Helper
========================================================= */

/*
  Erwartetes Layout:
  httpdocs/storage/pdf/<gremium_key>/<year>/<docId>_slice.pdf

  Primär: Pfad über DB-Metadaten (gremium_key + Jahr) auflösen.
  Fallback: glob über das storage-Verzeichnis.
*/
function get_slice_path(PDO $pdo, string $httpdocs, int $docId): string
{
  try {
    $st = $pdo->prepare("
      SELECT g.`key` AS gkey, YEAR(d.datum) AS y
      FROM documents d
      JOIN gremien g ON g.id = d.gremium_id
      WHERE d.id = ?
      LIMIT 1
    ");
    $st->execute([$docId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);

    $gkey = (string)($r['gkey'] ?? '');
    $y = (string)($r['y'] ?? '');

    if ($gkey !== '' && $y !== '') {
      $p = $httpdocs . '/storage/pdf/' . $gkey . '/' . $y . '/' . $docId . '_slice.pdf';
      if (is_file($p)) return $p;
    }
  } catch (Throwable $e) {
    logline("WARN: get_slice_path db lookup fehlgeschlagen: " . $e->getMessage());
  }

  $matches = glob($httpdocs . '/storage/pdf/*/*/' . $docId . '_slice.pdf');
  if (is_array($matches) && isset($matches[0]) && is_file($matches[0])) {
    return (string)$matches[0];
  }

  return '';
}

/* =========================================================
   Document Locking
========================================================= */

function pick_document(PDO $pdo): int
{
  $st = $pdo->query("
    SELECT id
    FROM documents
    WHERE deleted_at IS NULL
      AND extraction_retry_allowed = 1
      AND (
        processing_lock_until IS NULL
        OR processing_lock_until < NOW()
      )
    ORDER BY created_at ASC
    LIMIT 1
  ");
  return (int)($st->fetchColumn() ?: 0);
}

function claim_document(PDO $pdo, int $docId, string $token): bool
{
  $st = $pdo->prepare("
    UPDATE documents
    SET
      processing_token = ?,
      processing_locked_at = NOW(),
      processing_lock_until = DATE_ADD(NOW(), INTERVAL 30 MINUTE)
    WHERE id = ?
      AND extraction_retry_allowed = 1
      AND (
        processing_lock_until IS NULL
        OR processing_lock_until < NOW()
      )
  ");
  $st->execute([$token, $docId]);
  return $st->rowCount() === 1;
}

function refresh_lock(PDO $pdo, int $docId, string $token): void
{
  $pdo->prepare("
    UPDATE documents
    SET processing_lock_until = DATE_ADD(NOW(), INTERVAL 30 MINUTE)
    WHERE id = ? AND processing_token = ?
  ")->execute([$docId, $token]);
}

function finalize_document(PDO $pdo, int $docId, string $token): void
{
  $pdo->prepare("
    UPDATE documents
    SET
      extraction_retry_allowed = 0,
      processing_token = NULL,
      processing_locked_at = NULL,
      processing_lock_until = NULL
    WHERE id = ? AND processing_token = ?
  ")->execute([$docId, $token]);
}

/* =========================================================
   Task Handling
========================================================= */

function next_task(PDO $pdo, int $docId): ?array
{
  $st = $pdo->prepare("
    SELECT id, task
    FROM document_state
    WHERE document_id = ?
      AND status IN ('geplant','fehlgeschlagen')
    ORDER BY task_order ASC
    LIMIT 1
  ");
  $st->execute([$docId]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  return $r ?: null;
}

function set_task(PDO $pdo, int $id, string $status): void
{
  $pdo->prepare("
    UPDATE document_state
    SET status = ?
    WHERE id = ?
  ")->execute([$status, $id]);
}

/* =========================================================
   MAIN LOOP
========================================================= */

logline("Worker gestartet");

while (true) {
  $docId = pick_document($pdo);
  if ($docId === 0) {
    break;
  }

  $token = bin2hex(random_bytes(16));
  if (!claim_document($pdo, $docId, $token)) {
    continue;
  }

  logline("DOC $docId claimed");

  try {
    while (true) {
      $task = next_task($pdo, $docId);
      if (!$task) {
        break;
      }

      $stateId = (int)$task['id'];
      $name = (string)$task['task'];

      set_task($pdo, $stateId, 'gestartet');
      refresh_lock($pdo, $docId, $token);

      logline("DOC $docId TASK $name gestartet");

      $r = ['exit' => 1, 'out' => '', 'err' => 'unbekannter task'];

      if ($name === 'slice') {
        $r = run([$php, $tools['slice'], '--doc_id=' . $docId], $envBase, 600);
      } elseif ($name === 'get_json') {
        $slice = get_slice_path($pdo, $HTTPDOCS, $docId);

        if ($slice === '' || !is_file($slice)) {
          $r = ['exit' => 2, 'out' => '', 'err' => 'Slice PDF fehlt'];
          logline("DOC $docId Slice PDF fehlt");
        } else {
          $r = run(
            [
              $php,
              $tools['get_json'],
              $slice,
              '--schema=' . $schemaPath,
            ],
            array_merge($envBase, ['MISTRAL_API_KEY' => $mistralKey]),
            900
          );
        }
      } elseif (isset($tools[$name])) {
        $r = run([$php, $tools[$name], '--doc_id=' . $docId], $envBase, 300);
      } else {
        logline("DOC $docId Unbekannter Task Name: $name");
        $r = ['exit' => 2, 'out' => '', 'err' => 'Unbekannter Task'];
      }

      if ((int)$r['exit'] !== 0) {
        set_task($pdo, $stateId, 'fehlgeschlagen');
        logline("DOC $docId TASK $name FEHLER exit=" . (string)$r['exit']);
        break;
      }

      set_task($pdo, $stateId, 'fertig');
      logline("DOC $docId TASK $name fertig");
    }
  } catch (Throwable $e) {
    logline("DOC $docId EXCEPTION: " . $e->getMessage());
  } finally {
    finalize_document($pdo, $docId, $token);
    logline("DOC $docId abgeschlossen");
  }
}

logline("Worker beendet");
