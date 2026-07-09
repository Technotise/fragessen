<?php
declare(strict_types=1);

require __DIR__ . '/src/db.php';
require __DIR__ . '/src/util.php';
require __DIR__ . '/src/auth.php';

$pdo = db();

auth_start();

if (is_logged_in()) {
  $next = (string)($_GET['next'] ?? 'queue.php');
  header('Location: ' . $next);
  exit;
}

$err = null;
$next = (string)($_GET['next'] ?? ($_POST['next'] ?? 'queue.php'));
if (!preg_match('~^[a-z0-9_./-]+\.php(\?.*)?$~i', $next)) {
  $next = 'queue.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $u = trim((string)($_POST['username'] ?? ''));
  $p = (string)($_POST['password'] ?? '');

  if ($u === '' || $p === '') {
    $err = 'Bitte Benutzername und Passwort eingeben.';
  } else {
    $st = $pdo->prepare("SELECT id, username, password_hash, is_active FROM users WHERE username=? LIMIT 1");
    $st->execute([$u]);
    $row = $st->fetch();

    if (!$row || (int)$row['is_active'] !== 1 || !password_verify($p, (string)$row['password_hash'])) {
      $err = 'Login fehlgeschlagen.';
    } else {
      login_user((int)$row['id'], (string)$row['username']);
      $pdo->prepare("UPDATE users SET last_login_at=NOW() WHERE id=?")->execute([(int)$row['id']]);

      header('Location: ' . $next);
      exit;
    }
  }
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>System 1 Login</title>
  <link rel="stylesheet" href="ui/admin.css">
  <style>
    .ui-login-wrap { min-height: 100vh; display: grid; place-items: center; padding: 1.6rem 1rem; }
    .ui-login-card { width: 100%; max-width: 460px; }
    .ui-login-title { margin: 0 0 0.4rem; font-size: 1.35rem; }
    .ui-login-sub { margin: 0 0 1rem; color: var(--ui-text-muted); font-size: 0.98rem; }
    .ui-login-actions { margin-top: 1rem; display: flex; gap: 0.6rem; align-items: center; justify-content: space-between; flex-wrap: wrap; }
  </style>
</head>
<body>
  <div class="ui-login-wrap">
    <div class="ui-box ui-login-card">
      <h2 class="ui-login-title">System 1 Login</h2>
      <p class="ui-login-sub">Bitte anmelden, um fortzufahren.</p>

      <?php if ($err): ?>
        <div class="ui-err"><?= h($err) ?></div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="next" value="<?= h($next) ?>">

        <label class="ui-label">Benutzername</label>
        <input class="ui-input" name="username" autocomplete="username" required>

        <label class="ui-label" style="margin-top:0.8rem;">Passwort</label>
        <input class="ui-input" type="password" name="password" autocomplete="current-password" required>

        <div class="ui-login-actions">
          <button class="ui-btn" type="submit">Anmelden</button>
          <span class="ui-muted">Weiterleitung: <?= h($next) ?></span>
        </div>
      </form>
    </div>
  </div>
</body>
</html>
