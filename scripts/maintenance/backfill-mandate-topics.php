<?php
/**
 * Backfill Mandate Topics
 * =======================
 * One-shot script to classify existing mandates that have NULL policy_topic.
 * Safe to re-run — only processes untagged rows.
 *
 * Usage: php scripts/maintenance/backfill-mandate-topics.php
 * Run on server: php /home/sandge5/tpb2.sandgems.net/scripts/maintenance/backfill-mandate-topics.php
 */

$config = require __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../config-claude.php';
require_once __DIR__ . '/../../config/mandate-topics.php';

// Reuse the talk API's Claude helper
require_once __DIR__ . '/../../talk/api.php';

$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$stmt = $pdo->prepare("
    SELECT id, content FROM idea_log
    WHERE category IN ('mandate-federal','mandate-state','mandate-town')
      AND policy_topic IS NULL AND deleted_at IS NULL
    ORDER BY id
");
$stmt->execute();
$rows = $stmt->fetchAll();

echo count($rows) . " mandates need tagging.\n";

$topicList = implode(', ', MANDATE_POLICY_TOPICS);
$systemPrompt = "You classify citizen mandates. Respond with ONLY a JSON object on one line, nothing else:\n"
    . "{\"citizen_summary\": \"<plain language topic, 5-10 words, citizen's voice>\", "
    . "\"policy_topic\": \"<exactly one of: {$topicList}>\"}";

$updated = 0;
$failed  = 0;

foreach ($rows as $row) {
    echo "  #{$row['id']}: ";
    try {
        $msgs = [['role' => 'user', 'content' => $row['content']]];
        $resp = talkCallClaudeAPI($systemPrompt, $msgs, 'claude-haiku-4-5-20251001', false);

        if (isset($resp['error'])) {
            echo "API error — skipped\n";
            $failed++;
            continue;
        }

        $aiText = '';
        foreach (($resp['content'] ?? []) as $block) {
            if ($block['type'] === 'text') $aiText .= $block['text'];
        }

        if (preg_match('/\{[^}]+\}/', trim($aiText), $m)) {
            $parsed = json_decode($m[0], true);
            $cSummary = trim($parsed['citizen_summary'] ?? '');
            $pTopic   = trim($parsed['policy_topic'] ?? '');
            if ($pTopic && !in_array($pTopic, MANDATE_POLICY_TOPICS)) {
                $pTopic = 'Other';
            }
            $pdo->prepare("UPDATE idea_log SET citizen_summary = ?, policy_topic = ? WHERE id = ?")
                ->execute([$cSummary ?: null, $pTopic ?: null, $row['id']]);
            echo "{$pTopic}\n";
            $updated++;
        } else {
            echo "no JSON in response — skipped\n";
            $failed++;
        }

        usleep(200000); // 200ms between calls to avoid rate limits
    } catch (\Throwable $e) {
        echo "error: {$e->getMessage()}\n";
        $failed++;
    }
}

echo "\nDone. Updated: {$updated}, Failed: {$failed}\n";
