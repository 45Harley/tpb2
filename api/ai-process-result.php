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
} elseif ($jobType === 'truthfulness') {
    echo json_encode(processTruthfulnessResult($pdo, $resultData, $job));
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
    $startTime = microtime(true);
    $requestData = json_decode($job['request_data'], true);
    $officialId = intval($requestData['official_id'] ?? 326);

    if (!$parsed || !isset($parsed['statements'])) return ['error' => 'No statements data'];

    $statements = $parsed['statements'];
    if (empty($statements)) {
        $key = "statement_collect_last_success_{$officialId}";
        setSiteSetting($pdo, $key, date('Y-m-d H:i:s'));
        setSiteSetting($pdo, "statement_collect_last_result_{$officialId}", json_encode([
            'status' => 'success', 'inserted' => 0, 'pipeline' => 'Q'
        ]));
        return ['status' => 'success', 'inserted' => 0];
    }

    // Dedup
    $existing = $pdo->query("SELECT id, official_id, statement_date, LEFT(content, 200) AS cs, source_url FROM rep_statements")->fetchAll();
    $existingUrls = [];
    $existingKeys = [];
    foreach ($existing as $es) {
        if ($es['source_url']) $existingUrls[] = strtolower(trim($es['source_url']));
        $existingKeys[] = $es['official_id'] . '|' . $es['statement_date'] . '|' . strtolower(trim($es['cs']));
    }

    $validTenses = ['future', 'present', 'past'];
    $insertStmt = $pdo->prepare("INSERT INTO rep_statements (official_id, source, source_url, content, summary, policy_topic, tense, severity_score, benefit_score, statement_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $inserted = 0;
    $skipped = 0;
    $today = date('Y-m-d');

    foreach ($statements as $s) {
        $content = trim($s['content'] ?? '');
        $oid = intval($s['official_id'] ?? $officialId);
        $date = trim($s['statement_date'] ?? $today);
        $url = strtolower(trim($s['source_url'] ?? ''));

        if (empty($content)) continue;
        if ($url && in_array($url, $existingUrls)) { $skipped++; continue; }
        $key = $oid . '|' . $date . '|' . strtolower(substr($content, 0, 200));
        if (in_array($key, $existingKeys)) { $skipped++; continue; }

        $tense = in_array($s['tense'] ?? '', $validTenses) ? $s['tense'] : null;
        try {
            $insertStmt->execute([$oid, trim($s['source'] ?? 'Unknown'), trim($s['source_url'] ?? '') ?: null,
                $content, trim($s['summary'] ?? '') ?: null, trim($s['policy_topic'] ?? '') ?: null,
                $tense, intval($s['severity_score'] ?? 0), intval($s['benefit_score'] ?? 0), $date]);
            $inserted++;
        } catch (PDOException $e) { /* skip */ }
    }

    $elapsed = round(microtime(true) - $startTime, 1);
    setSiteSetting($pdo, "statement_collect_last_success_{$officialId}", date('Y-m-d H:i:s'));
    setSiteSetting($pdo, "statement_collect_last_result_{$officialId}", json_encode([
        'status' => 'success', 'inserted' => $inserted, 'skipped' => $skipped,
        'elapsed' => $elapsed, 'pipeline' => 'Q', 'job_id' => $job['id']
    ]));

    return ['status' => 'success', 'inserted' => $inserted, 'skipped' => $skipped, 'official_id' => $officialId];
}

function processTruthfulnessResult($pdo, $parsed, $job) {
    $startTime = microtime(true);

    $assignments = $parsed['cluster_assignments'] ?? [];
    $scores = $parsed['truthfulness_scores'] ?? [];

    if (empty($assignments) && empty($scores)) {
        return ['status' => 'success', 'clustered' => 0, 'scored' => 0, 'note' => 'Nothing to process'];
    }

    // Phase 1: cluster assignments
    $newClusterMap = [];
    $insertCluster = $pdo->prepare("INSERT INTO statement_clusters (canonical_claim, policy_topic, first_seen, last_seen, repeat_count) VALUES (?, ?, ?, ?, 1)");
    $updateStmtCluster = $pdo->prepare("UPDATE rep_statements SET cluster_id = ? WHERE id = ?");

    $clustered = 0;
    $newClusters = 0;

    foreach ($assignments as $a) {
        $stmtId = (int)($a['statement_id'] ?? 0);
        if (!$stmtId) continue;

        $stmtRow = $pdo->prepare("SELECT statement_date, policy_topic FROM rep_statements WHERE id = ?");
        $stmtRow->execute([$stmtId]);
        $stmtData = $stmtRow->fetch();
        if (!$stmtData) continue;

        $isNew = !empty($a['cluster_id_is_new']);
        if ($isNew) {
            $nc = $a['new_cluster'] ?? [];
            $claim = trim($nc['canonical_claim'] ?? $a['canonical_claim'] ?? '');
            if (!$claim) continue;
            $claimKey = strtolower($claim);

            if (isset($newClusterMap[$claimKey])) {
                $clusterId = $newClusterMap[$claimKey];
                $pdo->prepare("UPDATE statement_clusters SET repeat_count = repeat_count + 1, last_seen = GREATEST(last_seen, ?) WHERE id = ?")->execute([$stmtData['statement_date'], $clusterId]);
            } else {
                $topic = $nc['policy_topic'] ?? $stmtData['policy_topic'] ?? '';
                $insertCluster->execute([$claim, $topic, $stmtData['statement_date'], $stmtData['statement_date']]);
                $clusterId = (int)$pdo->lastInsertId();
                $newClusterMap[$claimKey] = $clusterId;
                $newClusters++;
            }
        } else {
            $clusterId = (int)($a['cluster_id'] ?? 0);
            if (!$clusterId) continue;
            $pdo->prepare("UPDATE statement_clusters SET repeat_count = repeat_count + 1, first_seen = LEAST(first_seen, ?), last_seen = GREATEST(last_seen, ?) WHERE id = ?")->execute([$stmtData['statement_date'], $stmtData['statement_date'], $clusterId]);
        }
        $updateStmtCluster->execute([$clusterId, $stmtId]);
        $clustered++;
    }

    // Phase 2: truthfulness scores
    $insertHistory = $pdo->prepare("INSERT INTO truthfulness_history (cluster_id, score, delta, note, evidence_refs) VALUES (?, ?, ?, ?, ?)");
    $updateCluster = $pdo->prepare("UPDATE statement_clusters SET truthfulness_score = ?, truthfulness_avg = ?, truthfulness_direction = ?, truthfulness_delta = ?, truthfulness_note = ? WHERE id = ?");

    $scored = 0;
    foreach ($scores as $s) {
        $score = (int)($s['score'] ?? 0);
        $note = trim($s['note'] ?? '');
        $evidenceRefs = trim($s['evidence_refs'] ?? '');
        $isNew = !empty($s['cluster_id_is_new']);

        if ($isNew) {
            $claim = trim($s['canonical_claim'] ?? '');
            $clusterId = $newClusterMap[strtolower($claim)] ?? null;
            if (!$clusterId) continue;
        } else {
            $clusterId = (int)($s['cluster_id'] ?? 0);
            if (!$clusterId) continue;
        }

        $prevRow = $pdo->prepare("SELECT truthfulness_score FROM statement_clusters WHERE id = ?");
        $prevRow->execute([$clusterId]);
        $prevScore = $prevRow->fetchColumn();
        $prevScore = ($prevScore !== false && $prevScore !== null) ? (int)$prevScore : null;

        $delta = ($prevScore !== null) ? ($score - $prevScore) : 0;
        $direction = 'new';
        if ($prevScore !== null) {
            if ($delta > 10) $direction = 'up';
            elseif ($delta < -10) $direction = 'down';
            else $direction = 'stable';
        }

        $insertHistory->execute([$clusterId, $score, $delta, $note, $evidenceRefs]);
        $avgRow = $pdo->prepare("SELECT AVG(score) FROM truthfulness_history WHERE cluster_id = ?");
        $avgRow->execute([$clusterId]);
        $avg = round((float)$avgRow->fetchColumn(), 1);
        $updateCluster->execute([$score, $avg, $direction, $delta, $note, $clusterId]);
        $scored++;
    }

    $elapsed = round(microtime(true) - $startTime, 1);
    setSiteSetting($pdo, 'truthfulness_score_last_success', date('Y-m-d H:i:s'));
    setSiteSetting($pdo, 'truthfulness_score_last_result', json_encode([
        'status' => 'success', 'clustered' => $clustered, 'new_clusters' => $newClusters,
        'scored' => $scored, 'elapsed' => $elapsed, 'pipeline' => 'Q', 'job_id' => $job['id']
    ]));

    return ['status' => 'success', 'clustered' => $clustered, 'new_clusters' => $newClusters, 'scored' => $scored];
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
