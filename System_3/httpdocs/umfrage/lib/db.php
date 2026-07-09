<?php
// /umfrage/lib/db.php
// Nutzt die bestehende config.php aus dem FragEssen-Hauptprojekt.
// Erwartet: config.php liegt eine Ebene über dem umfrage/-Ordner.

declare(strict_types=1);

function surveyPdo(): PDO
{
    $config = require __DIR__ . '/../../config.php';

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $config['db_host'] ?? 'localhost',
        $config['db_name']
    );

    return new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

function newSessionToken(): string
{
    return bin2hex(random_bytes(16));
}

function clientIpHash(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    return $ip ? hash('sha256', $ip) : null;
}

function userAgentHash(): ?string
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
    return $ua ? hash('sha256', $ua) : null;
}
