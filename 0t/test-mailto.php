<?php
/**
 * Email Invite Test
 * Plain text email - like from a friend
 */

$result = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $to = filter_var($_POST['to'] ?? '', FILTER_VALIDATE_EMAIL);
    $subjectChoice = $_POST['subject_choice'] ?? '';
    $customSubject = trim($_POST['custom_subject'] ?? '');
    
    // Use custom subject if provided, otherwise use dropdown
    $subject = $customSubject ?: $subjectChoice;
    
    if (!$to) {
        $error = 'Invalid email address';
    } elseif (!$subject) {
        $error = 'Please select or enter a subject';
    } else {
        $trackingUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/0t/?ref=test&gen=2';
        
        // Plain text - like from a friend
        $message = "Hey,

Check this out:

$trackingUrl

One click. Your voice heard. Pass it on. Bookmark it. Watch it grow. Feel the power.";
        
        $headers = "From: noreply@4tpb.org";
        
        if (@mail($to, $subject, $message, $headers)) {
            $result = "Email sent to: $to";
        } else {
            $error = "Failed to send email";
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Email Invite Test</title>
    <style>
        body { font-family: Georgia, serif; max-width: 600px; margin: 50px auto; padding: 20px; background: #1a1a2e; color: #e8e8e8; }
        h1 { color: #d4af37; }
        label { display: block; margin-top: 15px; color: #888; }
        input, select { width: 100%; padding: 10px; font-size: 14px; background: #111; border: 1px solid #333; color: #fff; margin-top: 5px; box-sizing: border-box; }
        button { padding: 14px 28px; background: #d4af37; color: #000; border: none; font-size: 16px; cursor: pointer; margin-top: 20px; }
        button:hover { background: #e5c54a; }
        .success { background: #2a4a2a; border: 1px solid #4a4; padding: 20px; margin: 20px 0; }
        .error { background: #4a2a2a; border: 1px solid #a44; padding: 20px; margin: 20px 0; }
        .note { background: #2a2a3a; border: 1px solid #444; padding: 15px; margin: 20px 0; color: #aaa; font-size: 0.9rem; }
        .preview { background: #222; border: 1px solid #444; padding: 20px; margin-top: 20px; font-family: monospace; white-space: pre-wrap; color: #ccc; }
        .or { text-align: center; color: #666; margin: 10px 0; }
    </style>
</head>
<body>
    <h1>Email Invite Test</h1>
    
    <?php if ($result): ?>
        <div class="success"><?= htmlspecialchars($result) ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <form method="POST">
        <label>To:
            <input type="email" name="to" placeholder="friend@example.com" required>
        </label>
        
        <label>Subject (choose one):
            <select name="subject_choice">
                <option value="Great way to oppose the Trump Administration. Pass it along.">Great way to oppose the Trump Administration. Pass it along.</option>
                <option value="Glad to see this. Hopefully it will explode and go viral. Wanna help?">Glad to see this. Hopefully it will explode and go viral. Wanna help?</option>
                <option value="I'm sending this to everyone I can think of. You too. Do the viral thing.">I'm sending this to everyone I can think of. You too. Do the viral thing.</option>
            </select>
        </label>
        
        <div class="or">— or write your own —</div>
        
        <label>Custom subject:
            <input type="text" name="custom_subject" placeholder="Your own subject line...">
        </label>
        
        <button type="submit">Send Email</button>
    </form>
    
    <div class="note">
        You can change any of this in your email app when you add your friends' email addresses.
    </div>
    
    <h2 style="color: #888; margin-top: 30px;">Preview:</h2>
    <div class="preview">Hey,

Check this out:

https://4tpb.org/0t/?ref=test&gen=2

One click. Your voice heard. Pass it on. Bookmark it. Watch it grow. Feel the power.</div>
</body>
</html>
