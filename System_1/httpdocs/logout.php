<?php
declare(strict_types=1);
require __DIR__ . '/src/auth.php';
logout_user();
header('Location: login.php');
exit;
