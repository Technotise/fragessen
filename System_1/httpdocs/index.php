<?php
declare(strict_types=1);

require __DIR__ . '/src/auth.php';

auth_start();

if (is_logged_in()) {
  header('Location: queue.php');
  exit;
}

header('Location: login.php');
exit;
