<?php
declare(strict_types=1);

require __DIR__ . '/src/db.php';
$cfg = require __DIR__ . '/src/config.php';

$pdo = db();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  echo 'Bad Request';
  exit;
}

$stmt = $pdo->prepare("
  SELECT d.storage_path, d.original_filename
  FROM documents d
  WHERE d.id=?
");
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row) {
  http_response_code(404);
  echo 'Not Found';
  exit;
}

$baseDir = rtrim((string)$cfg['storage']['base_dir'], '/');
$abs = $baseDir . '/' . $row['storage_path'];

if (!is_file($abs)) {
  http_response_code(404);
  echo 'File missing';
  exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename((string)$row['original_filename']) . '"');
header('Content-Length: ' . (string)filesize($abs));
readfile($abs);
