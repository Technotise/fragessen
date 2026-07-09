<?php
declare(strict_types=1);

/*
  tools/extract_agenda.php

  Aufgabe
  - agenda aus <doc>_slice.mistral.json laden
  - pro TOP eine Zeile in document_agenda schreiben
  - extracted_json setzen
  - top_num/top_suffix/top_sub/top_norm ableiten
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
  fwrite(STDERR, "Usage: php extract_agenda.php --doc_id=123\n");
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

if (!is_array($data) || !isset($data['agenda']) || !is_array($data['agenda'])) {
  fwrite(STDERR, "agenda Abschnitt fehlt oder ungueltig\n");
  exit(1);
}

$agenda = $data['agenda'];

/* =========================================================
   TOP Parsing
========================================================= */

function norm_top_raw(?string $s): string {
  $s = trim((string)$s);
  $s = preg_replace('/\s+/u', '', $s) ?? $s;
  return $s;
}

/*
  Akzeptiert:
  9
  9.a
  24.1
  24.1.a
  24.a
  liefert:
  [top_num(int), top_suffix(?string), top_sub(?string), top_norm(string)]
*/
function parse_top(string $raw): array {
  $raw0 = norm_top_raw($raw);
  if ($raw0 === '') return [0, null, null, ''];

  $parts = explode('.', $raw0);

  $num = 0;
  $suffix = null;
  $sub = null;

  if (preg_match('/^\d+$/', $parts[0])) {
    $num = (int)$parts[0];
  } else {
    return [0, null, null, ''];
  }

  if (count($parts) >= 2 && $parts[1] !== '') {
    // zweiter Teil kann Zahl oder Buchstabe sein
    if (preg_match('/^\d+$/', $parts[1])) {
      $sub = $parts[1];
    } elseif (preg_match('/^[A-Za-z]$/', $parts[1])) {
      $suffix = mb_strtoupper($parts[1], 'UTF-8');
    } else {
      // sonst als sub führen
      $sub = $parts[1];
    }
  }

  if (count($parts) >= 3 && $parts[2] !== '') {
    // dritter Teil wird als sub Erweiterung geführt (z.B. "24.1.a" -> sub "1.a")
    $tail = array_slice($parts, 1);
    $sub = implode('.', $tail);
    // wenn sub gesetzt ist, suffix nur, wenn sub keine Zahl startet und genau 1 Buchstabe
    if (preg_match('/^[A-Za-z]$/', $tail[0])) {
      $suffix = mb_strtoupper($tail[0], 'UTF-8');
      $sub = implode('.', array_slice($tail, 1));
      if ($sub === '') $sub = null;
    }
  }

  $norm = (string)$num;
  if ($suffix !== null) $norm .= '.' . $suffix;
  if ($sub !== null && $sub !== '') $norm .= '.' . $sub;

  return [$num, $suffix, $sub, $norm];
}

/* =========================================================
   DB Import
========================================================= */

try {
  $pdo->beginTransaction();

  $pdo->prepare("
    DELETE FROM document_agenda
    WHERE document_id = ?
      AND extracted_flag = 1
      AND curated_flag = 0
  ")->execute([$docId]);

  $ins = $pdo->prepare("
    INSERT INTO document_agenda
      (document_id, row_index, extracted_json,
       top_num, top_suffix, top_sub, top_norm,
       extracted_flag, curated_flag, needs_review)
    VALUES (?, ?, ?, ?, ?, ?, ?, 1, 0, 1)
  ");

  $rowIndex = 0;

  foreach ($agenda as $row) {
    if (!is_array($row)) continue;
    $rowIndex++;

    $topRaw = (string)($row['top'] ?? '');
    [$topNum, $topSuffix, $topSub, $topNorm] = parse_top($topRaw);

    $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) continue;

    $ins->execute([
      $docId,
      $rowIndex,
      $json,
      $topNum > 0 ? $topNum : null,
      $topSuffix,
      $topSub,
      $topNorm !== '' ? $topNorm : null,
    ]);

    if ($rowIndex > 4000) break;
  }

  $pdo->commit();
  echo "OK extract_agenda document_id=$docId rows=$rowIndex\n";
  exit(0);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  fwrite(STDERR, "EXTRACT_AGENDA_EXCEPTION: " . $e->getMessage() . "\n");
  exit(1);
}
