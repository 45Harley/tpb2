<?php
/**
 * AI Poller — generic queue processor for all AI jobs
 * =====================================================
 * Polls ai_queue via HTTP, routes by job_type, runs claude -p locally.
 *
 * Start: php scripts/maintenance/ai-poller.php [local|remote]
 *   local  = reads from local MySQL (localhost dev)
 *   remote = reads from staging via HTTP (default)
 */

$mode = $argv[1] ?? 'remote';
$pollInterval = 5;
$startTime = date('Y-m-d H:i:s');
$cycleCount = 0;
$jobsProcessed = 0;

// Remote config
$baseUrl = 'https://tpb2.sandgems.net/api';
$pollerKey = 'tpb-poller-2026-secure';

// Local config
$localConfig = null;
$localPdo = null;

if ($mode === 'local') {
    $localConfig = require __DIR__ . '/../../config.php';
    $localPdo = new PDO(
        "mysql:host={$localConfig['host']};dbname={$localConfig['database']};charset={$localConfig['charset']}",
        $localConfig['username'], $localConfig['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

echo "=== AI POLLER STARTED ({$mode}) ===\n";
echo "Time: {$startTime}\n";
echo "Poll interval: {$pollInterval}s\n";
echo "Mode: {$mode}\n";
if ($mode === 'remote') echo "Server: {$baseUrl}\n";
echo str_repeat('=', 50) . "\n\n";

// Job type handlers — each returns a prompt string
$handlers = [];

// Register handlers
$handlers['benefits_scan'] = function($job) {
    return buildBenefitsScanPrompt($job);
};

// Future handlers:
// $handlers['claudia_chat'] = function($job) { ... };
// $handlers['statement_collect'] = function($job) { ... };
// $handlers['truthfulness'] = function($job) { ... };

function buildBenefitsScanPrompt($job) {
    $request = json_decode($job['request_data'], true);
    $profile = $request['profile'] ?? [];
    $state = $request['state'] ?? 'CT';
    $stateName = $request['state_name'] ?? 'Connecticut';
    $town = $request['town'] ?? '';

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

    // Load state benefits DB
    $stateBenefitsContext = '';
    $benefitsFile = __DIR__ . '/../../help/data/' . strtolower($state) . '-benefits.json';
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

    return <<<PROMPT
You are a Benefits Navigator for The People's Branch civic platform.

## User Profile
Location: {$town}, {$stateName} ({$state})
{$age}

{$profileText}

## Instructions
1. Search for ALL relevant benefit programs — federal, state ({$stateName}), and local
2. Categories: housing, food, healthcare, energy, tax credits, education, childcare, veterans, disability, employment, senior services
3. For each: name, category, level (Federal/State/Local), provides (dollar amounts), why_you_qualify, how_to_apply (URL/phone), estimated_annual_value, confidence (high/medium/low)
4. Say "may qualify" not "you qualify"
5. Include {$stateName}-specific programs
6. Include municipal programs for {$town} if known
7. Skip programs they clearly don't qualify for
8. Sort by estimated value (highest first)
9. Use ONLY official .gov or well-known org domains for URLs
{$stateBenefitsContext}

## Output Format
Return ONLY valid JSON. No markdown, no code blocks.
{"programs":[{"name":"","category":"","level":"","provides":"","why_you_qualify":"","how_to_apply":"","estimated_annual_value":0,"confidence":""}],"summary":"","disclaimer":""}

---

User: Based on my profile, what federal and {$stateName} programs might I qualify for?
PROMPT;
}

// === Main loop ===
while (true) {
    $cycleCount++;
    $now = date('H:i:s');
    $uptime = time() - strtotime($startTime);
    $uptimeStr = gmdate('H:i:s', $uptime);

    // Get next job
    $job = null;

    if ($mode === 'local') {
        $stmt = $localPdo->query("SELECT id, job_type, user_id, request_data FROM ai_queue WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1");
        $job = $stmt->fetch() ?: null;
        if ($job) {
            $localPdo->prepare("UPDATE ai_queue SET status = 'processing', started_at = NOW() WHERE id = ?")->execute([$job['id']]);
        }
    } else {
        $ch = curl_init("{$baseUrl}/ai-pending.php?key={$pollerKey}");
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
        if ($data && $data['status'] === 'found') {
            $job = $data['job'];
        }
    }

    if (!$job) {
        echo "[{$now}] cycle #{$cycleCount} | uptime {$uptimeStr} | idle | processed: {$jobsProcessed}\n";
        sleep($pollInterval);
        continue;
    }

    $jobId = $job['id'];
    $jobType = $job['job_type'] ?? 'benefits_scan';
    echo "[{$now}] cycle #{$cycleCount} | uptime {$uptimeStr} | JOB #{$jobId} type={$jobType} user={$job['user_id']}\n";

    // Route to handler
    if (!isset($handlers[$jobType])) {
        echo "[{$now}] ERROR: unknown job_type '{$jobType}'\n";
        $resultStatus = 'error';
        $resultData = json_encode(['error' => "Unknown job type: {$jobType}"]);
    } else {
        $prompt = $handlers[$jobType]($job);

        $tempFile = sys_get_temp_dir() . '/tpb-ai-' . $jobId . '.txt';
        file_put_contents($tempFile, $prompt);

        echo "[{$now}] Running claude -p for {$jobType} #{$jobId}...\n";
        $cmdStart = microtime(true);

        $cmd = 'claude -p --allowedTools "WebSearch,WebFetch" < ' . escapeshellarg($tempFile) . ' 2>&1';
        $output = shell_exec($cmd);
        unlink($tempFile);

        $elapsed = round(microtime(true) - $cmdStart, 1);
        echo "[" . date('H:i:s') . "] claude -p done in {$elapsed}s | " . strlen($output ?? '') . " bytes\n";

        if (!$output) {
            $resultStatus = 'error';
            $resultData = json_encode(['error' => 'claude -p returned no output']);
        } else {
            $jsonStr = $output;
            if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $jsonStr, $m)) $jsonStr = $m[1];
            if (preg_match('/\{[\s\S]*"programs"[\s\S]*\}/s', $jsonStr, $m)) $jsonStr = $m[0];

            $parsed = json_decode($jsonStr, true);
            if (!$parsed) {
                $resultStatus = 'error';
                $resultData = json_encode(['error' => 'JSON parse failed', 'raw' => substr($output, 0, 2000)]);
            } else {
                // Fix bare URLs
                if (isset($parsed['programs'])) {
                    foreach ($parsed['programs'] as &$prog) {
                        $url = trim($prog['how_to_apply'] ?? '');
                        if ($url && !preg_match('/^https?:\/\//', $url) && preg_match('/^[a-z0-9].*\.[a-z]{2,}/i', $url)) {
                            $prog['how_to_apply'] = 'https://' . $url;
                        }
                    }
                    unset($prog);
                }
                $resultStatus = 'done';
                $resultData = json_encode($parsed);
            }
        }
    }

    // Store result
    if ($mode === 'local') {
        $localPdo->prepare("UPDATE ai_queue SET status = ?, result_data = ?, completed_at = NOW() WHERE id = ?")
            ->execute([$resultStatus, $resultData, $jobId]);
    } else {
        $ch = curl_init("{$baseUrl}/ai-complete.php?key={$pollerKey}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['job_id' => $jobId, 'status' => $resultStatus, 'result' => $resultData]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'TPB-Poller/1.0',
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        curl_exec($ch);
        $postCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($postCode !== 200) {
            echo "[" . date('H:i:s') . "] ERROR posting result: HTTP {$postCode}\n";
        }
    }

    $jobsProcessed++;
    $count = $resultStatus === 'done' && isset($parsed['programs']) ? count($parsed['programs']) : 0;
    echo "[" . date('H:i:s') . "] {$resultStatus}: job #{$jobId} | {$count} items | {$elapsed}s | total: {$jobsProcessed}\n";

    sleep($pollInterval);
}
