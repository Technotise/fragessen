<?php
// /umfrage/admin.php
// Auswertung – nutzt das bestehende FragEssen-Admin-Login.
// Wer in /admin.php (FragEssen) eingeloggt ist, kommt hier ohne separaten Login rein.
// Voraussetzung: gleicher PHP-Session-Namespace (gleicher Webroot, gleiche Cookie-Domain)

declare(strict_types=1);

session_start();
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/questions.php';

// ─── Auth: Session-Variable aus FragEssen-Admin prüfen ───
if (empty($_SESSION['admin_user_id'])) {
    ?>
    <!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">
    <title>Umfrage – Login erforderlich</title>
    <link rel="stylesheet" href="style.css">
    </head><body>
    <div class="wrap" style="max-width:480px;">
      <div class="card">
        <h2>🔒 Login erforderlich</h2>
        <p>Diese Auswertung nutzt das gleiche Login wie das FragEssen-Admin-Panel.</p>
        <p>Bitte melde dich zuerst dort an:</p>
        <div class="actions">
          <a href="/admin.php" class="btn-primary">Zum FragEssen-Admin →</a>
        </div>
        <p style="margin-top:1rem; color: var(--text-muted); font-size:.85rem;">
          Nach dem Login einfach diese URL erneut aufrufen.
        </p>
      </div>
    </div></body></html>
    <?php
    exit;
}

$adminUsername = $_SESSION['admin_username'] ?? 'admin';

$pdo = surveyPdo();

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    exportCsv($pdo);
    exit;
}

$stats = loadStats($pdo);
$responses = loadResponses($pdo);
$susResults = computeSusScores($pdo);
$umuxResults = computeUmuxLite($pdo);
$npsScore = computeNps($pdo);
$freeText = loadFreeText($pdo);

// ─────────────────────────────────────────────

function loadStats(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT
          COUNT(*)                                            AS total_started,
          SUM(stage1_completed_at IS NOT NULL)                AS stage1_done,
          SUM(stage2_completed_at IS NOT NULL)                AS stage2_done,
          SUM(role = 'bv')                                    AS n_bv,
          SUM(role = 'rat')                                   AS n_rat,
          SUM(role = 'verwaltung')                            AS n_verwaltung,
          SUM(role = 'buergerschaft')                         AS n_buergerschaft,
          SUM(gender = 'weiblich')                            AS n_w,
          SUM(gender = 'maennlich')                           AS n_m,
          SUM(gender = 'divers')                              AS n_d,
          SUM(gender = 'keine_angabe')                        AS n_ka,
          SUM(source = 'banner')                              AS n_banner,
          SUM(source = 'mail')                                AS n_mail,
          SUM(source = 'direct')                              AS n_direct
        FROM survey_participants
        WHERE stage1_completed_at IS NOT NULL
    ");
    return $stmt->fetch() ?: [];
}

function loadResponses(PDO $pdo): array
{
    $questions = array_merge(stage1Questions(), stage2Questions());
    $out = [];

    foreach ($questions as $q) {
        if (!in_array($q['type'], ['likert5', 'likert7', 'nps'], true)) continue;

        $stmt = $pdo->prepare("
            SELECT answer_numeric, COUNT(*) AS n
            FROM survey_responses
            WHERE question_key = ? AND answer_numeric IS NOT NULL
            GROUP BY answer_numeric
            ORDER BY answer_numeric
        ");
        $stmt->execute([$q['key']]);
        $rows = $stmt->fetchAll();

        $distribution = [];
        $sum = 0;
        $count = 0;
        foreach ($rows as $r) {
            $distribution[(int)$r['answer_numeric']] = (int)$r['n'];
            $sum += (int)$r['answer_numeric'] * (int)$r['n'];
            $count += (int)$r['n'];
        }

        $out[$q['key']] = [
            'label'        => $q['label'],
            'type'         => $q['type'],
            'distribution' => $distribution,
            'mean'         => $count > 0 ? round($sum / $count, 2) : null,
            'n'            => $count,
        ];
    }

    return $out;
}

function computeSusScores(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT session_token, question_key, answer_numeric
        FROM survey_responses
        WHERE question_key LIKE 'sus_%' AND answer_numeric IS NOT NULL
    ");
    $rows = $stmt->fetchAll();

    $byToken = [];
    foreach ($rows as $r) {
        $byToken[$r['session_token']][$r['question_key']] = (int)$r['answer_numeric'];
    }

    $scores = [];
    $positiveItems = ['sus_1','sus_3','sus_5','sus_7','sus_9'];
    $negativeItems = ['sus_2','sus_4','sus_6','sus_8','sus_10'];

    foreach ($byToken as $token => $answers) {
        if (count($answers) !== 10) continue;

        $sum = 0;
        foreach ($positiveItems as $k) { $sum += ($answers[$k] - 1); }
        foreach ($negativeItems as $k) { $sum += (5 - $answers[$k]); }

        $scores[] = $sum * 2.5;
    }

    if (!$scores) return ['n' => 0, 'mean' => null, 'scores' => []];

    $mean = array_sum($scores) / count($scores);
    $variance = 0;
    foreach ($scores as $s) { $variance += ($s - $mean) ** 2; }
    $sd = count($scores) > 1 ? sqrt($variance / (count($scores) - 1)) : 0;

    return [
        'n'      => count($scores),
        'mean'   => round($mean, 1),
        'sd'     => round($sd, 1),
        'min'    => min($scores),
        'max'    => max($scores),
        'scores' => $scores,
    ];
}

function computeUmuxLite(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT session_token, question_key, answer_numeric
        FROM survey_responses
        WHERE question_key IN ('umux_lite_capability','umux_lite_easeofuse')
          AND answer_numeric IS NOT NULL
    ");
    $rows = $stmt->fetchAll();

    $byToken = [];
    foreach ($rows as $r) {
        $byToken[$r['session_token']][$r['question_key']] = (int)$r['answer_numeric'];
    }

    $scores = [];
    foreach ($byToken as $answers) {
        if (!isset($answers['umux_lite_capability'], $answers['umux_lite_easeofuse'])) continue;
        $avg = ($answers['umux_lite_capability'] + $answers['umux_lite_easeofuse']) / 2;
        $scores[] = ($avg - 1) / 6 * 100;
    }

    if (!$scores) return ['n' => 0, 'mean' => null];

    $mean = array_sum($scores) / count($scores);
    $variance = 0;
    foreach ($scores as $s) { $variance += ($s - $mean) ** 2; }
    $sd = count($scores) > 1 ? sqrt($variance / (count($scores) - 1)) : 0;

    return ['n' => count($scores), 'mean' => round($mean, 1), 'sd' => round($sd, 1)];
}

function computeNps(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT answer_numeric, COUNT(*) AS n
        FROM survey_responses
        WHERE question_key = 'nps' AND answer_numeric IS NOT NULL
        GROUP BY answer_numeric
    ");
    $rows = $stmt->fetchAll();

    $promoters = $passives = $detractors = 0;
    foreach ($rows as $r) {
        $val = (int)$r['answer_numeric'];
        $n   = (int)$r['n'];
        if ($val >= 9)      $promoters  += $n;
        elseif ($val >= 7)  $passives   += $n;
        else                $detractors += $n;
    }
    $total = $promoters + $passives + $detractors;
    if (!$total) return ['n' => 0, 'score' => null];

    $score = (($promoters - $detractors) / $total) * 100;

    return [
        'n'          => $total,
        'score'      => round($score, 1),
        'promoters'  => $promoters,
        'passives'   => $passives,
        'detractors' => $detractors,
    ];
}

function loadFreeText(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT question_key, answer_text, created_at
        FROM survey_responses
        WHERE answer_text IS NOT NULL AND answer_text != ''
        ORDER BY created_at DESC
    ");
    return $stmt->fetchAll();
}

function exportCsv(PDO $pdo): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fragessen_umfrage_' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    $stmt = $pdo->query("
        SELECT DISTINCT question_key FROM survey_responses ORDER BY question_key
    ");
    $keys = array_column($stmt->fetchAll(), 'question_key');

    $header = array_merge(
        ['token','role','gender','ris_familiarity','source','stage1_at','stage2_at'],
        $keys
    );
    fputcsv($out, $header, ';');

    $stmt = $pdo->query("
        SELECT * FROM survey_participants
        WHERE stage1_completed_at IS NOT NULL
        ORDER BY stage1_completed_at
    ");
    $participants = $stmt->fetchAll();

    $respStmt = $pdo->prepare("
        SELECT question_key, answer_numeric, answer_text
        FROM survey_responses
        WHERE session_token = ?
    ");

    foreach ($participants as $p) {
        $respStmt->execute([$p['session_token']]);
        $responses = [];
        foreach ($respStmt->fetchAll() as $r) {
            $responses[$r['question_key']] = $r['answer_numeric'] ?? $r['answer_text'];
        }

        $row = [
            substr($p['session_token'], 0, 8) . '...',
            $p['role'],
            $p['gender'],
            $p['ris_familiarity'],
            $p['source'] ?? 'direct',
            $p['stage1_completed_at'],
            $p['stage2_completed_at'],
        ];
        foreach ($keys as $k) {
            $row[] = $responses[$k] ?? '';
        }
        fputcsv($out, $row, ';');
    }
    fclose($out);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>FragEssen Umfrage – Auswertung</title>
<link rel="stylesheet" href="style.css">
<style>
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: .8rem;
    margin-bottom: 1.5rem;
  }
  .stat-box {
    background: var(--surface);
    padding: 1rem;
    border-radius: var(--radius);
    box-shadow: var(--shadow);
  }
  .stat-value {
    font-size: 1.8rem;
    font-weight: 600;
    color: var(--accent);
  }
  .stat-label {
    font-size: .85rem;
    color: var(--text-muted);
  }
  table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
    margin-top: .5rem;
  }
  table th, table td {
    text-align: left;
    padding: .5rem .6rem;
    border-bottom: 1px solid var(--border);
  }
  table th { background: rgba(0,0,0,.03); }
  .bar {
    display: inline-block;
    height: 8px;
    background: var(--accent);
    border-radius: 4px;
    margin-right: .5rem;
    vertical-align: middle;
  }
  .quote {
    background: var(--bg);
    border-left: 3px solid var(--accent);
    padding: .7rem .9rem;
    margin-bottom: .5rem;
    border-radius: 4px;
    font-size: .92rem;
  }
  .quote .meta {
    font-size: .8rem;
    color: var(--text-muted);
    margin-top: .3rem;
  }
  .admin-userline {
    color: var(--text-muted);
    font-size: .85rem;
  }
  .admin-userline a {
    color: var(--accent);
    text-decoration: none;
  }
  .admin-userline a:hover { text-decoration: underline; }
</style>
</head>
<body>
<div class="wrap" style="max-width: 1000px;">

<header class="header">
  <h1>Umfrage-Auswertung</h1>
  <div class="subtitle admin-userline">
    Eingeloggt als <strong><?= htmlspecialchars($adminUsername) ?></strong>
    · <a href="?export=csv">CSV-Export</a>
    · <a href="/admin.php">Zurück zum Hauptadmin</a>
  </div>
</header>

<div class="stats-grid">
  <div class="stat-box">
    <div class="stat-value"><?= (int)($stats['stage1_done'] ?? 0) ?></div>
    <div class="stat-label">Abgeschlossen (Stage 1)</div>
  </div>
  <div class="stat-box">
    <div class="stat-value"><?= (int)($stats['stage2_done'] ?? 0) ?></div>
    <div class="stat-label">Vertiefung (Stage 2)</div>
  </div>
  <div class="stat-box">
    <div class="stat-value"><?= $umuxResults['mean'] !== null ? number_format($umuxResults['mean'], 1, ',', '') : '—' ?></div>
    <div class="stat-label">UMUX-Lite (n=<?= $umuxResults['n'] ?>)</div>
  </div>
  <div class="stat-box">
    <div class="stat-value"><?= $susResults['mean'] !== null ? number_format($susResults['mean'], 1, ',', '') : '—' ?></div>
    <div class="stat-label">SUS-Score (n=<?= $susResults['n'] ?>)</div>
  </div>
  <div class="stat-box">
    <div class="stat-value"><?= $npsScore['score'] !== null ? number_format($npsScore['score'], 0) : '—' ?></div>
    <div class="stat-label">NPS (n=<?= $npsScore['n'] ?>)</div>
  </div>
</div>

<div class="card">
  <h3 style="margin-top:0;">Stichprobe</h3>
  <table>
    <tr><th>Rolle</th><th>n</th></tr>
    <tr><td>Bezirksvertretung</td><td><?= (int)($stats['n_bv'] ?? 0) ?></td></tr>
    <tr><td>Rat der Stadt Essen</td><td><?= (int)($stats['n_rat'] ?? 0) ?></td></tr>
    <tr><td>Verwaltung</td><td><?= (int)($stats['n_verwaltung'] ?? 0) ?></td></tr>
    <tr><td>Öffentlichkeit</td><td><?= (int)($stats['n_buergerschaft'] ?? 0) ?></td></tr>
  </table>
  <table style="margin-top:1rem;">
    <tr><th>Geschlecht</th><th>n</th></tr>
    <tr><td>weiblich</td><td><?= (int)($stats['n_w'] ?? 0) ?></td></tr>
    <tr><td>männlich</td><td><?= (int)($stats['n_m'] ?? 0) ?></td></tr>
    <tr><td>divers</td><td><?= (int)($stats['n_d'] ?? 0) ?></td></tr>
    <tr><td>keine Angabe</td><td><?= (int)($stats['n_ka'] ?? 0) ?></td></tr>
  </table>
  <table style="margin-top:1rem;">
    <tr><th>Quelle</th><th>n</th></tr>
    <tr><td>Banner auf FragEssen</td><td><?= (int)($stats['n_banner'] ?? 0) ?></td></tr>
    <tr><td>E-Mail-Einladung</td><td><?= (int)($stats['n_mail'] ?? 0) ?></td></tr>
    <tr><td>Direkt (unbekannt)</td><td><?= (int)($stats['n_direct'] ?? 0) ?></td></tr>
  </table>
</div>

<div class="card">
  <h3 style="margin-top:0;">Antwortverteilungen</h3>
  <?php foreach ($responses as $key => $r): ?>
    <div style="margin-bottom: 1.5rem;">
      <strong><?= htmlspecialchars($r['label']) ?></strong>
      <div style="color: var(--text-muted); font-size:.85rem; margin: .2rem 0 .5rem;">
        n=<?= $r['n'] ?> · Mittelwert: <?= $r['mean'] !== null ? number_format($r['mean'], 2, ',', '') : '–' ?>
      </div>
      <table>
        <?php
        $max = $r['distribution'] ? max($r['distribution']) : 1;
        $range = match($r['type']) {
            'likert5' => range(1, 5),
            'likert7' => range(1, 7),
            'nps'     => range(0, 10),
            default   => [],
        };
        foreach ($range as $v):
            $n = $r['distribution'][$v] ?? 0;
            $pct = $max > 0 ? ($n / $max * 100) : 0;
        ?>
          <tr>
            <td style="width:40px;"><?= $v ?></td>
            <td><span class="bar" style="width: <?= $pct ?>%;"></span> <?= $n ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($freeText): ?>
<div class="card">
  <h3 style="margin-top:0;">Freitextantworten</h3>
  <?php foreach ($freeText as $ft): ?>
    <div class="quote">
      <?= nl2br(htmlspecialchars($ft['answer_text'])) ?>
      <div class="meta"><?= htmlspecialchars($ft['question_key']) ?> · <?= htmlspecialchars($ft['created_at']) ?></div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

</div>
</body>
</html>
