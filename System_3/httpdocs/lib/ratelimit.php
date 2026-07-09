<?php
// lib/ratelimit.php – Rate-Limiting, Code-Einlösung, Usage-Tracking
// Hinweis: getPdo() ist in security.php definiert
declare(strict_types=1);

/**
 * Prüft und zählt das Rate-Limit (pro IP-Hash).
 */
function checkRateLimit(PDO $pdo, string $ip_hash, int $window_sec, int $max_requests): bool
{
    $window_start = date('Y-m-d H:i:s', (int)(floor(time() / $window_sec) * $window_sec));

    $stmt = $pdo->prepare('
        INSERT INTO rate_limits (ip_hash, window_start, request_count)
        VALUES (?, ?, 1)
        ON DUPLICATE KEY UPDATE request_count = request_count + 1
    ');
    $stmt->execute([$ip_hash, $window_start]);

    $stmt = $pdo->prepare('
        SELECT request_count FROM rate_limits
        WHERE ip_hash = ? AND window_start = ?
    ');
    $stmt->execute([$ip_hash, $window_start]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // Gelegentlich alte Einträge aufräumen
    if (rand(1, 50) === 1) {
        $cutoff = date('Y-m-d H:i:s', time() - $window_sec * 2);
        $pdo->prepare('DELETE FROM rate_limits WHERE window_start < ?')->execute([$cutoff]);
    }

    return $row && $row['request_count'] <= $max_requests;
}

/**
 * Prüft ob eine Session per Code freigeschaltet wurde.
 */
function isSessionUnlocked(PDO $pdo, string $session_id): bool
{
    $stmt = $pdo->prepare('
        SELECT 1 FROM unlocked_sessions
        WHERE session_id = ?
          AND (expires_at IS NULL OR expires_at > NOW())
        LIMIT 1
    ');
    $stmt->execute([$session_id]);
    return (bool)$stmt->fetch();
}

/**
 * Gibt die access_code_id für eine freigeschaltete Session zurück (oder null).
 */
function getSessionCodeId(PDO $pdo, string $session_id): ?int
{
    $stmt = $pdo->prepare('
        SELECT access_code_id FROM unlocked_sessions
        WHERE session_id = ?
          AND (expires_at IS NULL OR expires_at > NOW())
        LIMIT 1
    ');
    $stmt->execute([$session_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['access_code_id'] : null;
}

/**
 * Prüft einen Code per Token (für persistent/LocalStorage-Codes).
 * Gibt die access_code_id zurück oder null.
 */
function validatePersistentCode(PDO $pdo, string $code): ?int
{
    $stmt = $pdo->prepare('
        SELECT id, max_uses, used_count, expires_at, code_type
        FROM access_codes
        WHERE code = ? AND is_active = 1 AND code_type = ?
        LIMIT 1
    ');
    $stmt->execute([trim($code), 'persistent']);
    $ac = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ac) return null;
    if ($ac['expires_at'] && strtotime($ac['expires_at']) < time()) return null;

    return (int)$ac['id'];
}

/**
 * Schaltet eine Session für einen bereits eingelösten Persistent-Code frei.
 * Erhöht NICHT den used_count (der wurde beim erstmaligen Einlösen gezählt).
 */
function unlockSessionForPersistentCode(PDO $pdo, int $code_id, string $session_id, string $ip_hash): void
{
    // Code-Ablauf holen
    $stmt = $pdo->prepare('SELECT expires_at FROM access_codes WHERE id = ?');
    $stmt->execute([$code_id]);
    $ac = $stmt->fetch(PDO::FETCH_ASSOC);

    $pdo->prepare('
        INSERT INTO unlocked_sessions (session_id, access_code_id, ip_hash, expires_at)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            access_code_id = VALUES(access_code_id),
            unlocked_at = NOW(),
            expires_at = VALUES(expires_at)
    ')->execute([$session_id, $code_id, $ip_hash, $ac['expires_at'] ?? null]);
}

/**
 * Löst einen Code ein und schaltet die Session frei.
 *
 * Session-Code:     expires_at = Session-Lifetime (default 24h)
 * Persistent-Code:  expires_at = Code's eigenes expires_at (oder NULL = unbegrenzt)
 *
 * Gibt ['success' => true, 'code_type' => '...', 'code' => '...'] oder ['success' => false] zurück.
 */
function redeemAccessCode(PDO $pdo, string $code, string $session_id, string $ip_hash, int $session_lifetime = 86400): array
{
    $stmt = $pdo->prepare('
        SELECT id, code, max_uses, used_count, expires_at, code_type
        FROM access_codes
        WHERE code = ? AND is_active = 1
        LIMIT 1
    ');
    $stmt->execute([trim($code)]);
    $ac = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ac) return ['success' => false];
    if ($ac['expires_at'] && strtotime($ac['expires_at']) < time()) return ['success' => false];

    // max_uses: 0 = unbegrenzt
    if ($ac['max_uses'] > 0 && $ac['used_count'] >= $ac['max_uses']) return ['success' => false];

    // Code als benutzt markieren
    $pdo->prepare('UPDATE access_codes SET used_count = used_count + 1 WHERE id = ?')
        ->execute([$ac['id']]);

    // Session-Ablauf bestimmen
    if ($ac['code_type'] === 'persistent') {
        // Persistent: Ablauf = Code-Ablauf (oder NULL = unbegrenzt)
        $session_expires = $ac['expires_at'];
    } else {
        // Session: Ablauf = jetzt + session_lifetime
        $session_expires = date('Y-m-d H:i:s', time() + $session_lifetime);
    }

    // Session freischalten
    $pdo->prepare('
        INSERT INTO unlocked_sessions (session_id, access_code_id, ip_hash, expires_at)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            access_code_id = VALUES(access_code_id),
            unlocked_at = NOW(),
            expires_at = VALUES(expires_at)
    ')->execute([$session_id, $ac['id'], $ip_hash, $session_expires]);

    return [
        'success'   => true,
        'code_type' => $ac['code_type'],
        'code'      => $ac['code'],
    ];
}

/**
 * Zählt eine Anfrage im Kosten-Tracking (pro Code, pro Qualitätsstufe).
 * Keine Verbindung zu chat_logs — nur aggregierte Zähler.
 */
function trackCodeUsage(PDO $pdo, int $access_code_id, string $quality): void
{
    $quality = in_array($quality, ['small', 'medium', 'large'], true) ? $quality : 'small';

    $pdo->prepare('
        INSERT INTO code_usage_stats (access_code_id, quality, request_count)
        VALUES (?, ?, 1)
        ON DUPLICATE KEY UPDATE
            request_count = request_count + 1,
            last_used_at = NOW()
    ')->execute([$access_code_id, $quality]);
}
