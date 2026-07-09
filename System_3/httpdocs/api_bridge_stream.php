<?php
// KommRAG – api_bridge_stream.php
// Streamt NDJSON von System 2 an das Frontend durch und speichert am Ende den Chat-Log.

declare(strict_types=1);
session_start();

require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/ratelimit.php';
require_once __DIR__ . '/lib/chatlog.php';

$config = require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Nur POST erlaubt.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || empty($data['query'])) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Keine Frage empfangen.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$query = trim((string)$data['query']);

if (mb_strlen($query) > 1000) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Frage zu lang (max. 1000 Zeichen).'], JSON_UNESCAPED_UNICODE);
    exit;
}

$injection_error = checkPromptInjection($query);
if ($injection_error) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $injection_error], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['kommrag_session_id'])) {
    $_SESSION['kommrag_session_id'] = bin2hex(random_bytes(16));
}
$session_id = $_SESSION['kommrag_session_id'];

$pdo     = getPdo($config);
$ip      = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ip_hash = hash('sha256', $ip);

$is_unlocked = isSessionUnlocked($pdo, $session_id);

if (!$is_unlocked) {
    $rate_ok = checkRateLimit(
        $pdo,
        $ip_hash,
        $config['rate_limit_window'],
        $config['rate_limit_requests']
    );

    if (!$rate_ok) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
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

$payload = json_encode([
    'query'         => $query,
    'session_id'    => $session_id,
    'top_k'         => $top_k,
    'gremium_key'   => $gremium_key,
    'year_from'     => $year_from,
    'year_to'       => $year_to,
    'answer_length' => $answer_length,
], JSON_UNESCAPED_UNICODE);

if ($payload === false) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Payload konnte nicht erstellt werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/x-ndjson; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Accel-Buffering: no');

ignore_user_abort(true);
set_time_limit(0);

@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');

if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}

while (ob_get_level() > 0) {
    @ob_end_flush();
}
ob_implicit_flush(true);

$finalAnswer     = '';
$finalSources    = [];
$finalCondensed  = null;
$finalClarify    = null;
$finalElapsedMs  = null;
$streamError     = null;
$httpCodeFromApi = 0;
$buffer          = '';

$forwardEvent = static function (array $event): void {
    echo json_encode($event, JSON_UNESCAPED_UNICODE) . "\n";
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    flush();
};

$consumeEvent = static function (
    array $event,
    string &$finalAnswer,
    array &$finalSources,
    ?string &$finalCondensed,
    ?string &$finalClarify,
    &$finalElapsedMs,
    ?string &$streamError
): void {
    $type = $event['type'] ?? '';

    if ($type === 'token') {
        $finalAnswer .= (string)($event['text'] ?? '');
        return;
    }

    if ($type === 'done') {
        $finalAnswer    = (string)($event['answer'] ?? $finalAnswer);
        $finalSources   = is_array($event['sources'] ?? null) ? $event['sources'] : [];
        $finalCondensed = isset($event['condensed_query']) ? (string)$event['condensed_query'] : $finalCondensed;
        $finalClarify   = isset($event['clarify']) ? $event['clarify'] : $finalClarify;
        $finalElapsedMs = $event['elapsed_ms'] ?? $finalElapsedMs;
        return;
    }

    if ($type === 'clarify') {
        $finalClarify   = isset($event['message']) ? (string)$event['message'] : $finalClarify;
        $finalCondensed = isset($event['condensed_query']) ? (string)$event['condensed_query'] : $finalCondensed;
        $finalElapsedMs = $event['elapsed_ms'] ?? $finalElapsedMs;
        return;
    }

    if ($type === 'error') {
        $streamError = isset($event['message']) ? (string)$event['message'] : 'Unbekannter Stream-Fehler';
    }
};

$ch = curl_init($config['api_base'] . '/chat/stream');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 0,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-API-Key: ' . $config['api_key'],
        'Accept-Encoding: identity',
        'Connection: keep-alive',
    ],
    CURLOPT_HEADER         => false,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
    CURLOPT_ENCODING       => 'identity',
    CURLOPT_BUFFERSIZE     => 128,
    CURLOPT_TCP_NODELAY    => 1,
    CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use (
        &$buffer,
        &$finalAnswer,
        &$finalSources,
        &$finalCondensed,
        &$finalClarify,
        &$finalElapsedMs,
        &$streamError,
        $consumeEvent,
        $forwardEvent
    ) {
        $buffer .= $chunk;

        while (($pos = strpos($buffer, "\n")) !== false) {
            $line   = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 1);

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $event = json_decode($line, true);
            if (!is_array($event)) {
                continue;
            }

            $consumeEvent(
                $event,
                $finalAnswer,
                $finalSources,
                $finalCondensed,
                $finalClarify,
                $finalElapsedMs,
                $streamError
            );

            $forwardEvent($event);
        }

        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();

        return strlen($chunk);
    },
]);

curl_exec($ch);
$curlError       = curl_error($ch);
$httpCodeFromApi = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$buffer = trim($buffer);
if ($buffer !== '') {
    $event = json_decode($buffer, true);
    if (is_array($event)) {
        $consumeEvent(
            $event,
            $finalAnswer,
            $finalSources,
            $finalCondensed,
            $finalClarify,
            $finalElapsedMs,
            $streamError
        );
        $forwardEvent($event);
    }
}

if ($curlError || $httpCodeFromApi !== 200) {
    $msg = $curlError ?: 'Fehler bei der Verarbeitung. Bitte versuche es später erneut.';
    $forwardEvent([
        'type'    => 'error',
        'message' => $msg,
    ]);
    exit;
}

if ($streamError) {
    // Stream-Fehler wurde bereits nach vorne gereicht, Logging läuft aber weiter.
}

if (!empty($finalSources)) {
    $filenames = array_filter(array_column($finalSources, 'filename'));
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

        foreach ($finalSources as &$source) {
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
    'condensed_query' => $finalCondensed,
    'answer'          => $finalAnswer !== '' ? $finalAnswer : null,
    'clarify'         => $finalClarify,
    'sources_json'    => json_encode($finalSources, JSON_UNESCAPED_UNICODE),
    'top_k'           => $top_k,
    'gremium_key'     => $gremium_key,
    'year_from'       => $year_from,
    'year_to'         => $year_to,
    'answer_length'   => $answer_length,
    'elapsed_ms'      => $finalElapsedMs,
]);

$forwardEvent([
    'type'            => 'meta',
    'log_id'          => $log_id,
    'sources'         => $finalSources,
    'condensed_query' => $finalCondensed,
    'clarify'         => $finalClarify,
    'elapsed_ms'      => $finalElapsedMs,
]);