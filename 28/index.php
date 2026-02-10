<?php
/**
 * Amendment 28 - The People's Amendment
 * One page. One table. One action.
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

// Get signer count
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM amendment_signers WHERE verified = 1");
    $count = $stmt->fetch()['count'] ?? 0;
} catch (Exception $e) {
    $count = 0;
}

// Handle form submission
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $zip = trim($_POST['zip'] ?? '');
    
    // Basic validation
    if (empty($name) || empty($email) || empty($zip)) {
        $message = "All fields are required.";
        $messageType = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
        $messageType = 'error';
    } elseif (!preg_match('/^\d{5}(-\d{4})?$/', $zip)) {
        $message = "Please enter a valid ZIP code.";
        $messageType = 'error';
    } else {
        try {
            // Check if already signed
            $stmt = $pdo->prepare("SELECT signer_id, verified FROM amendment_signers WHERE email = ?");
            $stmt->execute([$email]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                if ($existing['verified']) {
                    $message = "You've already signed. Thank you for standing with us.";
                    $messageType = 'info';
                } else {
                    // Resend verification email
                    $token = bin2hex(random_bytes(32));
                    $stmt = $pdo->prepare("UPDATE amendment_signers SET verification_token = ? WHERE signer_id = ?");
                    $stmt->execute([$token, $existing['signer_id']]);
                    
                    // Get their name for the email
                    $stmt = $pdo->prepare("SELECT name FROM amendment_signers WHERE signer_id = ?");
                    $stmt->execute([$existing['signer_id']]);
                    $signerData = $stmt->fetch();
                    $firstName = explode(' ', $signerData['name'])[0];
                    
                    // Build verification link
                    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
                    $verifyLink = "{$baseUrl}/28/verify.php?token={$token}";
                    
                    // Send verification email
                    $subject = "Verify Your Amendment 28 Signature";
                    $emailBody = "
{$firstName},

Click below to verify your Amendment 28 signature:

{$verifyLink}

Once verified, you'll be counted among the Americans standing up to take back their government.

---
Amendment 28: The power for the People to propose and pass laws directly.
The People's Branch - 4tpb.org
";

                    $headers = [
                        'From: noreply@4tpb.org',
                        'Reply-To: noreply@4tpb.org',
                        'X-Mailer: PHP/' . phpversion(),
                        'Content-Type: text/plain; charset=UTF-8'
                    ];

                    mail($email, $subject, $emailBody, implode("\r\n", $headers));
                    
                    $message = "We sent another verification email. Check your inbox (and spam folder).";
                    $messageType = 'info';
                }
            } else {
                // Generate verification token
                $token = bin2hex(random_bytes(32));
                $ip_hash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
                
                // Insert new signer with token
                $stmt = $pdo->prepare("INSERT INTO amendment_signers (name, email, zip, ip_hash, verification_token) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$name, $email, $zip, $ip_hash, $token]);
                
                // Build verification link
                $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
                $verifyLink = "{$baseUrl}/28/verify.php?token={$token}";
                
                // Send verification email
                $firstName = explode(' ', $name)[0];
                $subject = "Verify Your Amendment 28 Signature";
                $emailBody = "
{$firstName},

Thank you for signing Amendment 28 - The People's Amendment.

Click below to verify your signature:

{$verifyLink}

Once verified, you'll be counted among the Americans standing up to take back their government.

---
Amendment 28: The power for the People to propose and pass laws directly.
The People's Branch - 4tpb.org
";

                $headers = [
                    'From: noreply@4tpb.org',
                    'Reply-To: noreply@4tpb.org',
                    'X-Mailer: PHP/' . phpversion(),
                    'Content-Type: text/plain; charset=UTF-8'
                ];

                $emailSent = mail($email, $subject, $emailBody, implode("\r\n", $headers));
                
                if ($emailSent) {
                    $message = "You're in. Check your email to verify your signature.";
                    $messageType = 'success';
                } else {
                    $message = "Signed! (Email may be delayed - we'll count you once verified.)";
                    $messageType = 'success';
                }
                $count++; // Optimistic update for display
            }
        } catch (Exception $e) {
            $message = "Something went wrong. Please try again.";
            $messageType = 'error';
        }
    }
}

// Format count with commas
$countDisplay = number_format($count);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Amendment 28 - The People's Amendment</title>
    <meta name="description" content="Congress won't fix it. But we all can. One amendment to give the People direct power. Sign up.">
    <meta property="og:title" content="Amendment 28 - The People's Amendment">
    <meta property="og:description" content="Congress won't fix it. But we all can. Sign the amendment that changes everything.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://4tpb.org/28/">
    <meta property="og:image" content="https://4tpb.org/28/og-image.png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Amendment 28 - The People's Amendment">
    <meta name="twitter:description" content="Congress won't fix it. But we all can.">
    <meta name="twitter:image" content="https://4tpb.org/28/og-image.png">
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
            line-height: 1.6;
        }
        
        .container {
            max-width: 720px;
            margin: 0 auto;
            padding: 60px 24px;
        }
        
        /* The Gut Punch */
        .headline {
            font-size: clamp(2.5rem, 8vw, 4.5rem);
            font-weight: normal;
            line-height: 1.1;
            margin-bottom: 48px;
            letter-spacing: -0.02em;
        }
        
        .headline em {
            font-style: italic;
            color: #c9a227;
        }
        
        /* The Problem */
        .problem {
            font-size: 1.35rem;
            margin-bottom: 48px;
            color: #b8b8b8;
        }
        
        .problem p {
            margin-bottom: 24px;
        }
        
        .problem strong {
            color: #e8e8e8;
        }
        
        /* The List */
        .broken-list {
            font-size: 1.25rem;
            margin: 32px 0;
            padding-left: 0;
            list-style: none;
        }
        
        .broken-list li {
            padding: 12px 0;
            border-bottom: 1px solid #2a2a2a;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
        }
        
        .broken-list li:last-child {
            border-bottom: none;
        }
        
        .broken-list .issue {
            color: #e8e8e8;
        }
        
        .broken-list .status {
            color: #cc6666;
            text-align: right;
            flex-shrink: 0;
        }
        
        /* The Solution */
        .solution {
            background: #111;
            border-left: 4px solid #c9a227;
            padding: 32px;
            margin: 48px 0;
        }
        
        .solution h2 {
            font-size: 1.5rem;
            font-weight: normal;
            margin-bottom: 16px;
            color: #c9a227;
        }
        
        .solution p {
            font-size: 1.2rem;
            color: #ccc;
        }
        
        .solution a {
            color: #c9a227;
            text-decoration: underline;
        }
        
        .solution a:hover {
            color: #d4af37;
        }
        
        /* The Mechanism */
        .mechanism {
            font-size: 1.1rem;
            color: #888;
            margin: 48px 0;
            padding: 24px 0;
            border-top: 1px solid #2a2a2a;
            border-bottom: 1px solid #2a2a2a;
        }
        
        .mechanism p {
            margin-bottom: 16px;
        }
        
        .mechanism p:last-child {
            margin-bottom: 0;
        }
        
        /* The Form */
        .signup {
            background: #0f0f0f;
            padding: 48px 32px;
            margin: 48px 0;
            border: 1px solid #2a2a2a;
        }
        
        .signup h2 {
            font-size: 2rem;
            font-weight: normal;
            margin-bottom: 8px;
        }
        
        .signup .subhead {
            color: #888;
            margin-bottom: 32px;
            font-size: 1.1rem;
        }
        
        .form-row {
            margin-bottom: 20px;
        }
        
        .form-row label {
            display: block;
            font-size: 0.9rem;
            color: #888;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .form-row input {
            width: 100%;
            padding: 16px;
            font-size: 1.1rem;
            font-family: inherit;
            background: #1a1a1a;
            border: 1px solid #333;
            color: #e8e8e8;
            border-radius: 4px;
        }
        
        .form-row input:focus {
            outline: none;
            border-color: #c9a227;
        }
        
        .form-row input::placeholder {
            color: #555;
        }
        
        .btn-sign {
            width: 100%;
            padding: 20px;
            font-size: 1.3rem;
            font-family: inherit;
            font-weight: bold;
            background: #c9a227;
            color: #0a0a0a;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 16px;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            transition: background 0.2s;
        }
        
        .btn-sign:hover {
            background: #d4af37;
        }
        
        /* Messages */
        .message {
            padding: 16px;
            margin-bottom: 24px;
            border-radius: 4px;
            font-size: 1rem;
        }
        
        .message.success {
            background: #1a2e1a;
            border: 1px solid #2d4a2d;
            color: #7cb87c;
        }
        
        .message.error {
            background: #2e1a1a;
            border: 1px solid #4a2d2d;
            color: #b87c7c;
        }
        
        .message.info {
            background: #1a1a2e;
            border: 1px solid #2d2d4a;
            color: #7c7cb8;
        }
        
        /* The Count */
        .count {
            text-align: center;
            padding: 32px;
            margin: 48px 0;
        }
        
        .count-number {
            font-size: clamp(3rem, 10vw, 5rem);
            color: #c9a227;
            font-weight: normal;
            line-height: 1;
        }
        
        .count-label {
            font-size: 1.2rem;
            color: #888;
            margin-top: 8px;
        }
        
        /* Share */
        .share {
            text-align: center;
            margin: 48px 0;
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
        
        /* The Close */
        .close {
            text-align: center;
            padding: 48px 0;
            border-top: 1px solid #2a2a2a;
            color: #666;
            font-size: 1rem;
        }
        
        .close a {
            color: #c9a227;
            text-decoration: none;
        }
        
        .close a:hover {
            text-decoration: underline;
        }
        
        /* Quote */
        blockquote {
            font-style: italic;
            color: #888;
            padding: 24px 0;
            font-size: 1.2rem;
        }
        
        blockquote cite {
            display: block;
            margin-top: 12px;
            font-style: normal;
            font-size: 1rem;
            color: #666;
        }
        
        /* Responsive */
        @media (max-width: 600px) {
            .container {
                padding: 40px 20px;
            }
            
            .signup {
                padding: 32px 20px;
            }
            
            .broken-list .status {
                float: none;
                display: block;
                margin-top: 4px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        
        <!-- The Gut Punch -->
        <h1 class="headline">
            Congress won't fix it.<br>
            <em>But we all can.</em>
        </h1>
        
        <!-- The Problem -->
        <div class="problem">
            <p>
                You already know it's broken.
            </p>
            
            <ul class="broken-list">
                <li>
                    <span class="issue">Term limits</span>
                    <span class="status">They won't pass it.</span>
                </li>
                <li>
                    <span class="issue">Money in politics</span>
                    <span class="status">They won't fix it.</span>
                </li>
                <li>
                    <span class="issue">Corruption &amp; accountability</span>
                    <span class="status">They won't touch it.</span>
                </li>
            </ul>
            
            <p>
                <strong>Why?</strong> Because the people who would have to fix it are the same people who benefit from it being broken.
            </p>
        </div>
        
        <!-- The Solution -->
        <div class="solution">
            <h2>One Amendment. Then We Fix Everything.</h2>
            <p>
                Amendment 28 creates a <strong><a href="amendment28_3.html" target="_blank" rel="noopener">national ballot initiative</a></strong> — the power for the People to propose and pass laws directly, bypassing Congress entirely.
            </p>
        </div>
        
        <!-- The Mechanism -->
        <div class="mechanism">
            <p>
                26 states already have ballot initiatives. Citizens propose. Citizens vote. It works.
            </p>
            <p>
                The federal government has no such process. You can vote every 2-4 years and hope. That's it.
            </p>
            <p>
                <strong>Amendment 28 changes that.</strong> Once it passes, every other fix — term limits, campaign finance, accountability — becomes possible. Not through Congress. Through us.
            </p>
        </div>
        
        <!-- The Form -->
        <div class="signup" id="sign">
            <h2>Add Your Name</h2>
            <p class="subhead">Join the movement. It's free. It's your country.</p>
            
            <?php if ($message): ?>
                <div class="message <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            
            <form method="POST" action="/28/#sign">
                <div class="form-row">
                    <label for="name">Your Name</label>
                    <input type="text" id="name" name="name" placeholder="Full name" required 
                           value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                </div>
                
                <div class="form-row">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" placeholder="you@example.com" required
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>
                
                <div class="form-row">
                    <label for="zip">ZIP Code</label>
                    <input type="text" id="zip" name="zip" placeholder="00000" pattern="\d{5}(-\d{4})?" required
                           value="<?= htmlspecialchars($_POST['zip'] ?? '') ?>">
                </div>
                
                <button type="submit" class="btn-sign">I'm In</button>
            </form>
        </div>
        
        <!-- The Count -->
        <div class="count">
            <div class="count-number"><?= $countDisplay ?></div>
            <div class="count-label">Americans have signed</div>
        </div>
        
        <!-- Share -->
        <div class="share">
            <p>Help this grow. Share with others who believe.</p>
            <div class="share-buttons">
                <a href="https://twitter.com/intent/tweet?text=Amendment%2028%20%E2%80%94%20giving%20the%20People%20the%20power%20to%20bypass%20Congress%20and%20pass%20laws%20directly.%20Join%20the%20movement.&url=https://4tpb.org/28/" target="_blank" class="share-btn">Share on X</a>
                <a href="https://www.facebook.com/sharer/sharer.php?u=https://4tpb.org/28/" target="_blank" class="share-btn">Share on Facebook</a>
                <a href="mailto:?subject=Amendment%2028%20-%20Take%20Back%20Our%20Government&body=Congress%20won't%20fix%20it.%20We%20will.%20Amendment%2028%20gives%20the%20People%20the%20power%20to%20propose%20and%20pass%20laws%20directly%2C%20bypassing%20Congress.%20Sign%20up%3A%20https%3A%2F%2F4tpb.org%2F28%2F" class="share-btn">Email a Friend</a>
            </div>
        </div>
        
        <!-- The Quote -->
        <blockquote>
            "We the People of the United States..."
            <cite>— The first three words. The whole point.</cite>
        </blockquote>
        
        <!-- The Close -->
        <div class="close">
            <p>
                The People's Branch &middot; <a href="/">Learn more about TPB</a>
            </p>
        </div>
        
    </div>
</body>
</html>
