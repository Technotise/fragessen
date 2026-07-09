<?php
declare(strict_types=1);

function db(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  $cfg = require __DIR__ . '/config.php';
  $db = $cfg['db'];
  
  $host = (string)$db['host'];
  $port = (int)($db['port'] ?? 3306);

	$dsn = sprintf(
	  'mysql:host=%s;port=%d;dbname=%s;charset=%s',
	  $host,
	  $port,
	  $db['name'],
	  $db['charset']
	);

  $pdo = new PDO($dsn, $db['user'], $db['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);

  return $pdo;
}
