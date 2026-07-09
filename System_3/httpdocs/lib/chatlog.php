<?php
// lib/chatlog.php – Chat-Log + Feedback speichern

declare(strict_types=1);

/**
 * Speichert eine Chat-Runde in chat_logs.
 * Gibt die neue ID zurück.
 */
function saveChatLog(PDO $pdo, array $data): int
{
    $stmt = $pdo->prepare('
        INSERT INTO chat_logs
            (session_id, ip_hash, query, condensed_query, answer, clarify,
             sources_json, top_k, gremium_key, year_from, year_to,
             answer_length, quality, search_mode, elapsed_ms)
        VALUES
            (:session_id, :ip_hash, :query, :condensed_query, :answer, :clarify,
             :sources_json, :top_k, :gremium_key, :year_from, :year_to,
             :answer_length, :quality, :search_mode, :elapsed_ms)
    ');
    $stmt->execute($data);
    return (int)$pdo->lastInsertId();
}

/**
 * Speichert Nutzer-Feedback zu einer Antwort.
 */
function saveFeedback(PDO $pdo, int $log_id, string $session_id, int $rating, ?string $comment): void
{
    $stmt = $pdo->prepare('
        INSERT INTO chat_feedback (chat_log_id, session_id, rating, comment)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment)
    ');
    $stmt->execute([$log_id, $session_id, $rating, $comment]);
}
