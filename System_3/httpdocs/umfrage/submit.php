<?php
// /umfrage/submit.php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/questions.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Exception mit Error-Code, damit das Frontend differenziert reagieren kann.
 */
class SurveyException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'generic',
        public readonly int $httpStatus = 400
    ) {
        parent::__construct($message);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Nur POST erlaubt.']);
    exit;
}

$stage = (int)($_GET['stage'] ?? 0);
if (!in_array($stage, [1, 2], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unbekannte Stage.']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || empty($data['token'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Anfrage.']);
    exit;
}

$token = (string)$data['token'];
if (($_SESSION['survey_token'] ?? '') !== $token) {
    http_response_code(403);
    echo json_encode(['error' => 'Session-Token ungültig.']);
    exit;
}

try {
    $pdo = surveyPdo();
    $pdo->beginTransaction();

    if ($stage === 1) {
        saveStage1($pdo, $token, $data);
    } else {
        saveStage2($pdo, $token, $data);
    }

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (SurveyException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[survey/submit] ' . $e->errorCode . ': ' . $e->getMessage());
    http_response_code($e->httpStatus);
    echo json_encode([
        'error'      => $e->getMessage(),
        'error_code' => $e->errorCode,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[survey/submit] UNHANDLED: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error'      => 'Speicherfehler. Bitte erneut versuchen.',
        'error_code' => 'server_error',
    ]);
}

// ─────────────────────────────────────────────

function saveStage1(PDO $pdo, string $token, array $data): void
{
    $role           = (string)($data['role'] ?? '');
    $gender         = (string)($data['gender'] ?? '');
    $risFamiliarity = (string)($data['ris_familiarity'] ?? 'na');
    $consent        = !empty($data['consent']);

    $validRoles   = ['bv', 'rat', 'verwaltung', 'buergerschaft'];
    $validGenders = ['weiblich', 'maennlich', 'divers', 'keine_angabe'];
    $validRis     = ['nutze', 'kenne_nur', 'kenne_nicht', 'na'];

    if (!in_array($role, $validRoles, true))         { throw new SurveyException('Ungültige Rolle.', 'invalid_role', 400); }
    if (!in_array($gender, $validGenders, true))     { throw new SurveyException('Ungültiges Geschlecht.', 'invalid_gender', 400); }
    if (!in_array($risFamiliarity, $validRis, true)) { throw new SurveyException('Ungültige RIS-Angabe.', 'invalid_ris', 400); }
    if (!$consent)                                   { throw new SurveyException('Einwilligung fehlt.', 'missing_consent', 400); }

    $stmt = $pdo->prepare("SELECT id FROM survey_participants WHERE session_token = ?");
    $stmt->execute([$token]);
    $existing = $stmt->fetch();

    if ($existing) {
        throw new SurveyException(
            'Diese Umfrage wurde mit dieser Sitzung bereits abgeschickt.',
            'already_submitted',
            409
        );
    }

    $now = date('Y-m-d H:i:s');
    $source = $_SESSION['survey_source'] ?? 'direct';

    $stmt = $pdo->prepare("
        INSERT INTO survey_participants
          (session_token, role, gender, ris_familiarity, source,
           consent_given, consent_timestamp,
           started_at, stage1_completed_at,
           ip_hash, user_agent_hash)
        VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $token, $role, $gender, $risFamiliarity, $source,
        $now, $now, $now,
        clientIpHash(), userAgentHash(),
    ]);

    $responses = $data['responses'] ?? [];
    if (!is_array($responses)) { $responses = []; }

    saveResponses($pdo, $token, $responses, stage1Questions());
}

function saveStage2(PDO $pdo, string $token, array $data): void
{
    $stmt = $pdo->prepare("SELECT stage1_completed_at FROM survey_participants WHERE session_token = ?");
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row || !$row['stage1_completed_at']) {
        throw new SurveyException(
            'Der erste Teil der Umfrage ist nicht abgeschlossen. Bitte starten Sie neu.',
            'stage1_missing',
            409
        );
    }

    $responses = $data['responses'] ?? [];
    if (!is_array($responses)) { $responses = []; }

    saveResponses($pdo, $token, $responses, stage2Questions());

    $stmt = $pdo->prepare("UPDATE survey_participants SET stage2_completed_at = ? WHERE session_token = ?");
    $stmt->execute([date('Y-m-d H:i:s'), $token]);
}

function saveResponses(PDO $pdo, string $token, array $responses, array $questions): void
{
    $validKeys = array_column($questions, 'key');

    $stmt = $pdo->prepare("
        INSERT INTO survey_responses (session_token, question_key, answer_numeric, answer_text, created_at)
        VALUES (?, ?, ?, ?, ?)
    ");

    $now = date('Y-m-d H:i:s');

    foreach ($responses as $key => $value) {
        if (!in_array($key, $validKeys, true)) { continue; }

        $q = null;
        foreach ($questions as $qDef) {
            if ($qDef['key'] === $key) { $q = $qDef; break; }
        }
        if (!$q) { continue; }

        $numeric = null;
        $text    = null;

        if (in_array($q['type'], ['likert5', 'likert7', 'nps'], true)) {
            $numeric = (int)$value;

            if ($q['type'] === 'likert5' && ($numeric < 1 || $numeric > 5)) continue;
            if ($q['type'] === 'likert7' && ($numeric < 1 || $numeric > 7)) continue;
            if ($q['type'] === 'nps'     && ($numeric < 0 || $numeric > 10)) continue;
        } elseif ($q['type'] === 'textarea') {
            $text = mb_substr(trim((string)$value), 0, 2000);
            if ($text === '') continue;
        } else {
            continue;
        }

        $stmt->execute([$token, $key, $numeric, $text, $now]);
    }
}
