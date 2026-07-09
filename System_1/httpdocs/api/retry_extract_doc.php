<?php
declare(strict_types=1);

require __DIR__ . '/../src/db.php';
require __DIR__ . '/../src/auth.php';

require_login();

$pdo = db();

$docId = (int)($_POST['document_id'] ?? 0);
$gremiumId = (int)($_POST['gremium_id'] ?? 0);

if ($docId <= 0) {
  http_response_code(400);
  echo "document_id fehlt";
  exit;
}

/*
  Neustart bedeutet:
  documents.extraction_retry_allowed = 1

  Optional sinnvoll:
  die naechste offene Task wieder auf geplant setzen,
  falls sie gerade als fehlgeschlagen markiert ist.
*/

$pdo->beginTransaction();
try {
  $st = $pdo->prepare("
    UPDATE documents
    SET extraction_retry_allowed = 1
    WHERE id = ?
      AND deleted_at IS NULL
  ");
  $st->execute([$docId]);

  $st2 = $pdo->prepare("
    UPDATE document_state
    SET status = 'geplant'
    WHERE document_id = ?
      AND status = 'fehlgeschlagen'
      AND task IN ('slice','get_json','extract_core','extract_attendance','extract_agenda')
  ");
  $st2->execute([$docId]);

  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo "DB Fehler: " . $e->getMessage();
  exit;
}

$qs = [];
if ($gremiumId > 0) $qs[] = 'gremium_id=' . urlencode((string)$gremiumId);
$to = '../queue.php' . (count($qs) ? ('?' . implode('&', $qs)) : '');
header('Location: ' . $to);
exit;
