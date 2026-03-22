<?php
/**
 * Scan Poller (Remote) — polls staging server via HTTP, runs claude -p locally
 * ==============================================================================
 * Start: php scripts/maintenance/scan-poller-remote.php
 * Polls every 5 seconds. Ctrl+C to stop.
 */

$pollInterval = 5;
$startTime = date('Y-m-d H:i:s');
$cycleCount = 0;
$jobsProcessed = 0;

$baseUrl = 'https://tpb2.sandgems.net/api';
$pollerKey = 'tpb-poller-2026-secure';

echo "=== SCAN POLLER (REMOTE) STARTED ===\n";
echo "Time: {$startTime}\n";
echo "Poll interval: {$pollInterval}s\n";
echo "Server: {$baseUrl}\n";
echo str_repeat('=', 50) . "\n\n";

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

    // Check for pending jobs via HTTP GET
    $ch = curl_init("{$baseUrl}/scan-pending.php?key={$pollerKey}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'TPB-Poller/1.0',
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err || $httpCode !== 200) {
        echo "[{$now}] cycle #{$cycleCount} | uptime {$uptimeStr} | HTTP ERROR: {$httpCode} {$err}\n";
        sleep($pollInterval);
        continue;
    }

    $data = json_decode($resp, true);
    if (!$data || $data['status'] === 'empty') {
        echo "[{$now}] cycle #{$cycleCount} | uptime {$uptimeStr} | no pending jobs | processed: {$jobsProcessed}\n";
        sleep($pollInterval);
        continue;
    }

    if (isset($data['error'])) {
        echo "[{$now}] cycle #{$cycleCount} | uptime {$uptimeStr} | ERROR: {$data['error']}\n";
        sleep($pollInterval);
        continue;
    }

    $job = $data['job'];
    $jobId = $job['id'];
    echo "[{$now}] cycle #{$cycleCount} | uptime {$uptimeStr} | FOUND JOB #{$jobId} for user {$job['user_id']}\n";

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

    // Build prompt (same as scan-poller.php)
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
5. Include {$stateName}-specific programs
6. Include municipal programs for {$town} if known
7. Skip programs they clearly don't qualify for
8. Sort by estimated value (highest first)
9. Use ONLY official .gov or well-known org domains for URLs
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

    // Write prompt to temp file and run claude -p
    $tempFile = sys_get_temp_dir() . '/tpb-scan-' . $jobId . '.txt';
    file_put_contents($tempFile, $fullPrompt);

    echo "[{$now}] Running claude -p for job #{$jobId}...\n";
    $cmdStart = microtime(true);

    $cmd = 'claude -p --allowedTools "WebSearch,WebFetch" < ' . escapeshellarg($tempFile) . ' 2>&1';
    $output = shell_exec($cmd);
    unlink($tempFile);

    $elapsed = round(microtime(true) - $cmdStart, 1);
    echo "[" . date('H:i:s') . "] claude -p finished in {$elapsed}s | " . strlen($output ?? '') . " bytes\n";

    // Parse result
    $resultStatus = 'done';
    $resultData = '';

    if (!$output) {
        $resultStatus = 'error';
        $resultData = json_encode(['error' => 'claude -p returned no output']);
    } else {
        $jsonStr = $output;
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $jsonStr, $m)) $jsonStr = $m[1];
        if (preg_match('/\{[\s\S]*"programs"[\s\S]*\}/s', $jsonStr, $m)) $jsonStr = $m[0];

        $parsed = json_decode($jsonStr, true);
        if (!$parsed || !isset($parsed['programs'])) {
            $resultStatus = 'error';
            $resultData = json_encode(['error' => 'JSON parse failed', 'raw' => substr($output, 0, 2000)]);
        } else {
            // Fix bare URLs
            foreach ($parsed['programs'] as &$prog) {
                $url = trim($prog['how_to_apply'] ?? '');
                if ($url && !preg_match('/^https?:\/\//', $url) && preg_match('/^[a-z0-9].*\.[a-z]{2,}/i', $url)) {
                    $prog['how_to_apply'] = 'https://' . $url;
                }
            }
            unset($prog);
            $resultData = json_encode($parsed);
        }
    }

    // Post result back to server
    $ch = curl_init("{$baseUrl}/scan-complete.php?key={$pollerKey}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['job_id' => $jobId, 'status' => $resultStatus, 'result' => $resultData]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'TPB-Poller/1.0',
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $postResp = curl_exec($ch);
    $postCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($postCode === 200) {
        $programCount = 0;
        if ($resultStatus === 'done') {
            $p = json_decode($resultData, true);
            $programCount = count($p['programs'] ?? []);
        }
        echo "[" . date('H:i:s') . "] SUCCESS: job #{$jobId} posted back | {$programCount} programs | {$elapsed}s | total: " . (++$jobsProcessed) . "\n";
    } else {
        echo "[" . date('H:i:s') . "] ERROR posting result: HTTP {$postCode} | {$postResp}\n";
        $jobsProcessed++;
    }

    sleep($pollInterval);
}
