<?php
declare(strict_types=1);

function ui_h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ui_theme_attr(): string {
  $theme = $_SESSION['theme'] ?? '';
  if ($theme === 'dark' || $theme === 'light') {
    return ' data-theme="' . ui_h($theme) . '"';
  }
  return '';
}

function ui_theme_icon(): string {
  $theme = $_SESSION['theme'] ?? '';
  return ($theme === 'dark') ? '☀️' : '🌙';
}

function ui_header(string $title, string $active = '', array $extraCss = []): void {
  $u = (string)($_SESSION['uname'] ?? '');
  ?>
  <!doctype html>
  <html lang="de"<?= ui_theme_attr() ?>>
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= ui_h($title) ?></title>
    <link rel="stylesheet" href="ui/admin.css">
    <?php foreach ($extraCss as $href): ?>
      <link rel="stylesheet" href="<?= ui_h((string)$href) ?>">
    <?php endforeach; ?>
  </head>
  <body>
    <header class="ui-topbar">
      <div class="ui-topbar-inner">
        <div class="ui-brand">System 1</div>

        <nav class="ui-nav">
          <a class="ui-nav-link <?= $active === 'upload' ? 'is-active' : '' ?>" href="upload.php">Upload</a>
          <a class="ui-nav-link <?= $active === 'queue' ? 'is-active' : '' ?>" href="queue.php">Queue</a>
          <a class="ui-nav-link <?= $active === 'logs' ? 'is-active' : '' ?>" href="logs.php">Logs</a>
        </nav>

        <div class="ui-user">
          <a class="ui-nav-link" href="/tools/theme_toggle.php" title="Theme umschalten">
            <?= ui_theme_icon() ?>
          </a>

          <?php if ($u !== ''): ?>
            <span class="ui-user-name"><?= ui_h($u) ?></span>
          <?php endif; ?>

          <a class="ui-nav-link" href="logout.php">Logout</a>
        </div>
      </div>
    </header>

    <main class="ui-wrap">
      <h1 class="ui-page-title"><?= ui_h($title) ?></h1>
  <?php
}

function ui_footer(): void {
  ?>
    </main>
  </body>
  </html>
  <?php
}
