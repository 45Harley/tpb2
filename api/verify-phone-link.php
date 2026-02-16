<?php
/**
 * TPB2 Verify Phone Link API
 * ==========================
 * Handles the magic link click to confirm phone number
 * 
 * GET /api/verify-phone-link.php?token=xxx
 * 
 * On success: Redirects to demo.php?phone_verified=success
 */

$config = require __DIR__ . '/../config.php';

$token = $_GET['token'] ?? '';
$returnUrl = $_GET['return_url'] ?? '';

if (!$token) {
    die('Invalid verification link.');
}

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Find user by phone verification token
    $stmt = $pdo->prepare("
        SELECT uis.user_id, uis.phone, uis.phone_verify_expires, uis.phone_verified,
               u.civic_points
        FROM user_identity_status uis
        INNER JOIN users u ON uis.user_id = u.user_id
        WHERE uis.phone_verify_token = ?
    ");
    $stmt->execute([$token]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
        die('Invalid or expired verification link. Please request a new one.');
    }

    // Check if already verified
    if ($record['phone_verified']) {
        $redirectUrl = $returnUrl ?: $config['default_redirect'];
        $separator = strpos($redirectUrl, '?') !== false ? '&' : '?';
        header("Location: {$redirectUrl}{$separator}phone_verified=already");
        exit();
    }

    // Check expiration
    if (strtotime($record['phone_verify_expires']) < time()) {
        die('This verification link has expired. Please request a new one.');
    }

    // Mark phone as verified and clear token
    $stmt = $pdo->prepare("
        UPDATE user_identity_status 
        SET phone_verified = 1,
            phone_verify_token = NULL,
            phone_verify_expires = NULL,
            phone_verified_at = NOW()
        WHERE user_id = ?
    ");
    $stmt->execute([$record['user_id']]);

    // Points are awarded by LevelManager → PointLogger (not here — avoids double-award)
    // LevelManager::checkAndAdvance will call PointLogger::award('phone_verified')
    
    // Auto-advance identity level via LevelManager
    require_once __DIR__ . '/../includes/level-manager.php';
    LevelManager::checkAndAdvance($pdo, $record['user_id']);
    
    // Get updated points total
    $stmt = $pdo->prepare("SELECT civic_points FROM users WHERE user_id = ?");
    $stmt->execute([$record['user_id']]);
    $newPoints = (int)$stmt->fetchColumn();

    // Redirect to success
    $redirectUrl = $returnUrl ?: $config['default_redirect'];
    $separator = strpos($redirectUrl, '?') !== false ? '&' : '?';
    header("Location: {$redirectUrl}{$separator}phone_verified=success&points={$newPoints}");
    exit();

} catch (PDOException $e) {
    // Show actual error for debugging
    die('Database error: ' . $e->getMessage());
}
