<?php
// FragEssen System 3 – Konfiguration
// Kopieren nach config.php und Werte eintragen.
declare(strict_types=1);

return [
    // MySQL — geteilte Datenbank mit System 1, Schema: ../db/schema.mysql.sql
    'db_host' => 'localhost',
    'db_name' => 'CHANGEME',
    'db_user' => 'CHANGEME',
    'db_pass' => 'CHANGEME',

    // System 2 API (FastAPI-Backend)
    'api_base' => 'https://CHANGEME:8000',
    'api_key'  => 'CHANGEME',        // gleicher Wert wie KOMMRAG_API_KEY in System_2/web_api/.env

    // Rate-Limiting (anonyme Nutzer)
    'rate_limit_window'   => 3600,   // Sekunden
    'rate_limit_requests' => 15,     // Anfragen pro Fenster

    // Sessions
    'session_lifetime' => 86400,     // Sekunden (24 h)

    // Feedback (👍/👎) aktivieren
    'feedback_enabled' => true,
];
