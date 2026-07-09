<?php
declare(strict_types=1);

/*
  tools/extract_attendance.php

  Aufgabe
  - attendance aus <doc>_slice.mistral.json laden
  - pro Person eine Zeile in document_attendance schreiben
  - extracted_json setzen
  - curated_* niemals anfassen
*/

ini_set('display_errors', '1');
error_reporting(E_ALL);

/* =========================================================
   Bootstrap
========================================================= */

$ROOT = realpath(__DIR__ . '/..');
if ($ROOT === false) {
  fwrite(STDERR, "ROOT nicht gefunden\n");
  exit(1);
}

require $ROOT . '/src/db.php';
$cfg = require $ROOT . '/src/config.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* =========================================================
   Args
========================================================= */

$docId = 0;
foreach ($argv as $arg) {
  if (str_starts_with($arg, '--doc_id=')) {
    $docId = (int)substr($arg, 9);
  }
}

if ($docId <= 0) {
  fwrite(STDERR, "Usage: php extract_attendance.php --doc_id=123\n");
  exit(1);
}

/* =========================================================
   Storage Pfad
========================================================= */

$st = $pdo->prepare("
  SELECT storage_path
  FROM documents
  WHERE id = ? AND deleted_at IS NULL
");
$st->execute([$docId]);
$rel = (string)$st->fetchColumn();

if ($rel === '' || $rel === 'PENDING') {
  fwrite(STDERR, "storage_path fehlt\n");
  exit(1);
}

$baseDir = realpath((string)($cfg['storage']['base_dir'] ?? ''));
if ($baseDir === false) {
  fwrite(STDERR, "storage.base_dir ungueltig\n");
  exit(1);
}

$rel = ltrim($rel, '/');
$dirRel = trim((string)dirname($rel), '.');
if ($dirRel === '') $dirRel = '';

$baseName = pathinfo($rel, PATHINFO_FILENAME);
$jsonRel = ($dirRel !== '' ? $dirRel . '/' : '') . $baseName . '_slice.mistral.json';
$jsonAbs = $baseDir . '/' . $jsonRel;

if (!is_file($jsonAbs)) {
  fwrite(STDERR, "JSON fehlt: $jsonAbs\n");
  exit(1);
}

/* =========================================================
   JSON laden (inkl. wrapped JSON)
========================================================= */

$raw = file_get_contents($jsonAbs);
if ($raw === false || trim($raw) === '') {
  fwrite(STDERR, "JSON leer\n");
  exit(1);
}

$data = json_decode($raw, true);
if (is_string($data)) {
  $data = json_decode($data, true);
}

if (!is_array($data) || !isset($data['attendance']) || !is_array($data['attendance'])) {
  fwrite(STDERR, "attendance Abschnitt fehlt oder ungueltig\n");
  exit(1);
}

$attendance = $data['attendance'];

/* =========================================================
   DB Import
========================================================= */

try {
  $pdo->beginTransaction();

  /* alte extrahierte, nicht kuratierte Rows löschen */
  $pdo->prepare("
    DELETE FROM document_attendance
    WHERE document_id = ?
      AND extracted_flag = 1
      AND curated_flag = 0
  ")->execute([$docId]);

  $ins = $pdo->prepare("
    INSERT INTO document_attendance
      (document_id, row_index, extracted_json, row_hash,
       extracted_flag, curated_flag, needs_review)
    VALUES (?, ?, ?, ?, 1, 0, 1)
  ");

  $rowIndex = 0;
  foreach ($attendance as $row) {
    if (!is_array($row)) continue;

    $rowIndex++;

    $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) continue;

    $hash = hash('sha256', $json);

    $ins->execute([
      $docId,
      $rowIndex,
      $json,
      $hash
    ]);
  }

  $pdo->commit();
  echo "OK extract_attendance document_id=$docId rows=$rowIndex\n";
  exit(0);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  fwrite(STDERR, "EXTRACT_ATTENDANCE_EXCEPTION: " . $e->getMessage() . "\n");
  exit(1);
}
