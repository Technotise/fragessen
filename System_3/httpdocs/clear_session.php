<?php
// clear_session.php – Gesprächsverlauf auf System 2 löschen
session_start();
$config = require __DIR__ . '/config.php';

if (!empty($_SESSION['kommrag_session_id'])) {
    $sid = $_SESSION['kommrag_session_id'];
    $ch  = curl_init($config['api_base'] . '/session?' . http_build_query(['session_id' => $sid]));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_CUSTOMREQUEST   => 'DELETE',
        CURLOPT_TIMEOUT         => 5,
        CURLOPT_HTTPHEADER      => ['X-API-Key: ' . $config['api_key']],
    ]);
    curl_exec($ch);
    curl_close($ch);
    // Neue Session-ID vergeben
    $_SESSION['kommrag_session_id'] = bin2hex(random_bytes(16));
}
echo json_encode(['ok' => true]);
