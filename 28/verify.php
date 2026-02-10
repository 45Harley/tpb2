<?php
/**
 * Amendment 28 - Email Verification
 * Verifies signer's email and marks them as verified
 */

$config = require __DIR__ . '/../config.php';

// Database connection
try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("Database connection failed");
}

$token = $_GET['token'] ?? '';
$error = '';
$success = false;
$signerName = '';

if (empty($token)) {
    $error = "Invalid verification link.";
} else {
    try {
        // Find signer by token
        $stmt = $pdo->prepare("SELECT signer_id, name, verified FROM amendment_signers WHERE verification_token = ?");
        $stmt->execute([$token]);
        $signer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$signer) {
            $error = "Invalid or expired verification link.";
        } elseif ($signer['verified']) {
            $signerName = explode(' ', $signer['name'])[0];
            $error = "already_verified";
        } else {
            // Mark as verified
            $stmt = $pdo->prepare("UPDATE amendment_signers SET verified = 1, verified_at = NOW(), verification_token = NULL WHERE signer_id = ?");
            $stmt->execute([$signer['signer_id']]);
            $success = true;
            $signerName = explode(' ', $signer['name'])[0];
        }
    } catch (Exception $e) {
        $error = "Something went wrong. Please try again.";
    }
}

// Get current verified count
$stmt = $pdo->query("SELECT COUNT(*) as count FROM amendment_signers WHERE verified = 1");
$count = $stmt->fetch()['count'] ?? 0;
$countDisplay = number_format($count);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $success ? "Verified!" : "Verify" ?> - Amendment 28</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Georgia, 'Times New Roman', serif;
            background: #0a0a0a;
            color: #e8e8e8;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            line-height: 1.6;
        }
        
        .container {
            max-width: 600px;
            padding: 60px 24px;
            text-align: center;
        }
        
        .icon {
            font-size: 4rem;
            margin-bottom: 24px;
        }
        
        h1 {
            font-size: 2.5rem;
            font-weight: normal;
            margin-bottom: 16px;
        }
        
        .message {
            font-size: 1.25rem;
            color: #b8b8b8;
            margin-bottom: 32px;
        }
        
        .count {
            font-size: 1.1rem;
            color: #888;
            margin-bottom: 48px;
        }
        
        .count strong {
            color: #c9a227;
            font-size: 1.5rem;
        }
        
        .btn {
            display: inline-block;
            padding: 16px 32px;
            font-size: 1.1rem;
            font-family: inherit;
            background: #c9a227;
            color: #0a0a0a;
            text-decoration: none;
            border-radius: 4px;
            transition: background 0.2s;
        }
        
        .btn:hover {
            background: #d4af37;
        }
        
        .btn-secondary {
            background: transparent;
            border: 1px solid #444;
            color: #888;
            margin-left: 16px;
        }
        
        .btn-secondary:hover {
            border-color: #666;
            color: #aaa;
            background: transparent;
        }
        
        .error {
            color: #b87c7c;
        }
        
        .success {
            color: #7cb87c;
        }
        
        .share {
            margin-top: 48px;
            padding-top: 32px;
            border-top: 1px solid #2a2a2a;
        }
        
        .share p {
            color: #888;
            margin-bottom: 16px;
        }
        
        .share-buttons {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .share-btn {
            padding: 12px 20px;
            font-size: 0.95rem;
            background: #1a1a1a;
            border: 1px solid #333;
            color: #ccc;
            text-decoration: none;
            border-radius: 4px;
            transition: all 0.2s;
        }
        
        .share-btn:hover {
            background: #222;
            border-color: #444;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($success): ?>
            <div class="icon">✓</div>
            <h1 class="success">You're Verified, <?= htmlspecialchars($signerName) ?>.</h1>
            <p class="message">
                Your signature is now counted. You're standing with Americans who believe the People should have real power.
            </p>
            <p class="count">
                <strong><?= $countDisplay ?></strong> verified signatures and counting.
            </p>
            
            <div class="share">
                <p>Help this grow. Share with others who believe.</p>
                <div class="share-buttons">
                    <a href="https://twitter.com/intent/tweet?text=I%20just%20signed%20Amendment%2028%20%E2%80%94%20giving%20the%20People%20the%20power%20to%20bypass%20Congress%20and%20pass%20laws%20directly.%20Join%20me.&url=https://4tpb.org/28/" target="_blank" class="share-btn">Share on X</a>
                    <a href="https://www.facebook.com/sharer/sharer.php?u=https://4tpb.org/28/" target="_blank" class="share-btn">Share on Facebook</a>
                    <a href="mailto:?subject=Amendment%2028%20-%20Take%20Back%20Our%20Government&body=I%20just%20signed%20Amendment%2028.%20It%20gives%20the%20People%20the%20power%20to%20propose%20and%20pass%20laws%20directly%2C%20bypassing%20Congress.%20Check%20it%20out%3A%20https%3A%2F%2F4tpb.org%2F28%2F" class="share-btn">Email a Friend</a>
                </div>
            </div>
            
            <div style="margin-top: 48px;">
                <a href="/28/" class="btn">Back to Amendment 28</a>
                <a href="/" class="btn btn-secondary">Learn About TPB</a>
            </div>
            
        <?php elseif ($error === 'already_verified'): ?>
            <div class="icon">✓</div>
            <h1><?= htmlspecialchars($signerName) ?>, You're Already Verified.</h1>
            <p class="message">
                Your signature was counted. Thank you for standing with us.
            </p>
            <p class="count">
                <strong><?= $countDisplay ?></strong> verified signatures and counting.
            </p>
            <div style="margin-top: 32px;">
                <a href="/28/" class="btn">Back to Amendment 28</a>
            </div>
            
        <?php else: ?>
            <div class="icon">✗</div>
            <h1 class="error">Verification Failed</h1>
            <p class="message">
                <?= htmlspecialchars($error) ?>
            </p>
            <div style="margin-top: 32px;">
                <a href="/28/" class="btn">Try Again</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
