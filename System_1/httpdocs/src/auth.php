<?php
declare(strict_types=1);

function auth_start(): void {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    session_set_cookie_params([
      'lifetime' => 0,
      'path' => '/',
      'secure' => $secure,
      'httponly' => true,
      'samesite' => 'Lax',
    ]);

    session_start();
  }
}

function get_session_user_id(): int {
  auth_start();
  return (int)($_SESSION['uid'] ?? 0);
}

function get_session_user_name(): string {
  auth_start();
  $u = (string)($_SESSION['uname'] ?? '');
  return $u !== '' ? $u : 'unbekannt';
}

function is_logged_in(): bool {
  auth_start();
  return get_session_user_id() > 0;
}

function require_login(): void {
  if (PHP_SAPI === 'cli') return;
  if (is_logged_in()) return;

  $next = (string)($_SERVER['REQUEST_URI'] ?? 'queue.php');
  header('Location: login.php?next=' . rawurlencode($next));
  exit;
}

function login_user(int $uid, string $uname): void {
  auth_start();
  session_regenerate_id(true);

  $_SESSION['uid'] = $uid;
  $_SESSION['uname'] = $uname;
}

function logout_user(): void {
  auth_start();
  $_SESSION = [];

  if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(
      session_name(),
      '',
      time() - 42000,
      $p['path'] ?? '/',
      $p['domain'] ?? '',
      (bool)($p['secure'] ?? false),
      (bool)($p['httponly'] ?? true)
    );
  }

  session_destroy();
}
