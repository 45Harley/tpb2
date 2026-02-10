<?php
/**
 * stand.php - The People's Branch Stand Counter
 * Location: /0t/stand.php
 * Database: sandge5_tpb2
 */

// Database config
$dbConfig = [
    'host' => 'localhost',
    'database' => 'sandge5_tpb2',
    'username' => 'sandge5_tpb2',
    'password' => '.YeO6kSJAHh5',
    'charset' => 'utf8mb4'
];

// Load alliance config
$alliance = json_decode(file_get_contents(__DIR__ . '/alliance.json'), true);

// Connect to database
try {
    $pdo = new PDO(
        "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset={$dbConfig['charset']}",
        $dbConfig['username'],
        $dbConfig['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("Database connection failed");
}

$message = '';
$messageType = '';

// Handle magic link verification
if (isset($_GET['token'])) {
    $token = $_GET['token'];
    $stmt = $pdo->prepare("UPDATE stands SET verified_at = NOW() WHERE token = ? AND verified_at IS NULL");
    $stmt->execute([$token]);
    
    if ($stmt->rowCount() > 0) {
        $message = "✓ Your stand has been counted. Thank you for standing with us.";
        $messageType = "success";
    } else {
        $message = "This link has already been used or is invalid.";
        $messageType = "error";
    }
}

// Handle email submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'])) {
    $email = filter_var(trim($_POST['email']), FILTER_VALIDATE_EMAIL);
    
    // Get state from IP
    $state = null;
    $ip = $_SERVER['REMOTE_ADDR'];
    if ($ip && $ip !== '127.0.0.1') {
        $geo = @json_decode(file_get_contents("http://ip-api.com/json/{$ip}?fields=region"), true);
        if ($geo && isset($geo['region'])) {
            $state = strtoupper(substr($geo['region'], 0, 2));
        }
    }
    
    if (!$email) {
        $message = "Please enter a valid email address.";
        $messageType = "error";
    } else {
        // Check if already exists
        $stmt = $pdo->prepare("SELECT verified_at FROM stands WHERE email = ?");
        $stmt->execute([$email]);
        $existing = $stmt->fetch();
        
        if ($existing && $existing['verified_at']) {
            $message = "You've already taken your stand. Thank you!";
            $messageType = "info";
        } else {
            $token = bin2hex(random_bytes(32));
            
            if ($existing) {
                // Update existing unverified
                $stmt = $pdo->prepare("UPDATE stands SET token = ?, state_code = ? WHERE email = ?");
                $stmt->execute([$token, $state, $email]);
            } else {
                // Insert new
                $stmt = $pdo->prepare("INSERT INTO stands (email, state_code, token) VALUES (?, ?, ?)");
                $stmt->execute([$email, $state, $token]);
            }
            
            // Send magic link email
            $verifyUrl = "https://" . $_SERVER['HTTP_HOST'] . "/0t/stand.php?token=" . $token;
            $subject = "Verify Your Stand - The People's Branch";
            $body = "Click to verify your stand:\n\n$verifyUrl\n\nThe People's Branch";
            $headers = "From: noreply@4tpb.org";
            
            if (mail($email, $subject, $body, $headers)) {
                $message = $alliance['verify_note'];
                $messageType = "success";
            } else {
                $message = "Could not send verification email. Please try again.";
                $messageType = "error";
            }
        }
    }
}

// Get counts
$total = $pdo->query("SELECT COUNT(*) FROM stands WHERE verified_at IS NOT NULL")->fetchColumn();

$stateCounts = $pdo->query("
    SELECT state_code, COUNT(*) as count 
    FROM stands 
    WHERE verified_at IS NOT NULL AND state_code IS NOT NULL 
    GROUP BY state_code 
    ORDER BY count DESC
")->fetchAll(PDO::FETCH_KEY_PAIR);

// Build against list
$againstList = is_array($alliance['against']) ? $alliance['against'] : [$alliance['against']];

// Build allies string
$alliesList = implode(' • ', $alliance['allies']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Take Your Stand - <?= htmlspecialchars($alliance['title']) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #111;
            color: #fff;
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 700px;
            margin: 0 auto;
            text-align: center;
        }
        
        h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            color: #fff;
        }
        
        .subtitle {
            font-size: 1.1rem;
            color: #ccc;
            margin-bottom: 30px;
        }
        
        .statement {
            background: #222;
            border: 3px solid #ff4444;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
        }
        
        .alliance {
            font-size: 1.3rem;
            line-height: 1.6;
            margin-bottom: 20px;
            color: #fff;
        }
        
        .alliance strong {
            color: #44aaff;
        }
        
        .against {
            font-size: 1.3rem;
            font-weight: bold;
            color: #ff6666;
            margin-bottom: 10px;
        }
        
        .against-list {
            list-style: disc;
            margin: 15px 0;
            padding-left: 30px;
            text-align: left;
        }
        
        .against-list li {
            font-size: 1.1rem;
            color: #ff6666;
            padding: 6px 0;
            line-height: 1.4;
        }
        
        .allies {
            font-size: 0.95rem;
            color: #ccc;
            margin-top: 20px;
            font-style: italic;
        }
        
        .counter {
            background: #222;
            border: 3px solid #44aaff;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
        }
        
        .total-count {
            font-size: 3.5rem;
            font-weight: bold;
            color: #44aaff;
        }
        
        .count-label {
            font-size: 1.2rem;
            color: #fff;
        }
        
        .state-counts {
            margin-top: 15px;
            font-size: 0.9rem;
            color: #ddd;
            line-height: 1.8;
        }
        
        .state-counts span {
            display: inline-block;
            background: #333;
            padding: 4px 12px;
            border-radius: 15px;
            margin: 3px;
            color: #fff;
        }
        
        .form-section {
            background: #222;
            border-radius: 12px;
            padding: 25px;
        }
        
        .form-section h2 {
            font-size: 1.3rem;
            margin-bottom: 20px;
            color: #fff;
        }
        
        .form-row {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        input[type="email"] {
            flex: 1;
            padding: 15px;
            font-size: 1rem;
            border: 2px solid #444;
            border-radius: 8px;
            background: #333;
            color: #fff;
        }
        
        input:focus {
            outline: none;
            border-color: #44aaff;
        }
        
        input::placeholder {
            color: #888;
        }
        
        button {
            width: 100%;
            padding: 15px 30px;
            font-size: 1.2rem;
            font-weight: bold;
            background: #ff8c00;
            color: #1a1a5e;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        button:hover {
            background: #ffa333;
        }
        
        .verify-note {
            margin-top: 15px;
            font-size: 0.9rem;
            color: #aaa;
        }
        
        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: bold;
        }
        
        .message.success { background: rgba(68, 170, 255, 0.2); color: #44aaff; }
        .message.error { background: rgba(255, 68, 68, 0.2); color: #ff6666; }
        .message.info { background: rgba(255, 140, 0, 0.2); color: #ff8c00; }
        
        .footer {
            margin-top: 30px;
            font-size: 0.85rem;
            color: #999;
        }
        
        .footer a {
            color: #44aaff;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🗽 Take Your Stand</h1>
        <p class="subtitle"><?= htmlspecialchars($alliance['title']) ?></p>
        
        <div class="statement">
            <p class="alliance">
                <strong><?= htmlspecialchars($alliance['title']) ?></strong> <?= htmlspecialchars($alliance['fighting']) ?>
            </p>
            <p class="against">against:</p>
            <ul class="against-list">
                <?php foreach ($againstList as $item): ?>
                <li><?= htmlspecialchars($item) ?></li>
                <?php endforeach; ?>
            </ul>
            <p class="allies">In solidarity with: <?= htmlspecialchars($alliesList) ?></p>
        </div>
        
        <div class="counter">
            <div class="total-count"><?= number_format($total) ?></div>
            <div class="count-label">Americans Standing</div>
            <?php if (!empty($stateCounts)): ?>
            <div class="state-counts">
                <?php foreach ($stateCounts as $state => $count): ?>
                <span><?= htmlspecialchars($state) ?>: <?= number_format($count) ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if ($message): ?>
        <div class="message <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <div class="form-section">
            <h2>Add Your Voice</h2>
            <form method="POST">
                <div class="form-row">
                    <input type="email" name="email" placeholder="Your email" required>
                </div>
                <button type="submit"><?= htmlspecialchars($alliance['button_text']) ?></button>
                <p class="verify-note"><?= htmlspecialchars($alliance['verify_note']) ?></p>
            </form>
        </div>
        
        <div class="footer">
            <a href="https://4tpb.org"><?= htmlspecialchars($alliance['title']) ?></a> — A Fourth Branch of Government
        </div>
    </div>
</body>
</html>
