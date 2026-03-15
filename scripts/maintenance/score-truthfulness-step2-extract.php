<?php
/**
 * Truthfulness Scoring Pipeline — Step 2: Extract prompt for claude -p
 * Runs LOCALLY on Windows.
 *
 * Usage: php score-truthfulness-step2-extract.php <prompt-json> <output-txt>
 */

$inputFile = $argv[1] ?? '';
$outputFile = $argv[2] ?? '';

if (!$inputFile || !file_exists($inputFile)) {
    echo "ERROR: Input file not found: {$inputFile}\n";
    exit(1);
}
if (!$outputFile) {
    echo "ERROR: No output file specified\n";
    exit(1);
}

$data = json_decode(file_get_contents($inputFile), true);
if (!$data || ($data['status'] ?? '') !== 'ready') {
    echo "ERROR: Bad prompt data or scoring disabled\n";
    echo "Status: " . ($data['status'] ?? 'unknown') . "\n";
    if (isset($data['message'])) echo "Message: " . $data['message'] . "\n";
    exit(1);
}

$prompt = $data['system_prompt'] . "\n\n---\n\n" . $data['user_message'];
file_put_contents($outputFile, $prompt);

echo "Prompt written: " . strlen($prompt) . " chars\n";
echo "Unclustered: " . $data['unclustered_count'] . ", Existing clusters: " . $data['cluster_count'] . ", Threats: " . $data['threat_count'] . "\n";
