<?php
/**
 * KommRAG – admin_api.php
 * Backend-Endpunkte für die Admin-Oberfläche.
 *
 * Tabellen-Mapping (bestehendes Schema):
 *   users           → admin (Flag), username, password_hash, is_active
 *   access_codes    → code, label, used_count, max_uses, is_active, expires_at, created_by
 *   chat_logs       → session_id, ip_hash, query, answer, elapsed_ms, …
 *   chat_feedback   → chat_log_id, rating, comment (separate Tabelle)
 */

declare(strict_types=1);
session_start();

$config = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

// ─── DB ───────────────────────────────────────────────

function getAdminPdo(array $config): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
            $config['db_user'],
            $config['db_pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}

// ─── Auth-Prüfung ─────────────────────────────────────

function requireAdmin(): void
{
    if (empty($_SESSION['admin_user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Nicht eingeloggt.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ─── Request ──────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Nur POST erlaubt.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw    = file_get_contents('php://input');
$data   = json_decode($raw, true);
$action = $data['action'] ?? '';

$pdo = getAdminPdo($config);

// ─── Routen ───────────────────────────────────────────

switch ($action) {

    // ── Login ──────────────────────────────────────────
    case 'login':
        $username = trim($data['username'] ?? '');
        $password = $data['password'] ?? '';

        if (!$username || !$password) {
            http_response_code(400);
            echo json_encode(['error' => 'Benutzername und Passwort erforderlich.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT id, username, password_hash, admin, is_active
            FROM users
            WHERE username = ?
            LIMIT 1
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Ungültige Anmeldedaten.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!$user['is_active']) {
            http_response_code(403);
            echo json_encode(['error' => 'Konto deaktiviert.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!$user['admin']) {
            http_response_code(403);
            echo json_encode(['error' => 'Kein Admin-Zugang.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $_SESSION['admin_user_id'] = $user['id'];
        $_SESSION['admin_username'] = $user['username'];

        $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([$user['id']]);

        echo json_encode([
            'success'  => true,
            'username' => $user['username'],
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ── Logout ─────────────────────────────────────────
    case 'logout':
        unset($_SESSION['admin_user_id'], $_SESSION['admin_username']);
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        break;

    // ── Auth-Status ────────────────────────────────────
    case 'check_auth':
        echo json_encode([
            'authenticated' => !empty($_SESSION['admin_user_id']),
            'username'      => $_SESSION['admin_username'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ── Codes auflisten ────────────────────────────────
    case 'list_codes':
        requireAdmin();

        $stmt = $pdo->query("
            SELECT
                id,
                code,
                label,
                code_type,
                is_active,
                max_uses,
                used_count,
                expires_at,
                created_at
            FROM access_codes
            ORDER BY created_at DESC
        ");
        $codes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Usage-Stats pro Code laden
        $code_ids = array_column($codes, 'id');
        $usage_map = [];
        if ($code_ids) {
            $placeholders = implode(',', array_fill(0, count($code_ids), '?'));
            $ustmt = $pdo->prepare("
                SELECT access_code_id, quality, request_count
                FROM code_usage_stats
                WHERE access_code_id IN ({$placeholders})
            ");
            $ustmt->execute($code_ids);
            foreach ($ustmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $usage_map[$row['access_code_id']][$row['quality']] = (int)$row['request_count'];
            }
        }

        foreach ($codes as &$c) {
            $c['usage'] = $usage_map[$c['id']] ?? [];
        }
        unset($c);

        echo json_encode(['codes' => $codes], JSON_UNESCAPED_UNICODE);
        break;

    // ── Code erstellen ─────────────────────────────────
    case 'create_code':
        requireAdmin();

        $label     = trim($data['label'] ?? '');
        $custom    = trim($data['custom_code'] ?? '');
        $max_uses  = max(0, (int) ($data['max_uses'] ?? 1));  // 0 = unbegrenzt
        $code_type = in_array(($data['code_type'] ?? ''), ['session', 'persistent'], true)
            ? $data['code_type']
            : 'session';
        $expires   = !empty($data['expires_at']) ? $data['expires_at'] : null;

        if ($custom) {
            $code = $custom;
        } else {
            $code = strtoupper(
                substr(bin2hex(random_bytes(2)), 0, 4) . '-' .
                substr(bin2hex(random_bytes(2)), 0, 4) . '-' .
                substr(bin2hex(random_bytes(2)), 0, 4)
            );
        }

        $check = $pdo->prepare("SELECT id FROM access_codes WHERE code = ?");
        $check->execute([$code]);
        if ($check->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'Dieser Code existiert bereits.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO access_codes (code, label, code_type, is_active, max_uses, used_count, expires_at, created_by, created_at)
            VALUES (?, ?, ?, 1, ?, 0, ?, ?, NOW())
        ");
        $stmt->execute([
            $code,
            $label ?: null,
            $code_type,
            $max_uses,
            $expires,
            $_SESSION['admin_user_id'],
        ]);

        echo json_encode([
            'success' => true,
            'code'    => $code,
            'id'      => (int) $pdo->lastInsertId(),
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ── Code löschen ───────────────────────────────────
    case 'delete_code':
        requireAdmin();

        $id = (int) ($data['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Keine Code-ID angegeben.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM access_codes WHERE id = ?");
        $stmt->execute([$id]);

        echo json_encode(['success' => true, 'deleted_id' => $id], JSON_UNESCAPED_UNICODE);
        break;

    // ── Code aktivieren/deaktivieren ───────────────────
    case 'toggle_code':
        requireAdmin();

        $id = (int) ($data['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Keine Code-ID angegeben.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $pdo->prepare("UPDATE access_codes SET is_active = NOT is_active WHERE id = ?")->execute([$id]);

        $stmt = $pdo->prepare("SELECT is_active FROM access_codes WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success'   => true,
            'id'        => $id,
            'is_active' => (bool) ($row['is_active'] ?? false),
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ── Chat-Statistiken ───────────────────────────────
    case 'chat_stats':
        requireAdmin();

        $period = $data['period'] ?? '7d';
        $interval_map = [
            '24h' => 1,
            '7d'  => 7,
            '30d' => 30,
            'all' => 0,
        ];
        $days = $interval_map[$period] ?? 7;

        // Zeitfilter als prepared statement
        if ($days > 0) {
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
            $time_where = "WHERE cl.created_at >= ?";
            $time_params = [$cutoff];
        } else {
            $time_where = "";
            $time_params = [];
        }

        // Gesamtanzahl
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM chat_logs cl {$time_where}");
        $stmt->execute($time_params);
        $total = (int) $stmt->fetchColumn();

        // Anfragen pro Tag
        $stmt = $pdo->prepare("
            SELECT DATE(cl.created_at) AS day, COUNT(*) AS cnt
            FROM chat_logs cl {$time_where}
            GROUP BY DATE(cl.created_at)
            ORDER BY day ASC
        ");
        $stmt->execute($time_params);
        $per_day = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Durchschnittliche Antwortzeit
        $stmt = $pdo->prepare("SELECT COALESCE(AVG(cl.elapsed_ms), 0) FROM chat_logs cl {$time_where}");
        $stmt->execute($time_params);
        $avg_ms = (float) $stmt->fetchColumn();

        // Feedback (separate Tabelle)
        if ($days > 0) {
            $stmt = $pdo->prepare("
                SELECT
                    SUM(CASE WHEN cf.rating = 1 THEN 1 ELSE 0 END)  AS positive,
                    SUM(CASE WHEN cf.rating = -1 THEN 1 ELSE 0 END) AS negative
                FROM chat_feedback cf
                JOIN chat_logs cl ON cl.id = cf.chat_log_id
                WHERE cl.created_at >= ?
            ");
            $stmt->execute([$cutoff]);
        } else {
            $stmt = $pdo->query("
                SELECT
                    SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END)  AS positive,
                    SUM(CASE WHEN rating = -1 THEN 1 ELSE 0 END) AS negative
                FROM chat_feedback
            ");
        }
        $feedback = $stmt->fetch(PDO::FETCH_ASSOC);

        // Ohne Feedback
        if ($days > 0) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM chat_logs cl
                LEFT JOIN chat_feedback cf ON cf.chat_log_id = cl.id
                WHERE cl.created_at >= ? AND cf.id IS NULL
            ");
            $stmt->execute([$cutoff]);
        } else {
            $stmt = $pdo->query("
                SELECT COUNT(*)
                FROM chat_logs cl
                LEFT JOIN chat_feedback cf ON cf.chat_log_id = cl.id
                WHERE cf.id IS NULL
            ");
        }
        $feedback['none'] = (int) $stmt->fetchColumn();

        // Top Gremien
        $stmt = $pdo->prepare("
            SELECT COALESCE(cl.gremium_key, '(alle)') AS gremium, COUNT(*) AS cnt
            FROM chat_logs cl {$time_where}
            GROUP BY cl.gremium_key
            ORDER BY cnt DESC LIMIT 10
        ");
        $stmt->execute($time_params);
        $top_gremien = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Unique Sessions
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT cl.session_id) FROM chat_logs cl {$time_where}");
        $stmt->execute($time_params);
        $unique_sessions = (int) $stmt->fetchColumn();

        echo json_encode([
            'total'           => $total,
            'unique_sessions' => $unique_sessions,
            'avg_elapsed_ms'  => round($avg_ms),
            'per_day'         => $per_day,
            'feedback'        => $feedback,
            'top_gremien'     => $top_gremien,
            'period'          => $period,
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ── Chat-Logs (paginiert) ──────────────────────────
    case 'chat_logs':
        requireAdmin();

        $page     = max(1, (int) ($data['page'] ?? 1));
        $per_page = min(100, max(10, (int) ($data['per_page'] ?? 25)));
        $search   = trim($data['search'] ?? '');
        $rating   = $data['rating_filter'] ?? null;
        $offset   = ($page - 1) * $per_page;

        $where_clauses = [];
        $params = [];

        if ($search) {
            $where_clauses[] = "(cl.query LIKE ? OR cl.answer LIKE ? OR cl.condensed_query LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        if ($rating === 1 || $rating === -1) {
            $where_clauses[] = "cf.rating = ?";
            $params[] = $rating;
        } elseif ($rating === 'none') {
            $where_clauses[] = "cf.id IS NULL";
        }

        $where = $where_clauses ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        $count_stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT cl.id)
            FROM chat_logs cl
            LEFT JOIN chat_feedback cf ON cf.chat_log_id = cl.id
            {$where}
        ");
        $count_stmt->execute($params);
        $total = (int) $count_stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT
                cl.id,
                cl.session_id,
                cl.query,
                cl.condensed_query,
                cl.answer,
                cl.clarify,
                cl.top_k,
                cl.gremium_key,
                cl.year_from,
                cl.year_to,
                cl.answer_length,
                cl.elapsed_ms,
                cl.created_at,
                cf.rating
            FROM chat_logs cl
            LEFT JOIN chat_feedback cf ON cf.chat_log_id = cl.id
            {$where}
            ORDER BY cl.created_at DESC
            LIMIT {$per_page} OFFSET {$offset}
        ");
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($logs as &$log) {
            $log['answer_preview'] = $log['answer']
                ? mb_substr($log['answer'], 0, 200) . (mb_strlen($log['answer']) > 200 ? '…' : '')
                : null;
        }
        unset($log);

        echo json_encode([
            'logs'        => $logs,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => max(1, (int) ceil($total / $per_page)),
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ── Einzelnen Chat laden ───────────────────────────
    case 'chat_detail':
        requireAdmin();

        $id = (int) ($data['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Keine Log-ID angegeben.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT cl.*, cf.rating, cf.comment AS feedback_comment
            FROM chat_logs cl
            LEFT JOIN chat_feedback cf ON cf.chat_log_id = cl.id
            WHERE cl.id = ?
        ");
        $stmt->execute([$id]);
        $log = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$log) {
            http_response_code(404);
            echo json_encode(['error' => 'Eintrag nicht gefunden.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode(['log' => $log], JSON_UNESCAPED_UNICODE);
        break;

    // ── Unbekannte Aktion ──────────────────────────────
    default:
        http_response_code(400);
        echo json_encode(['error' => "Unbekannte Aktion: {$action}"], JSON_UNESCAPED_UNICODE);
        break;
}
