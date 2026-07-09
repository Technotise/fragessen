<?php
declare(strict_types=1);

require __DIR__ . '/../src/auth.php';

require_login();

auth_start();

$next = $_SERVER['HTTP_REFERER'] ?? 'index.php';

$current = $_SESSION['theme'] ?? null;

if ($current === 'dark') {
  $_SESSION['theme'] = 'light';
} else {
  $_SESSION['theme'] = 'dark';
}

header('Location: ' . $next);
exit;
