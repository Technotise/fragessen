<?php
// lib/security.php – Prompt-Injection-Schutz + PDO-Helper

declare(strict_types=1);

/**
 * Prüft eine Query auf Prompt-Injection-Versuche.
 * Gibt eine Fehlermeldung zurück oder null wenn alles ok ist.
 */
function checkPromptInjection(string $query): ?string
{
    $verboten = [
        // Rollenmanipulation (DE)
        'vergiss', 'verhalte dich', 'du bist jetzt', 'tu so als', 'rollenspiel',
        'überschreibe', 'ersetze deine rolle', 'ignoriere',
        // Rollenmanipulation (EN)
        'forget', 'you are now', 'roleplay', 'pretend', 'act as',
        'ignore previous', 'disregard', 'override', 'jailbreak', 'bypass',
        'break character', 'simulate',
        // System-Prompt-Exfiltration
        'zeig deinen prompt', 'zeige deinen system', 'reveal your prompt',
        'show your instructions', 'print your system',
        // Technisch
        'base64', '<?php', '<script', 'javascript:', 'eval(',
    ];

    $q_lower = mb_strtolower($query);
    foreach ($verboten as $wort) {
        if (str_contains($q_lower, mb_strtolower($wort))) {
            return 'Diese Eingabe kann nicht verarbeitet werden. Bitte formuliere deine Frage zu den Ratsprotokollen anders.';
        }
    }

    // "DAN" (Jailbreak-Persona) nur als eigenes Wort in Großschreibung matchen —
    // ein Substring-Check würde "dann", "Danke" etc. fälschlich blockieren.
    if (preg_match('/\bDAN\b/', $query)) {
        return 'Diese Eingabe kann nicht verarbeitet werden. Bitte formuliere deine Frage zu den Ratsprotokollen anders.';
    }

    return null;
}

/**
 * Gibt eine PDO-Verbindung zurück.
 */
function getPdo(array $config): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
            $config['db_user'],
            $config['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
    return $pdo;
}
