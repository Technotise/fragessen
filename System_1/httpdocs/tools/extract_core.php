<?php
declare(strict_types=1);

/*
  tools/extract_core.php

  Aufgabe
  - Core-Teil aus <base>/<rel>/<name>_slice.mistral.json laden
  - Validieren
  - extracted_json schreiben
  - curated_* Felder niemals anfassen
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
  fwrite(STDERR, "Usage: php extract_core.php --doc_id=123\n");
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

/* base_dir absolut und stabil auflösen */
$baseDir = realpath((string)($cfg['storage']['base_dir'] ?? ''));
if ($baseDir === false) {
  fwrite(STDERR, "storage.base_dir ungueltig\n");
  exit(1);
}

$rel = ltrim($rel, '/');
$dirRel = trim((string)dirname($rel), '.');
if ($dirRel === '') {
  $dirRel = '';
}

$baseName = pathinfo($rel, PATHINFO_FILENAME);
if ($baseName === '') {
  fwrite(STDERR, "baseName nicht bestimmbar\n");
  exit(1);
}

$jsonRel = ($dirRel !== '' ? $dirRel . '/' : '') . $baseName . '_slice.mistral.json';
$jsonAbs = $baseDir . '/' . $jsonRel;

/* Debug Pfade */
fwrite(STDERR, "DOC_ID=$docId\n");
fwrite(STDERR, "BASE_DIR=$baseDir\n");
fwrite(STDERR, "JSON_REL=$jsonRel\n");
fwrite(STDERR, "JSON_ABS=$jsonAbs\n");

if (!is_file($jsonAbs)) {
  fwrite(STDERR, "JSON fehlt: $jsonAbs\n");
  exit(1);
}

/* =========================================================
   JSON laden
========================================================= */

$raw = file_get_contents($jsonAbs);
if ($raw === false || trim($raw) === '') {
  fwrite(STDERR, "JSON leer\n");
  exit(1);
}

$data = json_decode($raw, true);

/* Fall: JSON ist als String serialisiert */
if (is_string($data)) {
  $data = json_decode($data, true);
}

if (!is_array($data)) {
  fwrite(STDERR, "JSON ungueltig\n");
  exit(1);
}

if (!isset($data['core']) || !is_array($data['core'])) {
  fwrite(STDERR, "core Abschnitt fehlt\n");
  fwrite(STDERR, "JSON_KEYS=" . implode(',', array_keys($data)) . "\n");
  exit(1);
}

$core = $data['core'];

/* =========================================================
   DB Import
========================================================= */

try {
  $pdo->beginTransaction();

  $st = $pdo->prepare("
    INSERT INTO document_core
      (document_id, extracted_json)
    VALUES (?, ?)
    ON DUPLICATE KEY UPDATE
      extracted_json = VALUES(extracted_json)
  ");

  $st->execute([
    $docId,
    json_encode($core, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
  ]);

  $pdo->commit();

  echo "OK extract_core document_id=$docId\n";
  exit(0);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  fwrite(STDERR, "EXTRACT_CORE_EXCEPTION: " . $e->getMessage() . "\n");
  exit(1);
}
