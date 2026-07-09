<?php
session_start();
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/chatlog.php';

header('Content-Type: application/json');

$raw    = file_get_contents('php://input');
$data   = json_decode($raw, true);
$log_id = (int)($data['log_id'] ?? 0);
$rating = (int)($data['rating'] ?? 0);

if (!$log_id || !in_array($rating, [1, -1])) {
    echo json_encode(['ok' => false]);
    exit;
}

$pdo        = getPdo($config);
$session_id = $_SESSION['kommrag_session_id'] ?? 'unknown';
saveFeedback($pdo, $log_id, $session_id, $rating, null);
echo json_encode(['ok' => true]);
