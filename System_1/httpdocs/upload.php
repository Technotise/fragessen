<?php
declare(strict_types=1);

require __DIR__ . '/src/db.php';
require __DIR__ . '/src/util.php';
require __DIR__ . '/src/auth.php';
require __DIR__ . '/ui/layout.php';

$cfg = require __DIR__ . '/src/config.php';

require_login();
$pdo = db();

$errors = [];

const MAX_FILES_PER_UPLOAD = 15;

function ensure_dir_local(string $dir): void
{
  if ($dir === '') {
    throw new RuntimeException('Leeres Verzeichnis.');
  }
  if (is_dir($dir)) {
    return;
  }
  if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
    throw new RuntimeException('Konnte Verzeichnis nicht erstellen: ' . $dir);
  }
}

function actor_string(PDO $pdo): string
{
  static $cached = null;
  if ($cached !== null) {
    return $cached;
  }

  $uid = (int)($_SESSION['user_id'] ?? 0);
  if ($uid <= 0) {
    return $cached = 'ui';
  }

  $candidates = [
    (string)($_SESSION['username'] ?? ''),
    (string)($_SESSION['uname'] ?? ''),
    (string)($_SESSION['display_name'] ?? ''),
    (string)($_SESSION['name'] ?? ''),
  ];

  foreach ($candidates as $n) {
    $n = trim($n);
    if ($n !== '') {
      return $cached = 'user:' . $uid . ' ' . $n;
    }
  }

  try {
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id=? LIMIT 1");
    $stmt->execute([$uid]);
    $uname = trim((string)$stmt->fetchColumn());
    if ($uname !== '') {
      return $cached = 'user:' . $uid . ' ' . $uname;
    }
  } catch (Throwable $e) {
  }

  return $cached = 'user:' . $uid;
}

$gremien = $pdo
  ->query("SELECT id, `key`, name FROM gremien WHERE aktiv=1 ORDER BY name")
  ->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $gremiumId = (int)($_POST['gremium_id'] ?? 0);
    if ($gremiumId <= 0) {
      throw new RuntimeException('Bitte Gremium auswählen.');
    }

    if (!isset($_FILES['pdf'])) {
      throw new RuntimeException('Keine Datei empfangen.');
    }

    $f = $_FILES['pdf'];

    $files = [];
    if (is_array($f['name'])) {
      $cnt = count($f['name']);
      if ($cnt > MAX_FILES_PER_UPLOAD) {
        throw new RuntimeException('Maximal ' . MAX_FILES_PER_UPLOAD . ' Dateien pro Upload erlaubt.');
      }

      for ($i = 0; $i < $cnt; $i++) {
        $files[] = [
          'name' => (string)($f['name'][$i] ?? ''),
          'tmp_name' => (string)($f['tmp_name'][$i] ?? ''),
          'error' => (int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE),
          'size' => (int)($f['size'][$i] ?? 0),
        ];
      }
    } else {
      $files[] = [
        'name' => (string)($f['name'] ?? ''),
        'tmp_name' => (string)($f['tmp_name'] ?? ''),
        'error' => (int)($f['error'] ?? UPLOAD_ERR_NO_FILE),
        'size' => (int)($f['size'] ?? 0),
      ];
    }

    if (!$files) {
      throw new RuntimeException('Keine Datei empfangen.');
    }

    $stmt = $pdo->prepare("SELECT `key` FROM gremien WHERE id=? AND aktiv=1");
    $stmt->execute([$gremiumId]);
    $gKey = (string)$stmt->fetchColumn();
    if ($gKey === '') {
      throw new RuntimeException('Unbekanntes Gremium.');
    }

    $year = (int)date('Y');
    $baseDir = rtrim((string)($cfg['storage']['base_dir'] ?? ''), '/');
    if ($baseDir === '') {
      throw new RuntimeException('Storage base_dir fehlt in config.');
    }

    $dir = $baseDir . '/' . $gKey . '/' . $year;
    ensure_dir_local($dir);

    $maxBytes = (int)($cfg['upload']['max_bytes'] ?? 0);
    $allowed = (array)($cfg['upload']['allowed_mime'] ?? ['application/pdf']);
    $finfo = new finfo(FILEINFO_MIME_TYPE);

    $uploadedIds = [];
    $fileErrors = [];

    foreach ($files as $idx => $one) {
      $origName = $one['name'] !== '' ? $one['name'] : ('upload_' . ($idx + 1) . '.pdf');

      try {
        if (($one['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
          throw new RuntimeException('Upload Fehler: ' . (string)$one['error']);
        }

        $size = (int)$one['size'];
        if ($size <= 0) {
          throw new RuntimeException('Datei ist leer.');
        }
        if ($maxBytes > 0 && $size > $maxBytes) {
          throw new RuntimeException('Datei zu groß.');
        }

        $tmp = $one['tmp_name'];
        if ($tmp === '' || !is_uploaded_file($tmp)) {
          throw new RuntimeException('Upload Datei ist ungültig.');
        }

        $mime = (string)$finfo->file($tmp);
        if (!in_array($mime, $allowed, true)) {
          throw new RuntimeException('Nur PDF erlaubt. Erkannt: ' . $mime);
        }

        $hash = sha256_file($tmp);

        $pdo->beginTransaction();
        try {
          $stmt = $pdo->prepare("
            INSERT INTO documents
              (gremium_id, original_filename, mime_type, file_size, storage_path, file_hash_sha256, extraction_retry_allowed)
            VALUES
              (?, ?, ?, ?, 'PENDING', ?, 1)
          ");
          $stmt->execute([$gremiumId, $origName, $mime, $size, $hash]);
          $docId = (int)$pdo->lastInsertId();

          $targetRel = $gKey . '/' . $year . '/' . $docId . '.pdf';
          $targetAbs = $baseDir . '/' . $targetRel;

          if (!move_uploaded_file($tmp, $targetAbs)) {
            throw new RuntimeException('Konnte Datei nicht speichern.');
          }

          $pdo->prepare("UPDATE documents SET storage_path=? WHERE id=?")
            ->execute([$targetRel, $docId]);

          $pdo->prepare("
            INSERT INTO document_state (document_id, task, task_order, status)
            VALUES
              (?, 'slice', 10, 'geplant'),
              (?, 'get_json', 20, 'geplant'),
              (?, 'extract_core', 30, 'geplant'),
              (?, 'extract_attendance', 40, 'geplant'),
              (?, 'extract_agenda', 50, 'geplant'),
              (?, 'curation_core', 60, 'geplant'),
              (?, 'curation_attendance', 70, 'geplant'),
              (?, 'curation_agenda', 80, 'geplant'),
              (?, 'export', 90, 'geplant')
          ")->execute([
            $docId, $docId, $docId,
            $docId, $docId, $docId,
            $docId, $docId, $docId
          ]);

          $logMsg = json_encode([
            'event' => 'document_uploaded',
            'document_id' => $docId,
            'gremium_id' => $gremiumId,
            'original_filename' => $origName,
            'mime_type' => $mime,
            'file_size' => $size,
            'storage_path' => $targetRel,
            'file_hash_sha256' => $hash
          ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

          if ($logMsg === false) {
            $logMsg = 'document_uploaded';
          }

          $pdo->prepare("
            INSERT INTO document_logs (document_id, level, task, message, actor)
            VALUES (?, 'info', 'upload', ?, ?)
          ")->execute([$docId, $logMsg, actor_string($pdo)]);

          $pdo->commit();
          $uploadedIds[] = $docId;

        } catch (PDOException $e) {
          $pdo->rollBack();
          if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            $fileErrors[] = $origName . ': Duplikat erkannt.';
          } else {
            throw $e;
          }
        } catch (Throwable $e) {
          $pdo->rollBack();
          throw $e;
        }

      } catch (Throwable $e) {
        $fileErrors[] = $origName . ': ' . $e->getMessage();
      }
    }

    foreach ($fileErrors as $m) {
      $errors[] = $m;
    }

    if ($uploadedIds) {
      header(
        'Location: queue.php?uploaded=' .
        (int)$uploadedIds[count($uploadedIds) - 1] .
        '&count=' . count($uploadedIds)
      );
      exit;
    }

  } catch (Throwable $e) {
    $errors[] = $e->getMessage();
  }
}

ui_header('PDF Upload', 'upload');
?>

<div class="ui-box">
  <h2>PDF Upload</h2>

  <?php foreach ($errors as $err): ?>
    <div class="ui-err"><?= h($err) ?></div>
  <?php endforeach; ?>

  <div class="ui-muted" style="margin-top:0.25rem;">
    Hinweis: Weitere Gremien werden über die Administration angelegt.
  </div>

  <form method="post" enctype="multipart/form-data">
    <label class="ui-label" for="gremium_id">Gremium</label>
    <select class="ui-select" name="gremium_id" id="gremium_id" required>
      <option value="">Bitte wählen</option>
      <?php foreach ($gremien as $g): ?>
        <option value="<?= (int)$g['id'] ?>">
          <?= h((string)$g['name']) ?> (<?= h((string)$g['key']) ?>)
        </option>
      <?php endforeach; ?>
    </select>

    <label class="ui-label" for="pdf">PDFs</label>
    <input class="ui-input" type="file" name="pdf[]" id="pdf" accept="application/pdf" multiple required>
    <div class="ui-muted" style="margin-top:0.25rem;">
      Maximal <?= MAX_FILES_PER_UPLOAD ?> Dateien pro Upload
    </div>

    <div class="ui-actions">
      <button class="ui-btn" type="submit">Hochladen</button>
      <a class="ui-link" href="queue.php">Zur Queue</a>
    </div>
  </form>
</div>

<?php ui_footer(); ?>
