<?php
/**
 * TPB2 Verify Parent Consent
 * ==========================
 * Parent clicks link to approve minor's participation
 * 
 * GET /api/verify-parent-consent.php?token=xxx
 */

$config = [
    'host' => 'localhost',
    'database' => 'sandge5_tpb2',
    'username' => 'sandge5_tpb2',
    'password' => '.YeO6kSJAHh5',
    'charset' => 'utf8mb4'
];

$token = $_GET['token'] ?? '';

if (!$token || strlen($token) !== 64) {
    showPage('Invalid Link', 'This consent link is invalid or has expired.', false);
    exit();
}

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Find user by parent consent token
    $stmt = $pdo->prepare("
        SELECT user_id, first_name, email, parent_email, parent_consent
        FROM users 
        WHERE parent_consent_token = ?
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        showPage('Invalid Link', 'This consent link is invalid or has already been used.', false);
        exit();
    }

    if ($user['parent_consent']) {
        showPage('Already Approved', 'You have already approved ' . htmlspecialchars($user['first_name'] ?: 'this user') . '\'s participation. Thank you!', true);
        exit();
    }

    // Mark parent consent as approved
    $stmt = $pdo->prepare("
        UPDATE users 
        SET parent_consent = 1, 
            parent_consent_at = NOW(),
            parent_consent_token = NULL
        WHERE user_id = ?
    ");
    $stmt->execute([$user['user_id']]);

    $childName = $user['first_name'] ?: 'Your child';
    showPage('Consent Approved! ✓', "Thank you! {$childName} can now fully participate in The People's Branch.", true);

} catch (PDOException $e) {
    showPage('Error', 'Something went wrong. Please try again later.', false);
}

function showPage($title, $message, $success) {
    $bgColor = $success ? '#1a3a2a' : '#3a1a1a';
    $borderColor = $success ? '#2a5a3a' : '#5a2a2a';
    $iconColor = $success ? '#4a8a5a' : '#8a4a4a';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> - The People's Branch</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #0a0a1a, #1a1a2e);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .card {
            background: <?= $bgColor ?>;
            border: 2px solid <?= $borderColor ?>;
            border-radius: 16px;
            padding: 40px;
            max-width: 500px;
            text-align: center;
        }
        .icon {
            font-size: 4em;
            margin-bottom: 20px;
        }
        h1 {
            color: #e0e0e0;
            margin-bottom: 15px;
            font-size: 1.8em;
        }
        p {
            color: #aaa;
            font-size: 1.1em;
            line-height: 1.6;
            margin-bottom: 25px;
        }
        .btn {
            display: inline-block;
            background: #d4af37;
            color: #0a0a0a;
            padding: 12px 30px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: bold;
            transition: background 0.2s;
        }
        .btn:hover {
            background: #e4bf47;
        }
        .footer {
            margin-top: 30px;
            color: #666;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon"><?= $success ? '✓' : '✗' ?></div>
        <h1><?= htmlspecialchars($title) ?></h1>
        <p><?= htmlspecialchars($message) ?></p>
        <a href="https://4tpb.org" class="btn">Visit The People's Branch</a>
        <div class="footer">
            The People's Branch · No Kings. Only Citizens.
        </div>
    </div>
</body>
</html>
    <?php
}
