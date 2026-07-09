<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function lock_now_token(): string {
  return bin2hex(random_bytes(32));
}

function get_lock(PDO $pdo, int $docId): ?array {
  $st = $pdo->prepare("
    SELECT document_id, locked_by, locked_by_name, lock_token, locked_at, expires_at
    FROM document_locks
    WHERE document_id=? LIMIT 1
  ");
  $st->execute([$docId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function is_lock_expired(array $lock): bool {
  $exp = (string)($lock['expires_at'] ?? '');
  if ($exp === '') return true;
  return strtotime($exp) <= time();
}

function acquire_lock(PDO $pdo, int $docId, int $userId, string $userName, int $ttlSeconds = 2700): array {
  if ($userId <= 0) {
    return ['ok' => false, 'reason' => 'no_user'];
  }

  $pdo->beginTransaction();
  try {
    $lock = get_lock($pdo, $docId);

    if ($lock && !is_lock_expired($lock) && (int)$lock['locked_by'] !== $userId) {
      $pdo->commit();
      return [
        'ok' => false,
        'reason' => 'locked_by_other',
        'locked_by_name' => (string)($lock['locked_by_name'] ?? ''),
        'expires_at' => (string)($lock['expires_at'] ?? ''),
      ];
    }

    $token = lock_now_token();

    $pdo->prepare("
      INSERT INTO document_locks
        (document_id, locked_by, locked_by_name, lock_token, locked_at, expires_at)
      VALUES
        (?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND))
      ON DUPLICATE KEY UPDATE
        locked_by = VALUES(locked_by),
        locked_by_name = VALUES(locked_by_name),
        lock_token = VALUES(lock_token),
        locked_at = NOW(),
        expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
    ")->execute([$docId, $userId, $userName, $token, $ttlSeconds, $ttlSeconds]);

    $pdo->commit();

    auth_start();
    $_SESSION['lock_token_' . $docId] = $token;

    return ['ok' => true, 'token' => $token];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    return ['ok' => false, 'reason' => 'error', 'message' => $e->getMessage()];
  }
}

function refresh_lock(PDO $pdo, int $docId, int $userId, int $ttlSeconds = 2700): void {
  if ($userId <= 0) return;

  $pdo->prepare("
    UPDATE document_locks
    SET expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND), locked_at = NOW()
    WHERE document_id = ? AND locked_by = ?
  ")->execute([$ttlSeconds, $docId, $userId]);
}

function release_lock(PDO $pdo, int $docId, int $userId): void {
  $pdo->prepare("DELETE FROM document_locks WHERE document_id=? AND locked_by=?")->execute([$docId, $userId]);

  auth_start();
  unset($_SESSION['lock_token_' . $docId]);
}

function force_unlock(PDO $pdo, int $docId): void {
  $pdo->prepare("DELETE FROM document_locks WHERE document_id=?")->execute([$docId]);

  auth_start();
  unset($_SESSION['lock_token_' . $docId]);
}

function has_own_lock(PDO $pdo, int $docId, int $userId): bool {
  if ($userId <= 0) return false;

  $st = $pdo->prepare("SELECT locked_by, expires_at FROM document_locks WHERE document_id=? LIMIT 1");
  $st->execute([$docId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) return false;
  if (strtotime((string)($row['expires_at'] ?? '')) <= time()) return false;
  return (int)$row['locked_by'] === $userId;
}
