<?php
/**
 * Scan Poller — runs on local PC, polls ai_queue, runs claude -p
 * =================================================================
 * Start: php scripts/maintenance/scan-poller.php
 * Polls every 5 seconds. Ctrl+C to stop.
 * Logs every cycle to show persistent continuity.
 */

$pollInterval = 5; // seconds between polls
$startTime = date('Y-m-d H:i:s');
$cycleCount = 0;
$jobsProcessed = 0;

// Use server DB config — poller talks to the same DB as staging
// For POC: use local config (same DB)
$config = require __DIR__ . '/../../config.php';

echo "=== SCAN POLLER STARTED ===\n";
echo "Time: {$startTime}\n";
echo "Poll interval: {$pollInterval}s\n";
echo "DB: {$config['host']}/{$config['database']}\n";
echo str_repeat('=', 50) . "\n\n";

// Connect
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// Load CT benefits database for prompt context
$benefitsFile = __DIR__ . '/../../help/data/ct-benefits.json';
$stateBenefitsContext = '';
if (file_exists($benefitsFile)) {
    $stateData = json_decode(file_get_contents($benefitsFile), true);
    if ($stateData && !empty($stateData['programs'])) {
        $stateBenefitsContext = "\n\n## Known Programs Database\n";
        foreach ($stateData['programs'] as $prog) {
            $stateBenefitsContext .= "### {$prog['name']} [{$prog['level']}] [{$prog['category']}]\n";
            $stateBenefitsContext .= "Provides: {$prog['provides']}\nEligibility: {$prog['eligibility']}\n";
            $stateBenefitsContext .= "Apply: {$prog['how_to_apply']}\n";
            if (!empty($prog['phone'])) $stateBenefitsContext .= "Phone: {$prog['phone']}\n";
            $stateBenefitsContext .= "\n";
        }
    }
}

while (true) {
    $cycleCount++;
    $now = date('H:i:s');
    $uptime = time() - strtotime($startTime);
    $uptimeStr = gmdate('H:i:s', $uptime);

    // Check for pending scans
    $stmt = $pdo->query("SELECT id, user_id, request_data FROM ai_queue WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1");
    $job = $stmt->fetch();

    if (!$job) {
        echo "[{$now}] cycle #{$cycleCount} | uptime {$uptimeStr} | no pending jobs | processed: {$jobsProcessed}\n";
        sleep($pollInterval);
        continue;
    }

    $jobId = $job['id'];
    echo "[{$now}] cycle #{$cycleCount} | uptime {$uptimeStr} | FOUND JOB #{$jobId} for user {$job['user_id']}\n";

    // Mark as processing
    $pdo->prepare("UPDATE ai_queue SET status = 'processing', started_at = NOW() WHERE id = ?")->execute([$jobId]);

    // Parse request
    $request = json_decode($job['request_data'], true);
    $profile = $request['profile'] ?? [];
    $state = $request['state'] ?? 'CT';
    $stateName = $request['state_name'] ?? 'Connecticut';
    $town = $request['town'] ?? '';

    // Build profile text
    $profileLines = [];
    foreach ($profile as $key => $val) {
        if ($val === null || $val === '' || $key === 'user_id' || $key === 'updated_at' || $key === 'benefits_match_optin') continue;
        $label = ucwords(str_replace('_', ' ', $key));
        $profileLines[] = "- {$label}: {$val}";
    }
    $profileText = implode("\n", $profileLines);

    $age = '';
    if (!empty($profile['date_of_birth'])) {
        $dob = new DateTime($profile['date_of_birth']);
        $age = $dob->diff(new DateTime())->y . ' years old';
    }

    // Build prompt
    $prompt = <<<PROMPT
You are a Benefits Navigator for The People's Branch civic platform.

Your job: Given this person's profile, identify federal and state programs they may qualify for.

## User Profile
Location: {$town}, {$stateName} ({$state})
{$age}

{$profileText}

## Instructions
1. Search for ALL relevant benefit programs — federal, state ({$stateName}), and local
2. Include programs across ALL categories: housing, food, healthcare, energy, tax credits, education, childcare, veterans, disability, employment, senior services
3. For each program provide: name, category, level (Federal/State/Local), what it provides (dollar amounts), why they qualify, how to apply (URL or phone), estimated annual value, confidence (high/medium/low)
4. Say "may qualify" not "you qualify"
5. Include {$stateName}-specific programs: housing finance authority, energy assistance, state Medicaid, food assistance, education grants, childcare subsidies, state tax credits
6. Include municipal programs for {$town} if known
7. Skip programs they clearly don't qualify for
8. Sort by estimated value (highest first)
9. Use ONLY official .gov or well-known org domains for URLs. Provide phone numbers as alternatives.
{$stateBenefitsContext}

## Output Format
Return ONLY valid JSON. No markdown, no code blocks.
{
  "programs": [
    {
      "name": "Program Name",
      "category": "Housing",
      "level": "Federal|State|Local",
      "provides": "What you could receive",
      "why_you_qualify": "Based on your profile: reason",
      "how_to_apply": "URL or phone",
      "estimated_annual_value": 0,
      "confidence": "high|medium|low"
    }
  ],
  "summary": "One paragraph summary",
  "disclaimer": "Standard disclaimer"
}
PROMPT;

    $userMsg = "Based on my profile, what federal and {$stateName} programs might I qualify for?";
    $fullPrompt = $prompt . "\n\n---\n\nUser: " . $userMsg;

    // Write prompt to temp file
    $tempFile = sys_get_temp_dir() . '/tpb-scan-' . $jobId . '.txt';
    file_put_contents($tempFile, $fullPrompt);

    echo "[{$now}] Running claude -p for job #{$jobId}...\n";
    $cmdStart = microtime(true);

    $cmd = 'claude -p --allowedTools "WebSearch,WebFetch" < ' . escapeshellarg($tempFile) . ' 2>&1';
    $output = shell_exec($cmd);
    unlink($tempFile);

    $elapsed = round(microtime(true) - $cmdStart, 1);
    echo "[" . date('H:i:s') . "] claude -p finished in {$elapsed}s | " . strlen($output) . " bytes\n";

    if (!$output) {
        $pdo->prepare("UPDATE ai_queue SET status = 'error', result_data = ?, completed_at = NOW() WHERE id = ?")
            ->execute([json_encode(['error' => 'claude -p returned no output']), $jobId]);
        echo "[" . date('H:i:s') . "] ERROR: no output from claude -p\n";
        $jobsProcessed++;
        sleep($pollInterval);
        continue;
    }

    // Parse JSON from output
    $jsonStr = $output;
    if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $jsonStr, $m)) {
        $jsonStr = $m[1];
    }
    if (preg_match('/\{[\s\S]*"programs"[\s\S]*\}/s', $jsonStr, $m)) {
        $jsonStr = $m[0];
    }

    $parsed = json_decode($jsonStr, true);
    if (!$parsed || !isset($parsed['programs'])) {
        $pdo->prepare("UPDATE ai_queue SET status = 'error', result_data = ?, completed_at = NOW() WHERE id = ?")
            ->execute([json_encode(['error' => 'JSON parse failed', 'raw' => substr($output, 0, 2000)]), $jobId]);
        echo "[" . date('H:i:s') . "] ERROR: couldn't parse JSON\n";
        $jobsProcessed++;
        sleep($pollInterval);
        continue;
    }

    // Fix bare domain URLs
    foreach ($parsed['programs'] as &$prog) {
        $url = trim($prog['how_to_apply'] ?? '');
        if ($url && !preg_match('/^https?:\/\//', $url) && preg_match('/^[a-z0-9].*\.[a-z]{2,}/i', $url)) {
            $prog['how_to_apply'] = 'https://' . $url;
        }
    }
    unset($prog);

    $programCount = count($parsed['programs']);
    $pdo->prepare("UPDATE ai_queue SET status = 'done', result_data = ?, completed_at = NOW() WHERE id = ?")
        ->execute([json_encode($parsed), $jobId]);

    $jobsProcessed++;
    echo "[" . date('H:i:s') . "] SUCCESS: job #{$jobId} done | {$programCount} programs | {$elapsed}s | total processed: {$jobsProcessed}\n";

    sleep($pollInterval);
}
