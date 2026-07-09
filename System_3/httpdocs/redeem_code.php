<?php
session_start();
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/ratelimit.php';

header('Content-Type: application/json');

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
$code = trim($data['code'] ?? '');

if (!$code) {
    echo json_encode(['success' => false, 'error' => 'Kein Code eingegeben.']);
    exit;
}

$pdo        = getPdo($config);
$session_id = $_SESSION['kommrag_session_id'] ?? bin2hex(random_bytes(16));
$ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ip_hash    = hash('sha256', $ip);

$result = redeemAccessCode(
    $pdo,
    $code,
    $session_id,
    $ip_hash,
    $config['session_lifetime'] ?? 86400
);

if ($result['success']) {
    echo json_encode([
        'success'   => true,
        'code_type' => $result['code_type'],
        'code'      => $result['code'],
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error'   => 'Ungültiger oder abgelaufener Code.',
    ]);
}
