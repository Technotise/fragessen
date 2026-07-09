<?php
// /umfrage/clear_session.php
// Setzt die Umfrage-Session zurück. Löscht KEINE Daten in der DB,
// macht nur ein frisches Session-Token für einen neuen Durchlauf.
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

unset($_SESSION['survey_token']);
unset($_SESSION['survey_source']);

echo json_encode(['success' => true]);
