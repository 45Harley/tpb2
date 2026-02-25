<?php
/**
 * Generate poll rows for all executive threats scoring 300+.
 * Safe to re-run — skips threats that already have polls.
 * Run: php scripts/db/generate-threat-polls.php
 */
$config = require __DIR__ . '/../../config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Get all 300+ threats that don't yet have a poll
$threats = $pdo->query("
    SELECT et.threat_id, et.title, et.severity_score
    FROM executive_threats et
    LEFT JOIN polls p ON p.threat_id = et.threat_id AND p.poll_type = 'threat'
    WHERE et.severity_score >= 300
      AND et.is_active = 1
      AND p.poll_id IS NULL
    ORDER BY et.severity_score DESC
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($threats)) {
    echo "No new threat polls to generate.\n";
    exit(0);
}

$stmt = $pdo->prepare("
    INSERT INTO polls (slug, question, poll_type, threat_id, active, created_by)
    VALUES (?, ?, 'threat', ?, 1, NULL)
");

$count = 0;
foreach ($threats as $t) {
    $slug = 'threat-' . $t['threat_id'];
    $question = $t['title'];
    $stmt->execute([$slug, $question, $t['threat_id']]);
    $count++;
    echo "Created poll for threat #{$t['threat_id']} (severity {$t['severity_score']}): {$t['title']}\n";
}

echo "\nDone. Created {$count} threat polls.\n";

// Summary
$total = $pdo->query("SELECT COUNT(*) FROM polls WHERE poll_type = 'threat'")->fetchColumn();
echo "Total threat polls in DB: {$total}\n";
