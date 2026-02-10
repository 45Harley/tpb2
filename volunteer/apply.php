<?php
/**
 * The People's Branch - Volunteer Application
 * ============================================
 * Apply to become a TPB volunteer
 * 
 * Prerequisites: User must have complete profile before applying
 * On submit: Sends email notification to admin
 */

// Database connection
$config = require __DIR__ . '/../config.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die("Database connection failed");
}

// Load user — standard method
require_once __DIR__ . '/../includes/get-user.php';
$dbUser = getUser($pdo);
$sessionId = $_COOKIE['tpb_civic_session'] ?? null;

// Load user data
$existingApplication = null;
$profileComplete = false;
$missingFields = [];

if (!$dbUser && $sessionId) {
    // Fallback: session-based lookup

    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username, u.email, u.first_name, u.last_name,
               u.current_state_id, u.current_town_id,
               s.abbreviation as state_abbrev, s.state_name,
               tw.town_name,
               uis.phone, uis.email_verified, uis.phone_verified
        FROM user_devices ud
        INNER JOIN users u ON ud.user_id = u.user_id
        LEFT JOIN states s ON u.current_state_id = s.state_id
        LEFT JOIN towns tw ON u.current_town_id = tw.town_id
        LEFT JOIN user_identity_status uis ON u.user_id = uis.user_id
        WHERE ud.device_session = ? AND ud.is_active = 1
    ");
    $stmt->execute([$sessionId]);
    $dbUser = $stmt->fetch();
}

// Check profile completeness (runs for ALL users regardless of how they were found)
if ($dbUser) {
    if (!$dbUser['first_name']) $missingFields[] = 'First Name';
    if (!$dbUser['last_name']) $missingFields[] = 'Last Name';
    if (!$dbUser['email']) $missingFields[] = 'Email';
    if (!$dbUser['email_verified']) $missingFields[] = 'Email Verification';
    if (!$dbUser['current_state_id']) $missingFields[] = 'State';
    if (!$dbUser['current_town_id']) $missingFields[] = 'Town';
    if (!$dbUser['phone']) $missingFields[] = 'Phone Number';
    
    $profileComplete = empty($missingFields);
    
    $stmt = $pdo->prepare("SELECT * FROM volunteer_applications WHERE user_id = ? ORDER BY applied_at DESC LIMIT 1");
    $stmt->execute([$dbUser['user_id']]);
    $existingApplication = $stmt->fetch();
}

// Get skill sets
$skillSets = $pdo->query("SELECT skill_set_id, set_name, description FROM skill_sets ORDER BY set_name")->fetchAll();

// Handle form submission
$error = null;
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dbUser && $profileComplete) {
    $ageRange = $_POST['age_range'] ?? null;
    $skillSetId = $_POST['skill_set_id'] ?? null;
    $motivation = trim($_POST['motivation'] ?? '');
    $experience = trim($_POST['experience'] ?? '');
    $availability = $_POST['availability'] ?? '';
    
    $linkedinUrl = trim($_POST['linkedin_url'] ?? '');
    $websiteUrl = trim($_POST['website_url'] ?? '');
    $githubUrl = trim($_POST['github_url'] ?? '');
    $vouchName = trim($_POST['vouch_name'] ?? '');
    $vouchEmail = trim($_POST['vouch_email'] ?? '');
    $otherVerification = trim($_POST['other_verification'] ?? '');
    
    $parentName = trim($_POST['parent_name'] ?? '');
    $parentEmail = trim($_POST['parent_email'] ?? '');
    
    $agreedReview = isset($_POST['agreed_review']) ? 1 : 0;
    $agreedContribute = isset($_POST['agreed_contribute']) ? 1 : 0;
    
    if (!$ageRange) {
        $error = "Please select your age range.";
    } elseif (!$motivation) {
        $error = "Please tell us why you want to volunteer.";
    } elseif (!$experience) {
        $error = "Please tell us about yourself.";
    } elseif (!$agreedReview || !$agreedContribute) {
        $error = "Please agree to both terms.";
    } elseif ($ageRange === '13-17' && (!$parentName || !$parentEmail)) {
        $error = "Parent/guardian information is required for volunteers under 18.";
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO volunteer_applications (
                user_id, age_range, skill_set_id, motivation, experience, availability,
                parent_name, parent_email,
                linkedin_url, website_url, github_url,
                vouch_name, vouch_email, other_verification,
                agreed_review, agreed_contribute,
                status, applied_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");
        
        try {
            $stmt->execute([
                $dbUser['user_id'], $ageRange, $skillSetId ?: null, $motivation, $experience, $availability,
                $parentName ?: null, $parentEmail ?: null,
                $linkedinUrl ?: null, $websiteUrl ?: null, $githubUrl ?: null,
                $vouchName ?: null, $vouchEmail ?: null, $otherVerification ?: null,
                $agreedReview, $agreedContribute
            ]);
            
            // Send email notification
            $adminEmail = $config['admin_email'];
            $subject = 'New TPB Volunteer Application: ' . $dbUser['first_name'] . ' ' . $dbUser['last_name'];
            
            $emailBody = "NEW VOLUNTEER APPLICATION\n";
            $emailBody .= "=========================\n\n";
            $emailBody .= "APPLICANT INFO\n--------------\n";
            $emailBody .= "Name: " . $dbUser['first_name'] . " " . $dbUser['last_name'] . "\n";
            $emailBody .= "Email: " . $dbUser['email'] . "\n";
            $emailBody .= "Phone: " . ($dbUser['phone'] ?: 'Not provided') . "\n";
            $emailBody .= "Location: " . $dbUser['town_name'] . ", " . $dbUser['state_abbrev'] . "\n";
            $emailBody .= "Age Range: " . $ageRange . "\n";
            $emailBody .= "Username: @" . $dbUser['username'] . "\n\n";
            
            if ($ageRange === '13-17') {
                $emailBody .= "PARENT/GUARDIAN\n---------------\n";
                $emailBody .= "Name: " . $parentName . "\nEmail: " . $parentEmail . "\n\n";
            }
            
            $emailBody .= "MOTIVATION\n----------\n" . $motivation . "\n\n";
            $emailBody .= "BACKGROUND/EXPERIENCE\n---------------------\n" . $experience . "\n\n";
            $emailBody .= "AVAILABILITY\n------------\n" . $availability . "\n\n";
            
            $emailBody .= "VERIFICATION\n------------\n";
            if ($linkedinUrl) $emailBody .= "LinkedIn: " . $linkedinUrl . "\n";
            if ($websiteUrl) $emailBody .= "Website: " . $websiteUrl . "\n";
            if ($githubUrl) $emailBody .= "GitHub: " . $githubUrl . "\n";
            if ($vouchName) $emailBody .= "Vouch: " . $vouchName . " (" . $vouchEmail . ")\n";
            if ($otherVerification) $emailBody .= "Other: " . $otherVerification . "\n";
            if (!$linkedinUrl && !$websiteUrl && !$githubUrl && !$vouchName && !$otherVerification) {
                $emailBody .= "(None provided)\n";
            }
            
            $emailBody .= "\nSUBMITTED\n---------\n" . date('F j, Y g:i A') . "\n\n";
            $emailBody .= "---\nReview at: https://tpb2.sandgems.net/admin.php\n";
            
            $headers = "From: TPB Volunteer System <noreply@sandgems.net>\r\n";
            $headers .= "Reply-To: " . $dbUser['email'] . "\r\n";
            
            mail($adminEmail, $subject, $emailBody, $headers);
            
            // Award points for volunteer application
            require_once __DIR__ . '/../includes/point-logger.php';
            PointLogger::init($pdo);
            PointLogger::awardMilestone($dbUser['user_id'], null, 50, 'volunteer_application');
            
            $success = true;
        } catch (PDOException $e) {
            $error = "Something went wrong. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Volunteer Application | The People's Branch</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=Source+Sans+Pro:wght@300;400;600;700&display=swap');
        
        :root {
            --gold: #d4af37;
            --gold-light: #ffdb58;
            --bg-dark: #0d0d0d;
            --bg-card: #1a1a1a;
            --border: #2a2a2a;
            --text: #e0e0e0;
            --text-dim: #888;
            --success: #2ecc71;
            --warning: #f39c12;
            --error: #e74c3c;
            --info: #3498db;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Source Sans Pro', sans-serif;
            background: var(--bg-dark);
            color: var(--text);
            min-height: 100vh;
            line-height: 1.6;
        }
        
        .header {
            background: linear-gradient(135deg, #1a1a1a 0%, #0d0d0d 100%);
            border-bottom: 2px solid var(--gold);
            padding: 15px 30px;
        }
        
        .logo {
            font-family: 'Cinzel', serif;
            font-size: 1.5rem;
            color: var(--gold);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .main-container { max-width: 700px; margin: 0 auto; padding: 40px 20px; }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--text-dim);
            text-decoration: none;
            margin-bottom: 30px;
        }
        .back-link:hover { color: var(--gold); }
        
        .form-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 35px;
        }
        
        .form-title {
            font-family: 'Cinzel', serif;
            font-size: 1.6rem;
            color: var(--gold);
            margin-bottom: 10px;
        }
        
        .form-subtitle { color: var(--text-dim); margin-bottom: 30px; }
        
        .form-group { margin-bottom: 25px; }
        .form-label { display: block; font-weight: 600; margin-bottom: 8px; }
        
        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 12px 15px;
            background: var(--bg-dark);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text);
            font-size: 1rem;
            font-family: inherit;
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--gold);
        }
        .form-textarea { min-height: 120px; resize: vertical; }
        .form-hint { font-size: 0.85rem; color: var(--text-dim); margin-top: 5px; }
        
        .radio-options { display: flex; flex-direction: column; gap: 10px; }
        .radio-option {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            background: var(--bg-dark);
            border: 1px solid var(--border);
            border-radius: 8px;
            cursor: pointer;
        }
        .radio-option:hover { border-color: var(--gold); }
        .radio-option input { display: none; }
        .radio-option input:checked + .radio-circle { border-color: var(--gold); }
        .radio-option input:checked + .radio-circle::after { opacity: 1; }
        .radio-option input:checked ~ .radio-text { color: var(--gold); }
        .radio-circle {
            width: 20px; height: 20px;
            border: 2px solid #888;
            border-radius: 50%;
            margin-right: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg-card);
        }
        .radio-circle::after {
            content: '';
            width: 10px; height: 10px;
            background: var(--gold);
            border-radius: 50%;
            opacity: 0;
        }
        
        .checkbox-option {
            display: flex;
            align-items: flex-start;
            padding: 12px 15px;
            background: var(--bg-dark);
            border: 1px solid var(--border);
            border-radius: 8px;
            cursor: pointer;
            margin-bottom: 10px;
        }
        .checkbox-option input { display: none; }
        .checkbox-option input:checked + .checkbox-box {
            border-color: var(--gold);
            background: var(--gold);
        }
        .checkbox-option input:checked + .checkbox-box::after { opacity: 1; }
        .checkbox-box {
            width: 20px; height: 20px;
            border: 2px solid #888;
            border-radius: 4px;
            margin-right: 12px;
            margin-top: 2px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            background: var(--bg-card);
        }
        .checkbox-box::after {
            content: '✓';
            color: #000;
            font-weight: 700;
            font-size: 0.8rem;
            opacity: 0;
        }
        
        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: inherit;
            border: none;
        }
        .btn-primary { background: #1a3a5c; color: #fff; }
        .btn-primary:hover { background: #2a4a6c; color: #fff; }
        .btn-secondary {
            background: transparent;
            color: var(--text-dim);
            border: 1px solid var(--border);
        }
        .btn-secondary:hover { border-color: var(--gold); color: var(--gold); }
        
        .form-actions {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
        }
        
        .error-msg {
            background: rgba(231, 76, 60, 0.1);
            border: 1px solid var(--error);
            color: var(--error);
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 25px;
        }
        
        .success-box, .notice-box { text-align: center; padding: 40px; }
        .success-icon { font-size: 4rem; margin-bottom: 20px; }
        .success-title {
            font-family: 'Cinzel', serif;
            font-size: 1.8rem;
            color: var(--gold);
            margin-bottom: 15px;
        }
        .notice-box h2 { color: var(--warning); margin-bottom: 15px; }
        
        .profile-incomplete {
            background: rgba(243, 156, 18, 0.1);
            border: 1px solid var(--warning);
            border-radius: 12px;
            padding: 30px;
            text-align: center;
        }
        .profile-incomplete h2 { color: var(--warning); margin-bottom: 15px; }
        .missing-fields {
            background: var(--bg-dark);
            border-radius: 8px;
            padding: 15px 20px;
            margin: 20px 0;
            text-align: left;
        }
        .missing-fields h4 { color: var(--warning); margin-bottom: 10px; }
        .missing-fields ul { list-style: none; padding: 0; }
        .missing-fields li { padding: 5px 0; color: var(--text-dim); }
        .missing-fields li::before { content: '❌ '; }
        
        .youth-notice {
            background: rgba(52, 152, 219, 0.1);
            border: 1px solid var(--info);
            border-radius: 8px;
            padding: 15px 20px;
            margin-bottom: 25px;
        }
        .youth-notice h4 { color: var(--info); margin-bottom: 8px; }
        .youth-notice p { color: var(--text-dim); margin: 0; }
        
        .info-box {
            background: rgba(212, 175, 55, 0.1);
            border-left: 3px solid var(--gold);
            padding: 15px 20px;
            border-radius: 0 8px 8px 0;
            margin-bottom: 25px;
        }
        .info-box p { margin: 0; }
        
        .parent-fields {
            display: none;
            background: rgba(52, 152, 219, 0.05);
            border: 1px solid var(--info);
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }
        .parent-fields.show { display: block; }
        
        .verification-item { margin-bottom: 15px; }
        .verification-item label { display: block; font-weight: 600; margin-bottom: 5px; }
    </style>
</head>
<body>
    <header class="header">
        <a href="/" class="logo">🏛️ TPB</a>
    </header>
    
    <div class="main-container">
        <a href="./" class="back-link">← Maybe later, take me back</a>
        
        <?php if (!$dbUser): ?>
        <div class="form-card">
            <div class="notice-box">
                <h2>Sign In Required</h2>
                <p style="color: var(--text-dim); margin-bottom: 20px;">You need to be signed in to apply as a volunteer.</p>
                <a href="../index.php" class="btn btn-primary">Sign In First</a>
            </div>
        </div>
        
        <?php elseif (!$profileComplete): ?>
        <div class="form-card">
            <div class="profile-incomplete">
                <div style="font-size: 3rem; margin-bottom: 20px;">📋</div>
                <h2>Complete Your Profile First</h2>
                <p>Before you can apply to volunteer, we need to know who you are.<br>This is the same trust standard we'll hold everyone to.</p>
                <div class="missing-fields">
                    <h4>Please complete:</h4>
                    <ul>
                        <?php foreach ($missingFields as $field): ?>
                        <li><?= htmlspecialchars($field) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <a href="../index.php#join" class="btn btn-primary">Complete My Profile</a>
                <p style="color: var(--text-dim); margin-top: 15px; font-size: 0.9rem;">Fill out your profile, then come back here to apply.</p>
            </div>
        </div>
        
        <?php elseif ($success): ?>
        <div class="form-card">
            <div class="success-box">
                <div class="success-icon">✅</div>
                <h2 class="success-title">Application Submitted!</h2>
                <p style="color: var(--text-dim); margin-bottom: 30px;">Thank you for wanting to help build The People's Branch.<br>We'll review your application and get back to you.</p>
                <div style="background: var(--bg-dark); border-radius: 8px; padding: 20px; margin-bottom: 25px; text-align: left;">
                    <h4 style="color: var(--gold); margin-bottom: 10px;">What happens next:</h4>
                    <ol style="margin-left: 20px; color: var(--text-dim);">
                        <li>We review your story</li>
                        <li>We check your verification links</li>
                        <li>You'll get an email when approved</li>
                    </ol>
                </div>
                <a href="../index.php" class="btn btn-primary">← Back to Platform</a>
            </div>
        </div>
        
        <?php elseif ($existingApplication && $existingApplication['status'] === 'pending'): ?>
        <div class="form-card">
            <div class="notice-box">
                <div style="font-size: 3rem; margin-bottom: 20px;">⏳</div>
                <h2>Application Pending</h2>
                <p style="color: var(--text-dim); margin-bottom: 20px;">You already have a pending application submitted on <?= date('M j, Y', strtotime($existingApplication['applied_at'])) ?>.</p>
                <a href="../index.php" class="btn btn-secondary">Back to Platform</a>
            </div>
        </div>
        
        <?php elseif ($existingApplication && $existingApplication['status'] === 'accepted'): ?>
        <div class="form-card">
            <div class="notice-box">
                <div style="font-size: 3rem; margin-bottom: 20px;">🎉</div>
                <h2 style="color: var(--success);">You're Already a Volunteer!</h2>
                <p style="color: var(--text-dim); margin-bottom: 20px;">Head to the task board to find work.</p>
                <a href="./" class="btn btn-primary">View Tasks</a>
            </div>
        </div>
        
        <?php else: ?>
        <div class="form-card">
            <h2 class="form-title">Volunteer Application</h2>
            <p class="form-subtitle">Help us know who you are. Trust matters.</p>
            
            <?php if ($error): ?>
            <div class="error-msg"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <div style="background: var(--bg-dark); border-radius: 8px; padding: 15px 20px; margin-bottom: 25px;">
                <h4 style="color: var(--gold); margin-bottom: 10px;">Your Profile ✓</h4>
                <p style="color: var(--text-dim); margin: 0;">
                    <?= htmlspecialchars($dbUser['first_name'] . ' ' . $dbUser['last_name']) ?><br>
                    <?= htmlspecialchars($dbUser['email']) ?><br>
                    <?= htmlspecialchars($dbUser['town_name'] . ', ' . $dbUser['state_abbrev']) ?><br>
                    <?= htmlspecialchars($dbUser['phone']) ?>
                </p>
            </div>
            
            <form method="POST">
                <div class="form-group">
                    <label class="form-label">How old are you?</label>
                    <div class="radio-options">
                        <label class="radio-option"><input type="radio" name="age_range" value="13-17" onchange="toggleParentFields()"><span class="radio-circle"></span><span class="radio-text">13 - 17</span></label>
                        <label class="radio-option"><input type="radio" name="age_range" value="18-24" onchange="toggleParentFields()"><span class="radio-circle"></span><span class="radio-text">18 - 24</span></label>
                        <label class="radio-option"><input type="radio" name="age_range" value="25-44" onchange="toggleParentFields()"><span class="radio-circle"></span><span class="radio-text">25 - 44</span></label>
                        <label class="radio-option"><input type="radio" name="age_range" value="45-64" onchange="toggleParentFields()"><span class="radio-circle"></span><span class="radio-text">45 - 64</span></label>
                        <label class="radio-option"><input type="radio" name="age_range" value="65+" onchange="toggleParentFields()"><span class="radio-circle"></span><span class="radio-text">65+</span></label>
                    </div>
                </div>
                
                <div class="parent-fields" id="parentFields">
                    <div class="youth-notice">
                        <h4>🌟 Young Volunteer</h4>
                        <p>Awesome that you want to help! Since you're under 18, we need a parent or guardian's information.</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Parent/Guardian Name</label>
                        <input type="text" name="parent_name" class="form-input" placeholder="Their name">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Parent/Guardian Email</label>
                        <input type="email" name="parent_email" class="form-input" placeholder="their@email.com">
                        <p class="form-hint">We'll send them a note explaining TPB.</p>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">What skills can you contribute?</label>
                    <select name="skill_set_id" class="form-select">
                        <option value="">Select a skill area...</option>
                        <?php foreach ($skillSets as $skill): ?>
                        <option value="<?= $skill['skill_set_id'] ?>"><?= htmlspecialchars($skill['set_name']) ?></option>
                        <?php endforeach; ?>
                        <option value="">Not sure yet</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Why do you want to help build TPB?</label>
                    <p style="color: #d4af37; font-size: 0.85em; margin-bottom: 10px;">📝 All content must be appropriate for all ages, 5 to 125.</p>
                    <textarea name="motivation" class="form-textarea" placeholder="What draws you to this project?"><?= htmlspecialchars($_POST['motivation'] ?? '') ?></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Tell us about yourself</label>
                    <textarea name="experience" class="form-textarea" placeholder="Background, skills, experience..." style="min-height: 150px;"><?= htmlspecialchars($_POST['experience'] ?? '') ?></textarea>
                    <p class="form-hint">This helps us understand how you might contribute.</p>
                </div>
                
                <div class="form-group">
                    <label class="form-label">How much time can you give?</label>
                    <select name="availability" class="form-select">
                        <option value="">Select...</option>
                        <option value="few_minutes">A few minutes when I can</option>
                        <option value="1-2_hours">An hour or two per week</option>
                        <option value="several_hours">Several hours per week</option>
                        <option value="committed">I want this to be a real commitment</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">How can we verify you? (optional but helps)</label>
                    <div class="info-box"><p><strong>Why verification?</strong> TPB is built on trust. Same standard we'll hold officials to.</p></div>
                    <div class="verification-item">
                        <label>LinkedIn Profile</label>
                        <input type="text" name="linkedin_url" class="form-input" placeholder="https://linkedin.com/in/yourname">
                    </div>
                    <div class="verification-item">
                        <label>Personal Website</label>
                        <input type="text" name="website_url" class="form-input" placeholder="https://yoursite.com">
                    </div>
                    <div class="verification-item">
                        <label>GitHub Profile</label>
                        <input type="text" name="github_url" class="form-input" placeholder="https://github.com/username">
                    </div>
                    <div class="verification-item">
                        <label>Someone who can vouch for you</label>
                        <input type="text" name="vouch_name" class="form-input" placeholder="Their name" style="margin-bottom: 8px;">
                        <input type="text" name="vouch_email" class="form-input" placeholder="Their email">
                    </div>
                    <div class="verification-item">
                        <label>Other verification</label>
                        <input type="text" name="other_verification" class="form-input" placeholder="Any other way we can verify you?">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="checkbox-option">
                        <input type="checkbox" name="agreed_review" value="1">
                        <span class="checkbox-box"></span>
                        <span>I understand my information will be reviewed</span>
                    </label>
                    <label class="checkbox-option">
                        <input type="checkbox" name="agreed_contribute" value="1">
                        <span class="checkbox-box"></span>
                        <span>I agree to contribute honestly and respectfully</span>
                    </label>
                </div>
                
                <div class="form-actions">
                    <a href="./" class="btn btn-secondary">← Not ready yet</a>
                    <button type="submit" class="btn btn-primary">Submit Application →</button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
    function toggleParentFields() {
        const ageRadios = document.querySelectorAll('input[name="age_range"]');
        const parentFields = document.getElementById('parentFields');
        ageRadios.forEach(radio => {
            if (radio.checked && radio.value === '13-17') {
                parentFields.classList.add('show');
            } else if (radio.checked) {
                parentFields.classList.remove('show');
            }
        });
    }
    </script>
</body>
</html>
