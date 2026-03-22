<?php
/**
 * AI Process Result — post-processing after Q delivers results
 * ==============================================================
 * Called by ai-complete.php after saving result_data.
 * Routes by job_type to run step3 logic (insert threats, statements, etc.)
 *
 * Can also be called standalone: GET ?job_id=X&key=xxx
 */
header('Content-Type: application/json');

$config = require dirname(__DIR__) . '/config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
require_once dirname(__DIR__) . '/includes/site-settings.php';

// Auth
$pollerKey = $config['poller_key'] ?? '';
$sentKey = $_GET['key'] ?? '';
if (!$pollerKey || $sentKey !== $pollerKey) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$jobId = intval($_GET['job_id'] ?? 0);
if (!$jobId) {
    echo json_encode(['error' => 'No job_id']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM ai_queue WHERE id = ? AND status = 'done'");
$stmt->execute([$jobId]);
$job = $stmt->fetch();
if (!$job) {
    echo json_encode(['error' => 'Job not found or not done']);
    exit;
}

$jobType = $job['job_type'];
$resultData = json_decode($job['result_data'], true);

if ($jobType === 'threat_collect') {
    echo json_encode(processThreatResult($pdo, $resultData, $jobId));
} elseif ($jobType === 'statement_collect') {
    echo json_encode(processStatementResult($pdo, $resultData, $job));
} else {
    echo json_encode(['status' => 'no_processing_needed', 'job_type' => $jobType]);
}

// === Threat insert (step 3 logic) ===
function processThreatResult($pdo, $parsed, $jobId) {
    $startTime = microtime(true);

    if (!$parsed || !isset($parsed['threats'])) {
        return ['error' => 'No threats data in result'];
    }

    $threats = $parsed['threats'];
    if (empty($threats)) {
        setSiteSetting($pdo, 'threat_collect_last_success', date('Y-m-d H:i:s'));
        setSiteSetting($pdo, 'threat_collect_last_result', json_encode([
            'status' => 'success', 'inserted' => 0, 'note' => 'No new threats (Q pipeline)', 'pipeline' => 'Q'
        ]));
        return ['status' => 'success', 'inserted' => 0];
    }

    // Dedup
    $existingTitles = $pdo->query("SELECT threat_id, title, source_url FROM executive_threats")->fetchAll();
    $existingUrls = [];
    foreach ($existingTitles as $et) {
        if ($et['source_url']) $existingUrls[] = strtolower(trim($et['source_url']));
    }

    $dedupedThreats = [];
    $skipped = 0;
    foreach ($threats as $threat) {
        $newUrl = strtolower(trim($threat['source_url'] ?? ''));
        if ($newUrl && in_array($newUrl, $existingUrls)) { $skipped++; continue; }

        $isDup = false;
        $newTitle = $threat['title'] ?? '';
        foreach ($existingTitles as $et) {
            if (titleSimilarity($newTitle, $et['title']) > 0.6) { $isDup = true; $skipped++; break; }
        }
        if (!$isDup) $dedupedThreats[] = $threat;
    }

    if (empty($dedupedThreats)) {
        setSiteSetting($pdo, 'threat_collect_last_success', date('Y-m-d H:i:s'));
        setSiteSetting($pdo, 'threat_collect_last_result', json_encode([
            'status' => 'success', 'inserted' => 0, 'skipped' => $skipped, 'pipeline' => 'Q'
        ]));
        return ['status' => 'success', 'inserted' => 0, 'skipped' => $skipped];
    }

    // Tag lookup
    $tags = $pdo->query("SELECT tag_id, tag_name FROM threat_tags ORDER BY tag_id")->fetchAll();
    $tagLookup = [];
    foreach ($tags as $tag) $tagLookup[$tag['tag_name']] = $tag['tag_id'];

    $insertStmt = $pdo->prepare("INSERT INTO executive_threats (threat_date, title, description, threat_type, target, source_url, action_script, official_id, is_active, severity_score, benefit_score, branch) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)");
    $tagStmt = $pdo->prepare("INSERT IGNORE INTO threat_tag_map (threat_id, tag_id) VALUES (?, ?)");

    $today = date('Y-m-d');
    $inserted = 0;
    $tagged = 0;

    foreach ($dedupedThreats as $threat) {
        $title = trim($threat['title'] ?? '');
        $desc = trim($threat['description'] ?? '');
        if (empty($title) || empty($desc)) continue;
        if (strlen($title) > 200) $title = substr($title, 0, 197) . '...';

        $type = in_array($threat['threat_type'] ?? '', ['tactical', 'strategic']) ? $threat['threat_type'] : 'tactical';
        $branch = in_array($threat['branch'] ?? '', ['executive', 'congressional', 'judicial']) ? $threat['branch'] : 'executive';

        try {
            $insertStmt->execute([
                $today, $title, $desc, $type,
                trim($threat['target'] ?? ''),
                trim($threat['source_url'] ?? ''),
                trim($threat['action_script'] ?? ''),
                intval($threat['official_id'] ?? 326),
                intval($threat['severity_score'] ?? 200),
                intval($threat['benefit_score'] ?? 0),
                $branch
            ]);
            $threatId = $pdo->lastInsertId();
            $inserted++;

            foreach ($threat['tags'] ?? [] as $tagName) {
                if (isset($tagLookup[$tagName])) { $tagStmt->execute([$threatId, $tagLookup[$tagName]]); $tagged++; }
            }
        } catch (PDOException $e) { /* skip */ }
    }

    // Generate polls for 300+ threats
    $newPolls = $pdo->query("SELECT et.threat_id, et.title, et.severity_score FROM executive_threats et LEFT JOIN polls p ON p.threat_id = et.threat_id AND p.poll_type = 'threat' WHERE et.severity_score >= 300 AND et.is_active = 1 AND p.poll_id IS NULL")->fetchAll();
    $pollStmt = $pdo->prepare("INSERT INTO polls (slug, question, poll_type, threat_id, active, created_by) VALUES (?, ?, 'threat', ?, 1, NULL)");
    $pollCount = 0;
    foreach ($newPolls as $t) {
        $pollStmt->execute(['threat-' . $t['threat_id'], $t['title'], $t['threat_id']]);
        $pollCount++;
    }

    $elapsed = round(microtime(true) - $startTime, 1);
    $totalThreats = $pdo->query("SELECT COUNT(*) FROM executive_threats")->fetchColumn();

    setSiteSetting($pdo, 'threat_collect_last_success', date('Y-m-d H:i:s'));
    setSiteSetting($pdo, 'threat_collect_last_result', json_encode([
        'status' => 'success', 'timestamp' => date('Y-m-d H:i:s'),
        'inserted' => $inserted, 'skipped' => $skipped, 'polls_created' => $pollCount,
        'tags_applied' => $tagged, 'total_threats' => (int)$totalThreats,
        'elapsed' => $elapsed, 'pipeline' => 'Q', 'job_id' => $jobId
    ]));

    return ['status' => 'success', 'inserted' => $inserted, 'skipped' => $skipped, 'polls' => $pollCount, 'tags' => $tagged];
}

function processStatementResult($pdo, $parsed, $job) {
    // TODO: wire statement insert logic
    return ['status' => 'not_implemented_yet'];
}

function titleSimilarity($a, $b) {
    $stop = ['the', 'a', 'an', 'of', 'to', 'in', 'for', 'and', 'on', 'is', 'at', 'by', 'as', 'after', 'from', 'with'];
    $norm = function($t) use ($stop) {
        $t = strtolower(preg_replace('/[^a-z0-9\s]/', '', $t));
        return array_diff(explode(' ', trim(preg_replace('/\s+/', ' ', $t))), $stop);
    };
    $wa = $norm($a); $wb = $norm($b);
    if (empty($wa) || empty($wb)) return 0;
    return count(array_intersect($wa, $wb)) / max(count($wa), count($wb));
}
