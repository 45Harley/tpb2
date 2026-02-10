<?php
/**
 * TPB Bot Detection Helper
 * ========================
 * Include in API files to check for bot activity
 * 
 * Usage:
 *   require_once __DIR__ . '/bot-detect.php';
 *   $botCheck = checkForBot($pdo, 'form_name', $input);
 *   if ($botCheck['is_bot']) {
 *       // Log and reject silently or with generic error
 *   }
 */

/**
 * Check if submission appears to be from a bot
 * 
 * @param PDO $pdo Database connection
 * @param string $formName Name of the form being submitted
 * @param array $input The POST input data
 * @param int|null $formLoadTime Unix timestamp when form was loaded (from hidden field)
 * @return array ['is_bot' => bool, 'reasons' => array, 'logged' => bool]
 */
function checkForBot($pdo, $formName, $input, $formLoadTime = null) {
    $configPath = __DIR__ . '/../config.php';
    if (!file_exists($configPath)) {
        return ['is_bot' => false, 'reasons' => [], 'logged' => false];
    }
    
    $config = require $configPath;
    $botConfig = $config['bot_detection'] ?? [];
    
    // Master switch
    if (empty($botConfig['enabled'])) {
        return ['is_bot' => false, 'reasons' => [], 'logged' => false];
    }
    
    $reasons = [];
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $sessionId = $input['session_id'] ?? $_COOKIE['tpb_civic_session'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $referrer = $_SERVER['HTTP_REFERER'] ?? '';
    
    // Check 1: Honeypot field filled
    $honeypotField = $botConfig['honeypot_field'] ?? 'website_url';
    $honeypotFilled = !empty($input[$honeypotField]);
    if ($honeypotFilled) {
        $reasons[] = 'honeypot';
    }
    
    // Check 2: Too fast submission
    $minTime = $botConfig['min_submit_time'] ?? 3;
    $tooFast = false;
    if ($formLoadTime && (time() - $formLoadTime) < $minTime) {
        $tooFast = true;
        $reasons[] = 'too_fast';
    }
    
    // Check 3: Missing referrer (optional, less reliable)
    $missingReferrer = empty($referrer);
    // Don't flag as bot just for this, but log it
    
    $isBot = count($reasons) > 0;
    $logged = false;
    
    // Log if suspicious
    if ($isBot || $missingReferrer) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO bot_attempts 
                    (ip_address, session_id, form_name, honeypot_filled, too_fast, missing_referrer, user_agent, extra_data)
                VALUES 
                    (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $ip,
                $sessionId,
                $formName,
                $honeypotFilled ? 1 : 0,
                $tooFast ? 1 : 0,
                $missingReferrer ? 1 : 0,
                substr($userAgent, 0, 500),
                json_encode(['reasons' => $reasons, 'referrer' => $referrer])
            ]);
            $logged = true;
            
            // Check if we should send alert
            if (!empty($botConfig['email_alerts'])) {
                checkAndSendAlert($pdo, $botConfig, $ip);
            }
        } catch (PDOException $e) {
            // Silent fail - don't break form submission over logging
            error_log("Bot detection logging failed: " . $e->getMessage());
        }
    }
    
    return [
        'is_bot' => $isBot,
        'reasons' => $reasons,
        'logged' => $logged
    ];
}

/**
 * Check thresholds and send email alert if exceeded
 */
function checkAndSendAlert($pdo, $botConfig, $currentIp) {
    $alertEmail = $botConfig['alert_email'] ?? null;
    if (!$alertEmail) return;
    
    $ipThreshold = $botConfig['alert_threshold_ip'] ?? 10;
    $totalThreshold = $botConfig['alert_threshold_total'] ?? 50;
    
    try {
        // Check IP threshold
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM bot_attempts 
            WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute([$currentIp]);
        $ipCount = (int) $stmt->fetchColumn();
        
        // Check total threshold
        $stmt = $pdo->query("
            SELECT COUNT(*) FROM bot_attempts 
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $totalCount = (int) $stmt->fetchColumn();
        
        // Send alert if thresholds exceeded (but not on every attempt)
        // Only alert on exact threshold to avoid spam
        if ($ipCount == $ipThreshold) {
            sendBotAlert($alertEmail, "IP threshold reached", 
                "IP $currentIp has made $ipCount suspicious form submissions in the last hour.");
        }
        
        if ($totalCount == $totalThreshold) {
            sendBotAlert($alertEmail, "Total threshold reached", 
                "TPB has received $totalCount suspicious form submissions in the last hour.");
        }
        
    } catch (PDOException $e) {
        error_log("Bot alert check failed: " . $e->getMessage());
    }
}

/**
 * Send bot alert email
 */
function sendBotAlert($to, $subject, $message) {
    $fullSubject = "[TPB Bot Alert] " . $subject;
    $fullMessage = "TPB Bot Detection Alert\n";
    $fullMessage .= "========================\n\n";
    $fullMessage .= $message . "\n\n";
    $fullMessage .= "Time: " . date('Y-m-d H:i:s') . "\n";
    $fullMessage .= "Server: " . ($_SERVER['SERVER_NAME'] ?? 'unknown') . "\n";
    
    $headers = [
        'From: noreply@tpb2.sandgems.net',
        'X-Mailer: PHP/' . phpversion(),
        'Content-Type: text/plain; charset=UTF-8'
    ];
    
    @mail($to, $fullSubject, $fullMessage, implode("\r\n", $headers));
}

/**
 * Generate hidden honeypot field HTML
 * Include this in forms
 */
function getHoneypotField() {
    $configPath = __DIR__ . '/../config.php';
    $config = file_exists($configPath) ? require $configPath : [];
    $fieldName = $config['bot_detection']['honeypot_field'] ?? 'website_url';
    
    // Use CSS to hide, not type="hidden" (bots know to skip those)
    return '<div style="position:absolute;left:-9999px;"><input type="text" name="' . htmlspecialchars($fieldName) . '" tabindex="-1" autocomplete="off"></div>';
}

/**
 * Generate hidden timestamp field HTML
 * Include this in forms to track load time
 */
function getTimestampField() {
    return '<input type="hidden" name="_form_load_time" value="' . time() . '">';
}
