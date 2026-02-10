<?php
$config = require 'config.php';
$adminEmail = $config['admin_email'];

// Database connection for points
try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    $pdo = null;
}

// Get session from cookie
$sessionId = $_COOKIE['tpb_civic_session'] ?? null;

// Get points
$sessionPoints = 0;
if ($pdo && $sessionId) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(points_earned), 0) FROM points_log WHERE session_id = ?");
    $stmt->execute([$sessionId]);
    $sessionPoints = (int) $stmt->fetchColumn();
}

// =====================================================
// USER TRUST PATH DETECTION
// =====================================================
// Levels: 
//   0 = Anonymous (no session or no user)
//   1 = Has profile but not 2FA verified
//   2 = Has 2FA (email+phone verified) but not volunteer
//   3 = Volunteer application pending
//   4 = Approved volunteer
// =====================================================
$userTrustLevel = 0;
$userId = null;
$volunteerStatus = null;

if ($pdo && $sessionId) {
    // Check if session links to a user
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.email, u.first_name, u.last_name,
               u.current_town_id, u.current_state_id, u.civic_points,
               u.identity_level_id,
               s.abbreviation as state_abbrev,
               tw.town_name,
               il.level_name as identity_level_name,
               uis.email_verified, uis.phone_verified
        FROM user_devices ud
        INNER JOIN users u ON ud.user_id = u.user_id
        LEFT JOIN states s ON u.current_state_id = s.state_id
        LEFT JOIN towns tw ON u.current_town_id = tw.town_id
        LEFT JOIN user_identity_status uis ON u.user_id = uis.user_id
        LEFT JOIN identity_levels il ON u.identity_level_id = il.level_id
        WHERE ud.device_session = ? AND ud.is_active = 1
    ");
    $stmt->execute([$sessionId]);
    $user = $stmt->fetch();
    
    if ($user) {
        $userId = $user['user_id'];
        $has2FA = ($user['email_verified'] && $user['phone_verified']);
        
        if ($has2FA) {
            $userTrustLevel = 2; // Has 2FA
            
            // Check volunteer status
            $stmt = $pdo->prepare("
                SELECT status FROM volunteer_applications 
                WHERE user_id = ? 
                ORDER BY applied_at DESC LIMIT 1
            ");
            $stmt->execute([$userId]);
            $volApp = $stmt->fetch();
            
            if ($volApp) {
                $volunteerStatus = $volApp['status'];
                if ($volunteerStatus === 'pending') {
                    $userTrustLevel = 3; // Applied, pending
                } elseif ($volunteerStatus === 'accepted') {
                    $userTrustLevel = 4; // Approved volunteer
                }
            }
        } else {
            $userTrustLevel = 1; // Has profile but no 2FA
        }
    }
}

// Nav variables via helper
require_once __DIR__ . '/includes/get-user.php';
$navVars = getNavVarsForUser($user, $sessionPoints);
extract($navVars);
$currentPage = 'story';

$pageTitle = 'Our Story - The People\'s Branch';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="The People's Branch - Making democracy visible through continuous conversation. Built by citizens who refuse to do nothing.">
    
    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://tpb2.sandgems.net/">
    <meta property="og:title" content="The People's Branch - Democracy Made Visible">
    <meta property="og:description" content="Making democracy visible through continuous conversation. Wisdom, AI, and determination building democratic infrastructure on a retirement budget.">
    <meta property="og:image" content="https://tpb2.sandgems.net/PeoplesBranch.png">
    
    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:url" content="https://tpb2.sandgems.net/">
    <meta name="twitter:title" content="The People's Branch - Democracy Made Visible">
    <meta name="twitter:description" content="Making democracy visible through continuous conversation. Built by citizens who refuse to do nothing.">
    <meta name="twitter:image" content="https://tpb2.sandgems.net/PeoplesBranch.png">
    
    <title>The People's Branch - Democracy Made Visible</title>
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Georgia', serif;
            line-height: 1.6;
            color: #333;
            background: #0a0a0a;
            padding-top: 0;
        }
        
        /* HERO SECTION */
        .hero {
            position: relative;
            height: 100vh;
            min-height: 600px;
            background: linear-gradient(rgba(0, 0, 0, 0.4), rgba(0, 0, 0, 0.6)),
                        url('0media/PeoplesBranch.png') center/cover no-repeat;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: white;
        }
        
        .hero-content {
            max-width: 900px;
            padding: 40px 20px;
            animation: fadeIn 1.5s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .hero h1 {
            font-size: 4em;
            margin-bottom: 20px;
            text-shadow: 2px 2px 8px rgba(0,0,0,0.8);
            font-weight: normal;
            letter-spacing: 2px;
        }
        
        .hero .tagline {
            font-size: 1.8em;
            margin-bottom: 30px;
            font-style: italic;
            text-shadow: 2px 2px 6px rgba(0,0,0,0.8);
        }
        
        .hero .quote {
            font-size: 1.3em;
            max-width: 700px;
            margin: 30px auto;
            padding: 20px;
            background: rgba(0, 0, 0, 0.6);
            border-left: 4px solid #d4af37;
            text-align: left;
        }
        
        .hero .attribution {
            text-align: right;
            margin-top: 15px;
            font-style: italic;
            color: #d4af37;
        }
        
        .scroll-indicator {
            position: absolute;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 2em;
            color: white;
            animation: bounce 2s infinite;
        }
        
        @keyframes bounce {
            0%, 100% { transform: translateX(-50%) translateY(0); }
            50% { transform: translateX(-50%) translateY(-10px); }
        }
        
        /* SECTIONS */
        section {
            max-width: 1200px;
            margin: 0 auto;
            padding: 80px 20px;
        }
        
        section h2 {
            font-size: 2.5em;
            margin-bottom: 30px;
            color: #d4af37;
            text-align: center;
        }
        
        section p {
            font-size: 1.2em;
            margin-bottom: 20px;
            line-height: 1.8;
        }
        
        /* THE STORY SECTION */
        .story {
            background: #1a1a1a;
            color: #e0e0e0;
        }
        
        .story-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            margin-top: 40px;
        }
        
        .story-point {
            background: #252525;
            padding: 30px;
            border-radius: 10px;
            border-left: 4px solid #d4af37;
        }
        
        .story-point h3 {
            color: #d4af37;
            font-size: 1.5em;
            margin-bottom: 15px;
        }
        
        .story-point ul {
            list-style: none;
            padding-left: 0;
        }
        
        .story-point li {
            padding: 8px 0;
            padding-left: 25px;
            position: relative;
        }
        
        .story-point li:before {
            content: "✓";
            position: absolute;
            left: 0;
            color: #4caf50;
            font-weight: bold;
        }
        
        .story-point li:has(❌):before,
        .story-point li:contains("❌"):before {
            content: "";
        }
        
        /* Make X marks red and visible */
        .story-point li {
            color: #e0e0e0;
        }
        
        /* THE PROBLEM SECTION */
        .problem {
            background: #2a1a1a;
            color: #e0e0e0;
        }
        
        .audio-player {
            background: #1a1a1a;
            padding: 30px;
            border-radius: 10px;
            margin: 40px auto;
            max-width: 600px;
            text-align: center;
        }
        
        .audio-player h3 {
            color: #d4af37;
            margin-bottom: 20px;
            font-size: 1.8em;
        }
        
        .audio-player audio {
            width: 100%;
            margin: 20px 0;
        }
        
        .lyrics {
            text-align: left;
            font-style: italic;
            color: #ccc;
            font-size: 0.95em;
            line-height: 1.6;
            margin-top: 20px;
            padding: 20px;
            background: #252525;
            border-left: 3px solid #d4af37;
        }
        
        /* THE SOLUTION SECTION */
        .solution {
            background: #1a2a1a;
            color: #e0e0e0;
        }
        
        .video-container {
            margin: 40px auto;
            max-width: 800px;
            text-align: center;
        }
        
        .video-container video {
            width: 100%;
            border-radius: 10px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.5);
        }
        
        .features {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 30px;
            margin-top: 50px;
        }
        
        .feature {
            background: #252525;
            padding: 30px;
            border-radius: 10px;
            text-align: center;
        }
        
        .feature-icon {
            font-size: 3em;
            margin-bottom: 20px;
        }
        
        .feature h3 {
            color: #d4af37;
            margin-bottom: 15px;
        }
        
        /* THE INNOVATION SECTION */
        .innovation {
            background: #1a1a2a;
            color: #e0e0e0;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-top: 40px;
            text-align: center;
        }
        
        .stat {
            background: #252525;
            padding: 30px 20px;
            border-radius: 10px;
            border-top: 4px solid #d4af37;
        }
        
        .stat-number {
            font-size: 3em;
            color: #d4af37;
            font-weight: bold;
            display: block;
            margin-bottom: 10px;
        }
        
        .stat-label {
            font-size: 1.1em;
            color: #ccc;
        }
        
        /* CTA SECTION */
        .cta {
            background: linear-gradient(135deg, #1a1a1a 0%, #2a1a1a 100%);
            color: #e0e0e0;
            text-align: center;
        }
        
        .cta-content {
            max-width: 700px;
            margin: 0 auto;
        }
        
        .cta h2 {
            font-size: 3em;
            margin-bottom: 30px;
        }
        
        .cta-buttons {
            display: flex;
            gap: 20px;
            justify-content: center;
            margin-top: 40px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 18px 40px;
            font-size: 1.2em;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .btn-primary {
            background: #d4af37;
            color: #1a1a1a;
        }
        
        .btn-primary:hover {
            background: #f4cf57;
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(212, 175, 55, 0.3);
        }
        
        .btn-secondary {
            background: transparent;
            color: #d4af37;
            border: 2px solid #d4af37;
        }
        
        .btn-secondary:hover {
            background: rgba(212, 175, 55, 0.1);
            transform: translateY(-2px);
        }
        
        .btn-clicked {
            border-color: #4a90d9 !important;
            color: #4a90d9 !important;
            background: rgba(74, 144, 217, 0.2) !important;
        }
        
        .skill-box {
            background: #252525;
            padding: 25px;
            border-radius: 10px;
            border-left: 4px solid #d4af37;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .skill-box:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        
        .skill-box-clicked {
            border-left-color: #4a90d9 !important;
            background: rgba(74, 144, 217, 0.1) !important;
        }
        
        .skill-box-clicked h3 {
            color: #4a90d9 !important;
        }
        
        .skill-box-clicked h3::after {
            content: ' ✓';
            color: #4a90d9;
        }
        
        /* FOOTER */
        footer {
            background: #0a0a0a;
            color: #888;
            padding: 40px 20px;
            text-align: center;
        }
        
        footer p {
            margin: 10px 0;
        }
        
        footer a {
            color: #d4af37;
            text-decoration: none;
        }
        
        footer a:hover {
            text-decoration: underline;
        }
        
        .no-kings {
            font-size: 1.5em;
            color: #d4af37;
            margin-top: 20px;
            font-weight: bold;
        }
        
        /* RESPONSIVE */
        @media (max-width: 768px) {
            .hero h1 {
                font-size: 2.5em;
            }
            
            .hero .tagline {
                font-size: 1.3em;
            }
            
            .hero .quote {
                font-size: 1.1em;
            }
            
            .story-grid,
            .features,
            .stats {
                grid-template-columns: 1fr;
            }
            
            section {
                padding: 60px 20px;
            }
            
            section h2 {
                font-size: 2em;
            }
            
            .cta h2 {
                font-size: 2em;
            }
            
            .cta-buttons {
                flex-direction: column;
                align-items: center;
            }
            
            .btn {
                width: 100%;
                max-width: 300px;
            }
        }
        
        /* SMOOTH SCROLL */
        html {
            scroll-behavior: smooth;
        }
        
        /* MANIFESTO SECTION */
        .manifesto {
            background: linear-gradient(135deg, #1a1a2a 0%, #2a1a2a 100%);
            color: #e0e0e0;
            text-align: center;
            padding: 100px 20px;
        }

        .manifesto-content {
            max-width: 800px;
            margin: 0 auto;
        }

        .manifesto-lead {
            font-size: 2.5em;
            color: #d4af37;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
        }

        .manifesto-body {
            margin: 50px 0;
        }

        .manifesto-body p {
            font-size: 1.4em;
            line-height: 1.8;
            margin-bottom: 30px;
            color: #e0e0e0;
        }

        .manifesto-cta {
            font-size: 2em;
            color: #d4af37;
            margin-top: 50px;
            padding: 30px;
            border: 2px solid #d4af37;
            display: inline-block;
            border-radius: 10px;
            transition: all 0.3s ease;
            cursor: pointer;
            text-decoration: none;
        }

        .manifesto-cta:hover {
            background: rgba(212, 175, 55, 0.1);
            transform: scale(1.02);
        }

        @media (max-width: 768px) {
            .manifesto-lead {
                font-size: 1.8em;
            }
            
            .manifesto-body p {
                font-size: 1.2em;
            }
            
            .manifesto-cta {
                font-size: 1.5em;
            }
        }

        /* CIVIC POINTS COUNTER */
        .civic-counter {
            position: fixed;
            top: 85px;
            right: 20px;
            background: #1a1a2a;
            border: 2px solid #d4af37;
            border-radius: 10px;
            padding: 15px 20px;
            z-index: 999;
            text-align: center;
            min-width: 140px;
        }
        .civic-counter .points {
            font-size: 2em;
            color: #d4af37;
            font-weight: bold;
        }
        .civic-counter .label {
            font-size: 0.8em;
            color: #888;
        }
        .civic-counter .pulse {
            animation: pointPulse 0.5s ease;
        }
        @keyframes pointPulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); color: #fff; }
            100% { transform: scale(1); }
        }
    </style>
    
    <!-- Modal Help System -->
    <link rel="stylesheet" href="/assets/modal-help.css">
</head>
<body>
    <?php require 'includes/nav.php'; ?>

    <!-- CIVIC POINTS COUNTER -->
    <div class="civic-counter">
        <div class="points" id="civicPoints"><?= $sessionPoints ?></div>
        <div class="label">civic points</div>
    </div>

    <!-- HERO SECTION -->
    <section class="hero">
        <div class="hero-content">
            <h1>THE PEOPLE'S<span class="tpb-help" data-modal="peoples_branch_philosophy"></span> BRANCH</h1>
            <p class="tagline">Democracy Made Visible</p>
            
            <div class="quote">
                "I've seen enough. I can't just watch the one marble man destroy our democracy. So I'm building something."
                <div class="attribution">— Harley, Builder (wise in all ways)</div>
            </div>
        </div>
        
        <div class="scroll-indicator">↓</div>
    </section>

    <!-- THE STORY -->
    <section class="story">
        <h2>The Story</h2>
        
        <p style="text-align: center; font-size: 1.4em; max-width: 800px; margin: 0 auto 50px;">
            <strong>Retired ex-tech. 12 years out.</strong><br>
            Wisdom from decades of building.<br>
            Watching democracy crisis unfold.<br>
            Time to use that wisdom.
        </p>
        
        <div class="story-grid">
            <div class="story-point">
                <h3>The Challenge</h3>
                <ul>
                    <li>Can't hire developers ($100K+ builds)</li>
                    <li>Out of tech for 12 years (adapting fast)</li>
                    <li>Retirement budget (fixed income)</li>
                    <li>Democracy crisis (urgent timeline)</li>
                    <li>But wisdom and AI change everything</li>
                </ul>
            </div>
            
            <div class="story-point">
                <h3>The Solution</h3>
                <ul>
                    <li>Tell AI what to build before bed</li>
                    <li>Wake up to working code</li>
                    <li>Deploy features in minutes</li>
                    <li>$100/month budget (sustainable)</li>
                    <li>Ship real infrastructure weekly</li>
                </ul>
            </div>
            
            <div class="story-point">
                <h3>What's Possible Now</h3>
                <ul>
                    <li>One person building civic tech</li>
                    <li>With accessible AI tools</li>
                    <li>On retirement budget</li>
                    <li>Real progress every week</li>
                    <li>Democracy gets infrastructure</li>
                </ul>
            </div>
            
            <div class="story-point">
                <h3>Why This Matters</h3>
                <ul>
                    <li>Democracy needs infrastructure NOW</li>
                    <li>Every citizen can contribute</li>
                    <li>Technology enables action</li>
                    <li>Wisdom guides the way</li>
                    <li>This is how we fight back</li>
                </ul>
            </div>
            
            <div class="story-point">
                <h3>The Reality</h3>
                <ul>
                    <li>✅ Can build with AI (working)</li>
                    <li>✅ Code ships fast (proven)</li>
                    <li>✅ Budget sustainable ($100/mo)</li>
                    <li>❌ No guarantee officials will care</li>
                    <li>❌ Adoption is uncertain</li>
                    <li>❌ Could fail completely</li>
                    <li>✅ But trying anyway</li>
                </ul>
            </div>
        </div>
    </section>

    <!-- THE PROBLEM -->
    <section class="problem">
        <h2>The Problem</h2>
        
        <p style="text-align: center; font-size: 1.3em; margin-bottom: 40px;">
            We can't just watch. We have to <strong>BUILD</strong>.
        </p>
        
        <div class="audio-player">
            <h3>🎵 "One Marble Man"</h3>
            <p style="color: #ccc; margin-bottom: 20px;">
                A ragtime satire about why we're building this
            </p>
            
            <audio controls>
                <source src="0media/onemarblman.mp3" type="audio/mpeg">
                Your browser does not support the audio element.
            </audio>
            
            <div class="lyrics">
                <strong>Lyrics:</strong><br><br>
                There's a bitty-minded boss in the big white hall,<br>
                Got one marble, thinks it's all,<br>
                Cognitive-deficient plans that twist and bend,<br>
                Start in the middle, skip to the end!<br><br>
                
                <em>Oh, one marble man, you're a curious sight,<br>
                Spinnin' your wheels from morning 'til night,<br>
                Logic took a holiday, common sense ran,<br>
                Still we're followin' the one marble man!</em><br><br>
                
                Scrambled egg brains in a great big chair,<br>
                Talkin' in circles, goin' nowhere,<br>
                Half-baked notions and a noodle-knotted plan,<br>
                All hail the one marble man!<br><br>
                
                But the MAGA crowd's still clappin' on demand,<br>
                They're in love with the one marble man!
            </div>
        </div>
    </section>

    <!-- THE MANIFESTO -->
    <section class="manifesto">
        <div class="manifesto-content">
            <p class="manifesto-lead"><strong>I am grassroots.</strong></p>
            <p class="manifesto-lead"><strong>We are The People.</strong></p>
            
            <div class="manifesto-body">
                <p>We can always do better.<br>
                Together we can build a more perfect Union.</p>
                
                <p>If you are here, your voice can be heard.<br>
                Both individually and collectively.<br>
                Your thoughts are very important.<br>
                Let them be heard.</p>
                
                <p>Let's trust each other, or begin to.</p>
                
                <p>The People's Branch (TPB) is being conceived.<br>
                Initial delivery is hard, but the promise is worth it.<br>
                We will need The People to shepherd this baby into adulthood.</p>
                
                <p>We are family.<br>
                We need The People.<br>
                We need You to help.</p>
                
                <p>We want to sing.<br>
                Let's do it together in beautiful harmonies.</p>
                
                <p>Recognizing each other's ideas as the real gold of economy,<br>
                as the steel feet of progress,<br>
                as the real angels of this nation and the World.</p>
                
                <p>Those thoughts born in true selfless desire to benefit all —<br>
                The Universal <a href="goldenrule.html" target="_blank" style="color: #d4af37; text-decoration: underline;">"Golden Rule"</a> recognized in all cultures<br>
                and by more than 5.9 billion individuals.</p>
            </div>
            
            <a href="#" class="manifesto-cta" id="joinCta" onclick="return false;"><strong>Join us. Help us build TPB.</strong></a>
        </div>
    </section>

    <!-- THE SOLUTION -->
    <section class="solution">
        <h2>The Solution</h2>
        
        <p style="text-align: center; font-size: 1.3em; max-width: 800px; margin: 0 auto 40px;">
            <strong>The People's Branch makes continuous democratic conversation visible.</strong>
        </p>
        
        <div class="video-container">
            <video controls poster="0media/PeoplesBranch.png">
                <source src="0media/Avatar IV Video.mp4" type="video/mp4">
                Your browser does not support the video element.
            </video>
            <p style="margin-top: 20px; color: #ccc; font-style: italic;">
                AI Avatar explaining The People's Branch vision
            </p>
        </div>
        
        <div class="features">
            <div class="feature">
                <div class="feature-icon">📊</div>
                <h3>Continuous Visibility</h3>
                <p>What do people actually care about? See priorities evolve in real-time, not just during elections.</p>
            </div>
            
            <div class="feature">
                <div class="feature-icon">🎯</div>
                <h3>Representative Accountability</h3>
                <p>Representatives see constituent priorities continuously. They reference this data. Accountability automatic.</p>
            </div>
            
            <div class="feature">
                <div class="feature-icon">🔍</div>
                <h3>Transparency</h3>
                <p>Not online voting. Not elections. Just making democratic conversation visible to everyone.</p>
            </div>
        </div>
        
        <p style="text-align: center; font-size: 1.2em; margin-top: 50px; max-width: 700px; margin-left: auto; margin-right: auto;">
            <strong>This isn't about replacing democracy.</strong><br>
            It's about making it <em>work better</em> through transparency.
        </p>
    </section>

    <!-- THE INNOVATION -->
    <section class="innovation">
        <h2>Built With AI</h2>
        
        <p style="text-align: center; font-size: 1.3em; max-width: 800px; margin: 0 auto 40px;">
            One person + Claude AI = shipping democracy infrastructure
        </p>
        
        <div class="stats">
            <div class="stat">
                <span class="stat-number">$100</span>
                <span class="stat-label">Per Month</span>
            </div>
            <div class="stat">
                <span class="stat-number">40+</span>
                <span class="stat-label">Years Experience</span>
            </div>
            <div class="stat">
                <span class="stat-number">0</span>
                <span class="stat-label">VC Funding</span>
            </div>
            <div class="stat">
                <span class="stat-number">∞</span>
                <span class="stat-label">Determination</span>
            </div>
        </div>
        
        <div style="max-width: 700px; margin: 50px auto; text-align: center;">
            <p style="font-size: 1.2em; line-height: 1.8;">
                <strong>This is the new possible:</strong><br>
                Features built overnight. Deployed in mornings.<br>
                Real progress on retirement budget.<br>
                Democracy gets infrastructure it needs.<br>
                <em>Wisdom, AI, and determination.</em>
            </p>
        </div>
    </section>

    <!-- SEE IT IN ACTION - Use Case Video -->
    <section class="use-case" style="padding: 80px 20px; background: linear-gradient(135deg, #1a1a2a 0%, #0a0a1a 100%); text-align: center;">
        <div style="max-width: 900px; margin: 0 auto;">
            <h2 style="color: #d4af37; font-size: 2.5em; margin-bottom: 20px;">See It In Action</h2>
            <p style="font-size: 1.3em; color: #ccc; margin-bottom: 40px;">
                Watch how one citizen's voice becomes civic power.
            </p>
            
            <div style="display: inline-block; background: #000; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,0.5); border: 2px solid #333;">
                <video controls style="max-width: 720px; width: 100%; display: block;" poster="0media/PeoplesBranch.png">
                    <source src="0media/Civic_Power_Unleashed.mp4" type="video/mp4">
                    Your browser does not support the video tag.
                </video>
            </div>
            
            <p style="margin-top: 30px; font-size: 0.95em; color: #888; font-style: italic;">
                * Design use case study — Marco is a composite character illustrating the citizen journey through The People's Branch.
            </p>
        </div>
    </section>

    <!-- CALL TO ACTION -->
    <section class="cta">
        <div class="cta-content">
            <h2>Every Citizen Can Contribute</h2>
            
            <p style="font-size: 1.3em; margin-bottom: 50px;">
                Building democratic infrastructure takes a movement.<br>
                Whatever you can give. However much time you have.<br>
                <strong>Your skills matter.</strong>
            </p>
            
            <div style="max-width: 900px; margin: 0 auto;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 30px; text-align: left; margin-bottom: 50px;">
                    
<?php
// Skill cards from database (single source of truth)
$skillKeys = [
    1 => 'tech', 2 => 'content', 3 => 'governance', 4 => 'connected',
    6 => 'community', 7 => 'educator', 8 => 'pm', 9 => 'storyteller',
    10 => 'trainer', 11 => 'youth', 12 => 'designer', 13 => 'legal',
    14 => 'social', 15 => 'security'
];

if ($pdo) {
    $skillCards = $pdo->query("
        SELECT skill_set_id, icon, card_title, card_description 
        FROM skill_sets 
        ORDER BY skill_set_id
    ")->fetchAll();
    
    foreach ($skillCards as $card) {
        $id = $card['skill_set_id'];
        $key = $skillKeys[$id] ?? 'skill' . $id;
        $icon = htmlspecialchars($card['icon']);
        $title = htmlspecialchars($card['card_title']);
        $desc = str_replace("\n", "<br>", htmlspecialchars($card['card_description']));
        
        echo <<<HTML
                    <div class="skill-box" data-skill="{$key}" onclick="handleSkillClick('{$key}');">
                        <h3 style="color: #d4af37; margin-bottom: 15px;">{$icon} {$title}</h3>
                        <p>{$desc}</p>
                    </div>
                    
HTML;
    }
}
?>
                </div>
            </div>
            
            <div class="cta-buttons">
                <a href="https://tpb2.sandgems.net/demo.php" class="btn btn-primary" data-cta="platform" onclick="this.innerHTML='✓ Try The Platform'; this.classList.add('btn-clicked'); localStorage.setItem('tpb_cta_platform','1');">Try The Platform</a>
            </div>
            
            <p style="margin-top: 50px; font-size: 1.1em; color: #ccc;">
                Not top-down. <strong>WE THE PEOPLE.</strong><br>
                Not venture-backed. <strong>Grassroots.</strong><br>
                Not years away. <strong>Working TODAY.</strong>
            </p>
        </div>
    </section>

    <!-- FOOTER -->
    <footer>
        <!-- VOLUNTEER SECTION -->
        <div style="background: #1a1a1a; border: 1px solid #d4af37; border-radius: 10px; padding: 25px; margin-bottom: 30px; max-width: 600px; margin-left: auto; margin-right: auto;">
            <h3 style="color: #d4af37; margin-bottom: 15px; font-size: 1.3em;">🔨 Want to Help Build TPB?</h3>
            <p style="color: #ccc; margin-bottom: 15px; line-height: 1.6;">
                We're looking for volunteers to help build The People's Branch. 
                Before you can apply, you'll need to complete your profile on our platform 
                (name, email verified, location, and phone number).
            </p>
            <p style="color: #888; font-size: 0.9em; margin-bottom: 20px;">
                Same trust standard we'll hold elected officials to. Fair is fair.
            </p>
            <a href="volunteer/apply.php" style="display: inline-block; background: #d4af37; color: #1a1a1a; padding: 12px 25px; border-radius: 8px; text-decoration: none; font-weight: bold;">Apply to Volunteer</a>
        </div>
        
        <p>&copy; 2025 The People's Branch. Built by citizens, for citizens.</p>
        <p>
            <a href="/demo.php">Platform Demo</a> | 
            <a href="mailto:<?= htmlspecialchars($adminEmail) ?>">Contact</a> | 
            <a href="0media/onemarblman.mp3" target="_blank">Listen: One Marble Man</a>
        </p>
        <p style="margin-top: 20px; font-size: 0.9em;">
            Built with Claude AI • Powered by One        </p>
        <p class="no-kings">No Kings. Only Citizens.</p>
    </footer>

    <!-- CIVIC CLICK TRACKING -->
    <script data-cfasync="false" src="/cdn-cgi/scripts/5c5dd728/cloudflare-static/email-decode.min.js"></script>
    <script>
    // Global trust level for skill card handlers
    var TPB_USER_TRUST_LEVEL = <?= $userTrustLevel ?>;
    </script>
    <script>
    (function() {
        'use strict';

        const CONFIG = {
            apiBase: 'https://tpb2.sandgems.net/api',
            page: 'landing_page',
            debug: false,
            userTrustLevel: <?= $userTrustLevel ?>,
            // Trust levels:
            // 0 = Anonymous (no session or no user)
            // 1 = Has profile but not 2FA verified
            // 2 = Has 2FA (email+phone verified) but not volunteer
            // 3 = Volunteer application pending
            // 4 = Approved volunteer
        };

        // Session handling - prioritize existing cookie (may be linked to user)
        // Only create new session if no cookie exists
        function getCookie(name) {
            const value = `; ${document.cookie}`;
            const parts = value.split(`; ${name}=`);
            if (parts.length === 2) return parts.pop().split(';').shift();
            return null;
        }
        
        let sessionId = getCookie('tpb_civic_session');
        if (!sessionId) {
            // No cookie - check localStorage as fallback
            sessionId = localStorage.getItem('tpb_civic_session');
        }
        if (!sessionId) {
            // No session anywhere - create new one
            sessionId = 'civic_' + Math.random().toString(36).substr(2, 9) + '_' + Date.now();
        }
        // Sync both storage locations
        localStorage.setItem('tpb_civic_session', sessionId);
        document.cookie = 'tpb_civic_session=' + sessionId + '; path=/; max-age=31536000';

        // Points tracking
        let civicPoints = <?= $sessionPoints ?>;
        
        function updatePoints(newTotal) {
            if (newTotal > civicPoints) {
                civicPoints = newTotal;
                const el = document.getElementById('civicPoints');
                if (el) {
                    el.textContent = civicPoints;
                    el.classList.add('pulse');
                    setTimeout(() => el.classList.remove('pulse'), 500);
                }
            }
        }

        let pageVisitLogged = false;

        async function logCivicAction(actionType, elementId = null, extraData = {}) {
            try {
                const payload = {
                    action_type: actionType,
                    page_name: CONFIG.page,
                    element_id: elementId,
                    session_id: sessionId,
                    timestamp: new Date().toISOString(),
                    ...extraData
                };

                if (CONFIG.debug) console.log('[TPB Civic] Logging:', payload);

                const response = await fetch(`${CONFIG.apiBase}/log-civic-click.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (data.session && data.session.total_points) {
                    updatePoints(data.session.total_points);
                }
            } catch (error) {
                if (CONFIG.debug) console.warn('[TPB Civic] Failed:', error);
            }
        }

        function logPageVisit() {
            if (pageVisitLogged) return;
            pageVisitLogged = true;
            logCivicAction('page_visit', null, {
                referrer: document.referrer || 'direct',
                viewport: window.innerWidth + 'x' + window.innerHeight
            });
        }

        function getSkillFromElement(element) {
            const text = element.textContent || '';
            const skillMap = {
                '💻': 'tech', '📣': 'connected', '✍️': 'writer',
                '🏘️': 'community_leader', '🎓': 'educator', '📊': 'project_manager',
                '🎤': 'storyteller', '👥': 'trainer', '🌟': 'youth',
                '🎨': 'designer', '⚖️': 'legal_policy', '📱': 'social_media'
            };
            for (const [emoji, skill] of Object.entries(skillMap)) {
                if (text.includes(emoji)) return skill;
            }
            return null;
        }

        function setupContributionTracking() {
            const boxes = document.querySelectorAll('.cta [style*="background: #252525"]');
            boxes.forEach((box, index) => {
                box.style.cursor = 'pointer';
                box.addEventListener('click', function() {
                    const skill = getSkillFromElement(this);
                    logCivicAction('skill_interest', 'contribution_box_' + index, {
                        skill_type: skill,
                        box_index: index
                    });
                });
            });
        }

        function setupButtonTracking() {
            document.querySelectorAll('.btn-primary, .btn-secondary').forEach(btn => {
                // Get clean text without checkmark for ID
                const cleanText = btn.textContent.replace('✓ ', '').trim();
                const ctaId = btn.dataset.cta || cleanText.toLowerCase().replace(/\s+/g, '_');
                
                // Check if already clicked (from localStorage)
                if (localStorage.getItem('tpb_cta_' + ctaId)) {
                    btn.innerHTML = '✓ ' + cleanText;
                    btn.style.background = 'rgba(74, 144, 217, 0.2)';
                    btn.style.borderColor = '#4a90d9';
                    btn.style.color = '#4a90d9';
                    btn.dataset.clicked = 'true';
                }
                
                btn.addEventListener('click', function(e) {
                    // Don't handle beta button here (handled separately)
                    if (this.dataset.cta === 'beta') return;
                    
                    const buttonText = this.textContent.replace('✓ ', '').trim();
                    
                    // Add checkmark FIRST (before async logging)
                    if (!this.dataset.clicked) {
                        this.dataset.clicked = 'true';
                        localStorage.setItem('tpb_cta_' + ctaId, 'true');
                        this.innerHTML = '✓ ' + buttonText;
                        this.style.background = 'rgba(74, 144, 217, 0.2)';
                        this.style.borderColor = '#4a90d9';
                        this.style.color = '#4a90d9';
                    }
                    
                    // Log action (don't let errors block UI)
                    try {
                        logCivicAction('cta_click', buttonText.toLowerCase().replace(/\s+/g, '_'), {
                            button_text: buttonText,
                            href: this.href || null
                        });
                    } catch (err) {
                        console.warn('Click logging failed:', err);
                    }
                });
            });
        }
        
        // Beta tester form functions (global scope for onclick)
        window.showBetaForm = function() {
            const form = document.getElementById('betaForm');
            const btn = document.querySelector('[data-cta="beta"]');
            
            // Show checkmark immediately (blue)
            btn.innerHTML = '✓ Become A Beta Tester';
            btn.style.background = 'rgba(74, 144, 217, 0.2)';
            btn.style.borderColor = '#4a90d9';
            btn.style.color = '#4a90d9';
            localStorage.setItem('tpb_cta_beta', 'true');
            
            // Toggle form
            if (form.style.display === 'none' || form.style.display === '') {
                form.style.display = 'block';
                document.getElementById('betaEmail').focus();
            } else {
                form.style.display = 'none';
            }
            
            // Log (don't let errors stop UI)
            try {
                logCivicAction('cta_click', 'become_a_beta_tester', {
                    button_text: 'Become A Beta Tester',
                    action: 'opened_form'
                });
            } catch (err) {
                console.warn('Beta click logging failed:', err);
            }
        };
        
        // Restore beta button state on load
        function restoreBetaState() {
            if (localStorage.getItem('tpb_cta_beta')) {
                const btn = document.querySelector('[data-cta="beta"]');
                if (btn) {
                    btn.innerHTML = '✓ Become A Beta Tester';
                    btn.style.background = 'rgba(74, 144, 217, 0.2)';
                    btn.style.borderColor = '#4a90d9';
                    btn.style.color = '#4a90d9';
                }
            }
            if (localStorage.getItem('tpb_beta_signed_up')) {
                const form = document.getElementById('betaForm');
                if (form) {
                    form.style.display = 'block';
                    form.innerHTML = '<p style="color: #4caf50; font-size: 1.2em;">✓ Thanks! We\'ll be in touch soon.</p>';
                }
            }
        }
        
        window.submitBetaEmail = function() {
            const emailInput = document.getElementById('betaEmail');
            const email = emailInput.value.trim();
            if (!email || !email.includes('@')) {
                alert('Please enter a valid email address.');
                return;
            }
            
            // Immediate feedback
            const form = document.getElementById('betaForm');
            form.innerHTML = '<p style="color: #4caf50; font-size: 1.4em; font-weight: bold;">✓ Thanks! We\'ll be in touch soon.</p>';
            
            // Send to API (sends email notification)
            fetch(CONFIG.apiBase + '/beta-signup.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email: email })
            }).catch(function(err) {
                console.warn('Beta signup API error:', err);
            });
            
            localStorage.setItem('tpb_beta_signed_up', 'true');
        };

        function setupMediaTracking() {
            const audio = document.querySelector('audio');
            if (audio) {
                audio.addEventListener('play', () => {
                    logCivicAction('media_play', 'one_marble_man_audio', { media_type: 'audio' });
                });
            }
            const video = document.querySelector('video');
            if (video) {
                video.addEventListener('play', () => {
                    logCivicAction('media_play', 'avatar_video', { media_type: 'video' });
                });
            }
        }

        function setupScrollTracking() {
            const milestones = [25, 50, 75, 100];
            const reached = new Set();
            function checkScroll() {
                const scrollHeight = document.documentElement.scrollHeight - window.innerHeight;
                const scrollPercent = Math.round((window.scrollY / scrollHeight) * 100);
                milestones.forEach(m => {
                    if (scrollPercent >= m && !reached.has(m)) {
                        reached.add(m);
                        logCivicAction('scroll_depth', 'scroll_' + m, { depth_percent: m });
                    }
                });
            }
            let throttle = false;
            window.addEventListener('scroll', function() {
                if (!throttle) {
                    checkScroll();
                    throttle = true;
                    setTimeout(() => throttle = false, 500);
                }
            });
        }

        function setupManifestoTracking() {
            const manifesto = document.querySelector('.manifesto');
            if (manifesto) {
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            logCivicAction('section_view', 'manifesto', {});
                            observer.unobserve(entry.target);
                        }
                    });
                }, { threshold: 0.5 });
                observer.observe(manifesto);

                const cta = document.getElementById('joinCta');
                if (cta) {
                    // Check if already joined
                    if (localStorage.getItem('tpb_joined')) {
                        cta.innerHTML = '<strong>✓ You\'re in. Keep scrolling to see how you might help build with TPB.</strong>';
                        cta.style.background = 'rgba(76, 175, 80, 0.2)';
                        cta.style.borderColor = '#4caf50';
                        cta.style.cursor = 'default';
                    }
                    
                    cta.addEventListener('click', function(e) {
                        e.preventDefault();
                        if (!localStorage.getItem('tpb_joined')) {
                            logCivicAction('manifesto_cta_click', 'join_us', {});
                            localStorage.setItem('tpb_joined', 'true');
                            this.innerHTML = '<strong>✓ You\'re in. Keep scrolling to see how you might help build with TPB.</strong>';
                            this.style.background = 'rgba(76, 175, 80, 0.2)';
                            this.style.borderColor = '#4caf50';
                            this.style.cursor = 'default';
                        }
                    });
                }
            }
        }

        function init() {
            logPageVisit();
            setupContributionTracking();
            setupButtonTracking();
            setupMediaTracking();
            setupScrollTracking();
            setupManifestoTracking();
            restoreBetaState();
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
        } else {
            init();
        }

        window.TPBCivic = {
            logAction: logCivicAction,
            getSessionId: () => sessionId,
            enableDebug: () => { CONFIG.debug = true; console.log('[TPB Civic] Debug enabled'); }
        };
    })();
    </script>

<script>
// Restore CTA and skill box states on page load
(function(){
    // Restore buttons
    if(localStorage.getItem('tpb_cta_platform')){
        var btn = document.querySelector('[data-cta="platform"]');
        if(btn){ btn.innerHTML='✓ Try The Platform'; btn.classList.add('btn-clicked'); }
    }
    if(localStorage.getItem('tpb_cta_beta')){
        var btn = document.querySelector('[data-cta="beta"]');
        if(btn){ btn.innerHTML='✓ Become A Beta Tester'; btn.classList.add('btn-clicked'); }
    }
    // Restore skill boxes
    var skills = ['tech','connected','writer','community','educator','pm','storyteller','trainer','youth','designer','legal','social','security'];
    skills.forEach(function(skill){
        if(localStorage.getItem('tpb_skill_'+skill)){
            var box = document.querySelector('[data-skill="'+skill+'"]');
            if(box){ box.classList.add('skill-box-clicked'); }
        }
    });
})();

// =====================================================
// SKILL CARD TRUST PATH HANDLER
// =====================================================
// Maps skill card names to database skill_set_ids
const skillIdMap = {
    'tech': 1,
    'content': 2,
    'governance': 3,
    'connected': 4,
    'community': 6,
    'educator': 7,
    'pm': 8,
    'storyteller': 9,
    'trainer': 10,
    'youth': 11,
    'designer': 12,
    'legal': 13,
    'social': 14,
    'security': 15
};

function handleSkillClick(skillName) {
    // Log the click
    if (window.TPBCivic) {
        window.TPBCivic.logAction('skill_interest', skillName, { skill_id: skillIdMap[skillName] });
    }
    
    // Mark as clicked visually
    localStorage.setItem('tpb_skill_' + skillName, '1');
    var box = document.querySelector('[data-skill="' + skillName + '"]');
    if (box) box.classList.add('skill-box-clicked');
    
    // Get trust level from global PHP variable
    var trustLevel = (typeof TPB_USER_TRUST_LEVEL !== 'undefined') ? TPB_USER_TRUST_LEVEL : 0;
    var skillId = skillIdMap[skillName] || '';
    
    // Trust path navigation
    if (trustLevel === 0 || trustLevel === 1) {
        // No profile or incomplete 2FA
        if (confirm('Thanks for your interest in ' + getSkillDisplayName(skillName) + '!\n\nTo volunteer, you\'ll need to set up your Two-Factor Authentication profile first (email and phone verification).\n\nWould you like to set up your profile now?')) {
            window.location.href = 'profile.php';
        }
    } else if (trustLevel === 2) {
        // Has 2FA but not a volunteer
        if (confirm('Great! You\'re verified and ready to help with ' + getSkillDisplayName(skillName) + '.\n\nWould you like to apply to become a volunteer?')) {
            window.location.href = 'volunteer/apply.php?skill=' + skillId;
        }
    } else if (trustLevel === 3) {
        // Application pending
        alert('Your volunteer application is pending review.\n\nWe\'ll notify you once it\'s approved. Thanks for your patience!');
    } else if (trustLevel === 4) {
        // Approved volunteer - go to tasks
        window.location.href = 'volunteer/?skill=' + skillId;
    }
}

function getSkillDisplayName(skillName) {
    const names = {
        'tech': 'Technical',
        'content': 'Content Creation',
        'governance': 'Governance',
        'connected': 'Connections',
        'community': 'Community Leadership',
        'educator': 'Education',
        'pm': 'Project Management',
        'storyteller': 'Storytelling',
        'trainer': 'Training',
        'youth': 'Youth Engagement',
        'designer': 'Design',
        'legal': 'Legal/Policy',
        'social': 'Social Media',
        'security': 'Security (Hacker With A Heart)'
    };
    return names[skillName] || skillName;
}

// Initialize Modal Help System
</script>
<script src="/assets/modal-help.js"></script>
<script>
TPBModalHelp.init({ 
    apiBase: '/api/modal', 
    page: 'story',
    enableAnalytics: true,
    enableTooltips: true
});
</script>
</body>
</html>