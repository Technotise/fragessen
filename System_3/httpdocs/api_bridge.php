<?php
// KommRAG – api_bridge.php
// JSON-Bridge-Endpunkt mit Code-Usage-Tracking

declare(strict_types=1);
session_start();

require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/ratelimit.php';
require_once __DIR__ . '/lib/chatlog.php';

$config = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Erkennt offensichtlich sinnlose Eingaben, die nicht an das Backend
 * weitergeleitet werden müssen. Gibt einen Klarstellungstext zurück
 * oder null, wenn die Eingabe ok ist.
 */
function detectNonsenseQuery(string $query): ?string {
    $q = trim($query);
    $len = mb_strlen($q);

    // Zu kurz (< 3 Zeichen, z.B. "ab", "hi")
    if ($len < 3) {
        return 'Bitte formuliere eine vollständige Frage zu den Ratsprotokollen der Stadt Essen.';
    }

    // Nur Sonderzeichen, Zahlen oder Emojis — keine Buchstaben
    $letters_only = preg_replace('/[^a-zA-ZäöüÄÖÜß]/u', '', $q);
    if (mb_strlen($letters_only) < 2) {
        return 'Das scheint keine Frage zu sein. Stelle eine Frage zu den kommunalen Ratsprotokollen.';
    }

    // Starke Zeichenwiederholung (z.B. "aaaaaaa", "hahahaha", "boo boo boo")
    $lower = mb_strtolower($q, 'UTF-8');
    // Gleicher Buchstabe ≥5× hintereinander
    if (preg_match('/(.)\1{4,}/u', $lower)) {
        return 'Das scheint keine Frage zu sein. Was möchtest du über die Essener Ratsprotokolle wissen?';
    }
    // Gleiches Wort ≥3× wiederholt
    if (preg_match('/\b(\w{2,})\b(?:\s+\1\b){2,}/ui', $lower)) {
        return 'Das scheint keine Frage zu sein. Was möchtest du über die Essener Ratsprotokolle wissen?';
    }

    // Kein erkennbares deutsches Wort (≥3 Buchstaben) vorhanden
    // Einfache Heuristik: mindestens ein Wort mit ≥3 Buchstaben muss enthalten sein
    if (!preg_match('/[a-zA-ZäöüÄÖÜß]{3,}/u', $q)) {
        return 'Bitte formuliere eine verständliche Frage zu den Ratsprotokollen.';
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Nur POST erlaubt.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || empty($data['query'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Keine Frage empfangen.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$query = trim((string)$data['query']);

if (mb_strlen($query) > 1000) {
    http_response_code(400);
    echo json_encode(['error' => 'Frage zu lang (max. 1000 Zeichen).'], JSON_UNESCAPED_UNICODE);
    exit;
}

$injection_error = checkPromptInjection($query);
if ($injection_error) {
    http_response_code(400);
    echo json_encode(['error' => $injection_error], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── Nonsense-/Gibberish-Filter (spart API-Kosten) ────────────────
$nonsense_reason = detectNonsenseQuery($query);
if ($nonsense_reason) {
    echo json_encode([
        'clarify'         => $nonsense_reason,
        'answer'          => null,
        'sources'         => [],
        'condensed_query' => $query,
        'elapsed_ms'      => 0,
        'log_id'          => null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['kommrag_session_id'])) {
    $_SESSION['kommrag_session_id'] = bin2hex(random_bytes(16));
}
$session_id = $_SESSION['kommrag_session_id'];

$pdo     = getPdo($config);
$ip      = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ip_hash = hash('sha256', $ip);

// ─── Persistent-Code aus Header prüfen ────────────────
$persistent_code_id = null;
$bearer_code = trim($data['access_code'] ?? '');
if ($bearer_code) {
    $persistent_code_id = validatePersistentCode($pdo, $bearer_code);
    if ($persistent_code_id) {
        // Persistent-Code → Session freischalten (falls noch nicht)
        if (!isSessionUnlocked($pdo, $session_id)) {
            redeemAccessCode($pdo, $bearer_code, $session_id, $ip_hash, $config['session_lifetime'] ?? 86400);
        }
    }
}

$is_unlocked = isSessionUnlocked($pdo, $session_id);

if (!$is_unlocked) {
    $rate_ok = checkRateLimit($pdo, $ip_hash, $config['rate_limit_window'], $config['rate_limit_requests']);
    if (!$rate_ok) {
        http_response_code(429);
        echo json_encode([
            'error'        => 'Zu viele Anfragen. Bitte warte einen Moment oder gib deinen Zugangscode ein.',
            'rate_limited' => true,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$top_k         = max(3, min(20, (int)($data['top_k'] ?? 10)));
$gremium_key   = $data['gremium_key'] ?? null;
$year_from     = isset($data['year_from']) && $data['year_from'] !== '' ? (int)$data['year_from'] : null;
$year_to       = isset($data['year_to']) && $data['year_to'] !== '' ? (int)$data['year_to'] : null;
$answer_length = in_array(($data['answer_length'] ?? ''), ['short', 'normal', 'detailed'], true)
    ? $data['answer_length']
    : 'normal';
$quality       = in_array(($data['quality'] ?? ''), ['small', 'medium', 'large'], true)
    ? $data['quality']
    : 'small';
$search_mode   = in_array(($data['search_mode'] ?? ''), ['relevant', 'recent', 'breadth'], true)
    ? $data['search_mode']
    : 'relevant';

$payload = json_encode([
    'query'         => $query,
    'session_id'    => $session_id,
    'top_k'         => $top_k,
    'gremium_key'   => $gremium_key,
    'year_from'     => $year_from,
    'year_to'       => $year_to,
    'answer_length' => $answer_length,
    'quality'       => $quality,
    'search_mode'   => $search_mode,
], JSON_UNESCAPED_UNICODE);

$ch = curl_init($config['api_base'] . '/chat');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 180,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-API-Key: ' . $config['api_key'],
    ],
]);

$response   = curl_exec($ch);
$curl_error = curl_error($ch);
$http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curl_error || !$response) {
    http_response_code(502);
    echo json_encode(['error' => 'System 2 nicht erreichbar. Bitte versuche es später erneut.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = json_decode($response, true);

if ($http_code !== 200 || !$result) {
    http_response_code(502);
    echo json_encode(['error' => 'Fehler bei der Verarbeitung. Bitte versuche es später erneut.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!empty($result['sources'])) {
    $filenames = array_filter(array_column($result['sources'], 'filename'));
    $filenames = array_values(array_unique($filenames));

    if ($filenames) {
        $placeholders = implode(',', array_fill(0, count($filenames), '?'));
        $stmt = $pdo->prepare("
            SELECT id, original_filename
            FROM documents
            WHERE original_filename IN ($placeholders)
        ");
        $stmt->execute($filenames);

        $id_map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id_map[$row['original_filename']] = $row['id'];
        }

        foreach ($result['sources'] as &$source) {
            if (!empty($source['filename']) && isset($id_map[$source['filename']])) {
                $source['download_id'] = $id_map[$source['filename']];
            }
        }
        unset($source);
    }
}

$log_id = saveChatLog($pdo, [
    'session_id'      => $session_id,
    'ip_hash'         => $ip_hash,
    'query'           => $query,
    'condensed_query' => $result['condensed_query'] ?? null,
    'answer'          => $result['answer'] ?? null,
    'clarify'         => $result['clarify'] ?? null,
    'sources_json'    => json_encode($result['sources'] ?? [], JSON_UNESCAPED_UNICODE),
    'top_k'           => $top_k,
    'gremium_key'     => $gremium_key,
    'year_from'       => $year_from,
    'year_to'         => $year_to,
    'answer_length'   => $answer_length,
    'quality'         => $quality,
    'search_mode'     => $search_mode,
    'elapsed_ms'      => $result['elapsed_ms'] ?? null,
]);

// ─── Usage-Tracking (Kosten pro Code, ohne Chat-Zuordnung) ───
$active_code_id = $persistent_code_id ?? getSessionCodeId($pdo, $session_id);
if ($active_code_id) {
    trackCodeUsage($pdo, $active_code_id, $quality);
}

$result['log_id'] = $log_id;

echo json_encode($result, JSON_UNESCAPED_UNICODE);
