<?php
declare(strict_types=1);

require __DIR__ . '/src/db.php';
require __DIR__ . '/src/auth.php';
$cfg = require __DIR__ . '/src/config.php';

require_login();

require __DIR__ . '/vendor/autoload.php';

use phpseclib3\Net\SFTP;
use phpseclib3\Crypt\PublicKeyLoader;

$pdo = db();

function ensure_dir(string $dir): void
{
  if ($dir === '') throw new RuntimeException('Leeres Verzeichnis');
  if (is_dir($dir)) return;
  if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
    throw new RuntimeException('Konnte Verzeichnis nicht erstellen: ' . $dir);
  }
}

function rrmdir(string $dir): void
{
  if ($dir === '' || !is_dir($dir)) return;
  $items = scandir($dir);
  if (!is_array($items)) return;

  foreach ($items as $item) {
    if ($item === '.' || $item === '..') continue;
    $p = $dir . '/' . $item;
    if (is_dir($p)) rrmdir($p);
    else @unlink($p);
  }
  @rmdir($dir);
}

function safe_filename(string $name): string
{
  $name = trim($name);
  if ($name === '') return 'document.pdf';

  $name = str_replace(["\0", "\r", "\n", "\t"], ' ', $name);
  $name = preg_replace('/[\/\\\\]+/', '_', $name) ?? $name;
  $name = preg_replace('/[^A-Za-z0-9ÄÖÜäöüß\.\_\-\(\)\[\] ]/u', '_', $name) ?? $name;
  $name = preg_replace('/\s+/', ' ', $name) ?? $name;

  $name = trim($name);
  return $name === '' ? 'document.pdf' : $name;
}

function json_write(string $path, mixed $data): void
{
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException('JSON encode fehlgeschlagen: ' . json_last_error_msg());
  if (file_put_contents($path, $json) === false) throw new RuntimeException('Konnte Datei nicht schreiben: ' . $path);
}

function fetch_one(PDO $pdo, string $sql, array $params = []): ?array
{
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row && is_array($row) ? $row : null;
}

function fetch_all(PDO $pdo, string $sql, array $params = []): array
{
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  return is_array($rows) ? $rows : [];
}

function upsert_export_state(PDO $pdo, int $docId): void
{
  $sql = "
    INSERT INTO document_state (document_id, task, status, updated_at)
    VALUES (:doc_id, 'export', 'fertig', NOW())
    ON DUPLICATE KEY UPDATE status='fertig', updated_at=NOW()
  ";
  $st = $pdo->prepare($sql);
  $st->execute([':doc_id' => $docId]);
}

function sftp_connect(array $cfg): SFTP
{
  $host = (string)($cfg['host'] ?? '');
  $port = (int)($cfg['port'] ?? 22);
  $user = (string)($cfg['username'] ?? '');
  $keyData = (string)($cfg['private_key'] ?? '');

  if ($host === '' || $user === '' || $keyData === '') {
    throw new RuntimeException('SFTP Config unvollständig');
  }

  $sftp = new SFTP($host, $port);

  $key = PublicKeyLoader::loadPrivateKey($keyData);
  if (!$sftp->login($user, $key)) {
    throw new RuntimeException('SFTP Login via Key fehlgeschlagen');
  }

  return $sftp;
}

function sftp_mkdirs(SFTP $sftp, string $path): void
{
  $path = rtrim($path, '/');
  if ($path === '') return;

  $parts = explode('/', ltrim($path, '/'));
  $cur = '';
  foreach ($parts as $p) {
    if ($p === '') continue;
    $cur .= '/' . $p;
    if ($sftp->is_dir($cur)) continue;
    if (!$sftp->mkdir($cur)) {
      throw new RuntimeException('Konnte Remote Ordner nicht erstellen: ' . $cur);
    }
  }
}

function sftp_put_file(SFTP $sftp, string $local, string $remote): void
{
  if (!is_file($local)) throw new RuntimeException('Local file missing: ' . $local);
  $data = file_get_contents($local);
  if ($data === false) throw new RuntimeException('Konnte Local Datei nicht lesen: ' . $local);
  if (!$sftp->put($remote, $data)) throw new RuntimeException('SFTP Upload fehlgeschlagen: ' . $remote);
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => 'Bad Request'], JSON_UNESCAPED_UNICODE);
  exit;
}

$tmpRoot = rtrim(sys_get_temp_dir(), '/');
$tmpDir = $tmpRoot . '/kommrag_export_' . $id . '_' . bin2hex(random_bytes(6));

try {
  $pdo->beginTransaction();

  $doc = fetch_one($pdo, "
    SELECT
      d.id,
      d.gremium_id,
      d.original_filename,
      d.storage_path,
      d.file_hash_sha256,
      d.created_at,
      g.id AS g_id,
      g.`key` AS g_key,
      g.name AS g_name
    FROM documents d
    JOIN gremien g ON g.id = d.gremium_id
    WHERE d.id = ?
    LIMIT 1
  ", [$id]);

  if (!$doc) {
    $pdo->rollBack();
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Not Found'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $baseDir = rtrim((string)($cfg['storage']['base_dir'] ?? ''), '/');
  if ($baseDir === '') throw new RuntimeException('config storage.base_dir fehlt');

  $pdfAbs = $baseDir . '/' . ltrim((string)$doc['storage_path'], '/');
  if (!is_file($pdfAbs)) throw new RuntimeException('PDF fehlt im Storage: ' . $pdfAbs);

  ensure_dir($tmpDir);

  $pdfOutName = safe_filename(basename((string)$doc['original_filename']));
  $pdfLocal = $tmpDir . '/' . $pdfOutName;

  if (!copy($pdfAbs, $pdfLocal)) throw new RuntimeException('Konnte PDF nicht in temp kopieren');

  $documentsPayload = [
    'document' => [
      'id' => (int)$doc['id'],
      'gremium_id' => (int)$doc['gremium_id'],
      'original_filename' => (string)$doc['original_filename'],
      'file_hash_sha256' => (string)$doc['file_hash_sha256'],
      'created_at' => (string)$doc['created_at'],
    ],
    'gremium' => [
      'id' => (int)$doc['g_id'],
      'key' => (string)$doc['g_key'],
      'name' => (string)$doc['g_name'],
    ],
  ];
  json_write($tmpDir . '/documents.json', $documentsPayload);

  $core = fetch_one($pdo, "
    SELECT
      document_id,
      curated_sitzungsdatum,
      curated_uhrzeit_start,
      curated_ort,
      curated_sitzungstyp,
      curated_periodenbezug,
      curated_niederschrift_nr
    FROM document_core
    WHERE document_id=?
    LIMIT 1
  ", [$id]) ?? [];
  json_write($tmpDir . '/core.json', $core);

  $attendance = fetch_all($pdo, "
    SELECT
      document_id,
      row_index,
      role_curated,
      salutation_curated,
      title_curated,
      last_name_curated,
      faction_raw_curated,
      free_text_curated,
      name_norm,
      base_norm,
      row_hash
    FROM document_attendance
    WHERE document_id=?
    ORDER BY row_index, id
  ", [$id]);
  json_write($tmpDir . '/attendance.json', $attendance);

  $agenda = fetch_all($pdo, "
    SELECT
      document_id,
      row_index,
      top_key_curated,
      title_curated,
      drucksache_curated,
      section_curated,
      top_num,
      top_suffix,
      top_sub,
      top_norm
    FROM document_agenda
    WHERE document_id=?
      AND (section_curated IS NULL OR section_curated <> 'non_public')
    ORDER BY row_index, id
  ", [$id]);
  json_write($tmpDir . '/agenda.json', $agenda);

  $sftpCfg = (array)($cfg['system2']['sftp'] ?? []);
  $remoteBase = rtrim((string)($sftpCfg['remote_base_dir'] ?? ''), '/');
  if ($remoteBase === '') throw new RuntimeException('config system2.sftp.remote_base_dir fehlt');

  $remoteDir = $remoteBase . '/' . $id;

  $sftp = sftp_connect($sftpCfg);
  sftp_mkdirs($sftp, $remoteDir);

  $filesToUpload = [
    $pdfOutName,
    'documents.json',
    'core.json',
    'attendance.json',
    'agenda.json',
  ];

  foreach ($filesToUpload as $fn) {
    $local = $tmpDir . '/' . $fn;
    $remote = $remoteDir . '/' . $fn;
    sftp_put_file($sftp, $local, $remote);
  }

  $readyLocal = $tmpDir . '/ready.done';
  if (file_put_contents($readyLocal, '') === false) throw new RuntimeException('Konnte ready.done nicht schreiben');
  sftp_put_file($sftp, $readyLocal, $remoteDir . '/ready.done');

  upsert_export_state($pdo, $id);

  $pdo->commit();

  rrmdir($tmpDir);

  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'ok' => true,
    'document_id' => $id,
    'remote_dir' => $remoteDir,
    'files' => array_merge($filesToUpload, ['ready.done']),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  rrmdir($tmpDir);

  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'ok' => false,
    'error' => $e->getMessage(),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$back = $_SERVER['HTTP_REFERER'] ?? '';

if ($back !== '') {
  header('Location: ' . $back, true, 303);
  exit;
}

header('Location: /queue.php', true, 303);
exit;
