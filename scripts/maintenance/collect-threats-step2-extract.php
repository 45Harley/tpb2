<?php
/**
 * Step 2 helper: Extract prompt from step1 JSON and write to a text file for claude -p.
 * Runs LOCALLY on Windows.
 *
 * Usage: php collect-threats-step2-extract.php <prompt-json> <output-txt>
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
    echo "ERROR: Bad prompt data or collection disabled\n";
    echo "Status: " . ($data['status'] ?? 'unknown') . "\n";
    if (isset($data['message'])) echo "Message: " . $data['message'] . "\n";
    exit(1);
}

$prompt = $data['system_prompt'] . "\n\n---\n\n" . $data['user_message'];
file_put_contents($outputFile, $prompt);

echo "Prompt written: " . strlen($prompt) . " chars\n";
echo "Window: " . $data['window_start'] . " to " . $data['today'] . "\n";
echo "Existing threats: " . $data['threat_count'] . ", tags: " . $data['tag_count'] . "\n";
