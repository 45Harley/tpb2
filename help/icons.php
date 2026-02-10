<?php
/**
 * TPB Modal Icon Reference - Help Page
 * Location: /help/index.php
 */

$config = require '../config.php';

// Database connection
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
require_once __DIR__ . '/../includes/get-user.php';
$dbUser = getUser($pdo);
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
$userTrustLevel = 0;
$userId = null;
$user = null;

if ($pdo && $sessionId) {
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.email, u.first_name, u.last_name,
               u.current_town_id, u.current_state_id, u.civic_points,
               s.abbreviation as state_abbrev,
               tw.town_name,
               uis.email_verified, uis.phone_verified
        FROM user_devices ud
        INNER JOIN users u ON ud.user_id = u.user_id
        LEFT JOIN states s ON u.current_state_id = s.state_id
        LEFT JOIN towns tw ON u.current_town_id = tw.town_id
        LEFT JOIN user_identity_status uis ON u.user_id = uis.user_id
        WHERE ud.device_session = ? AND ud.is_active = 1
    ");
    $stmt->execute([$sessionId]);
    $user = $stmt->fetch();
    
    if ($user) {
        $userId = $user['user_id'];
        $has2FA = ($user['email_verified'] && $user['phone_verified']);
        
        if ($has2FA) {
            $userTrustLevel = 2;
            
            $stmt = $pdo->prepare("
                SELECT status FROM volunteer_applications 
                WHERE user_id = ? 
                ORDER BY applied_at DESC LIMIT 1
            ");
            $stmt->execute([$userId]);
            $volApp = $stmt->fetch();
            
            if ($volApp) {
                if ($volApp['status'] === 'pending') {
                    $userTrustLevel = 3;
                } elseif ($volApp['status'] === 'accepted') {
                    $userTrustLevel = 4;
                }
            }
        } else {
            $userTrustLevel = 1;
        }
    }
}

// Nav variables
$trustLabels = ['Visitor', 'Profile Started', 'Verified (2FA)', 'Applied', 'Volunteer'];
$trustLevel = $trustLabels[$userTrustLevel] ?? 'Visitor';
$points = $user ? (int)($user['civic_points'] ?? 0) : $sessionPoints;
$currentPage = 'help';

$userEmail = $user ? ($user['email'] ?? '') : '';
$userTownName = $user ? ($user['town_name'] ?? '') : '';
$userTownSlug = $userTownName ? strtolower(str_replace(' ', '-', $userTownName)) : '';
$userStateAbbr = $user ? strtolower($user['state_abbrev'] ?? '') : '';
$userStateDisplay = $user ? ($user['state_abbrev'] ?? '') : '';
$isLoggedIn = (bool)$user;

$pageTitle = 'Modal Icon Reference - TPB Help';

// Page-specific styles
$pageStyles = '
    /* ICON REFERENCE PAGE STYLES */
    .container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 40px 20px;
    }
    
    .page-title {
        text-align: center;
        color: #d4af37;
        font-size: 2.2em;
        margin-bottom: 10px;
    }
    
    .page-subtitle {
        text-align: center;
        color: #888;
        margin-bottom: 40px;
        font-size: 1.1em;
    }
    
    /* STATS */
    .stats {
        background: #1a1a2e;
        border: 1px solid #d4af37;
        border-radius: 12px;
        padding: 25px;
        margin-bottom: 40px;
        text-align: center;
    }
    
    .stats h2 {
        color: #d4af37;
        margin-bottom: 15px;
        font-size: 1.5em;
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: 20px;
        margin-top: 20px;
    }
    
    .stat-box {
        background: #0a0a0f;
        padding: 15px;
        border-radius: 8px;
        border-left: 3px solid #d4af37;
    }
    
    .stat-number {
        font-size: 2em;
        font-weight: bold;
        color: #d4af37;
    }
    
    .stat-label {
        color: #888;
        font-size: 0.85em;
    }
    
    /* CATEGORIES */
    .category {
        background: #1a1a2e;
        border: 1px solid #333;
        border-radius: 12px;
        padding: 30px;
        margin-bottom: 30px;
    }
    
    .category-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        padding-bottom: 15px;
        border-bottom: 2px solid #d4af37;
    }
    
    .category-title {
        color: #d4af37;
        font-size: 1.6em;
        font-weight: bold;
    }
    
    .category-count {
        background: #0a0a0f;
        color: #d4af37;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.85em;
    }
    
    /* ICON GRID */
    .icon-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
    }
    
    .icon-card {
        background: #0a0a0f;
        border: 1px solid #333;
        border-radius: 8px;
        padding: 20px;
        transition: all 0.3s ease;
    }
    
    .icon-card:hover {
        transform: translateY(-3px);
        border-color: #d4af37;
        box-shadow: 0 5px 20px rgba(212, 175, 55, 0.2);
    }
    
    .icon-header {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 15px;
    }
    
    .icon-display {
        font-size: 2.5em;
        line-height: 1;
    }
    
    .icon-meta {
        flex: 1;
    }
    
    .icon-name {
        font-size: 1.2em;
        font-weight: bold;
        color: #d4af37;
    }
    
    .icon-code {
        font-family: monospace;
        font-size: 0.8em;
        color: #888;
    }
    
    .icon-description {
        color: #ccc;
        font-size: 0.95em;
        line-height: 1.5;
        margin-bottom: 12px;
    }
    
    .icon-example {
        background: #1a1a2e;
        border-left: 3px solid #d4af37;
        padding: 10px 15px;
        border-radius: 4px;
        font-size: 0.85em;
        color: #999;
        font-style: italic;
    }
    
    /* COLOR LEGEND */
    .color-legend {
        background: #1a1a2e;
        border: 1px solid #333;
        border-radius: 12px;
        padding: 25px;
        margin-top: 30px;
    }
    
    .legend-title {
        color: #d4af37;
        font-size: 1.4em;
        margin-bottom: 20px;
        text-align: center;
    }
    
    .legend-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 15px;
    }
    
    .legend-item {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .legend-swatch {
        width: 24px;
        height: 24px;
        border-radius: 50%;
        border: 2px solid #333;
    }
    
    .legend-text {
        color: #ccc;
        font-size: 0.9em;
    }
';

// Include header and nav
require_once '../includes/header.php';
require_once '../includes/nav.php';
?>

<div class="container">
    <h1 class="page-title">🎨 Modal Icon Reference</h1>
    <p class="page-subtitle">Complete Visual Guide — 16 Icon Types for Contextual Help</p>
    
    <!-- Stats Overview -->
    <div class="stats">
        <h2>System Overview</h2>
        <div class="stats-grid">
            <div class="stat-box">
                <div class="stat-number">16</div>
                <div class="stat-label">Icon Types</div>
            </div>
            <div class="stat-box">
                <div class="stat-number">4</div>
                <div class="stat-label">Categories</div>
            </div>
            <div class="stat-box">
                <div class="stat-number">6</div>
                <div class="stat-label">Color Themes</div>
            </div>
            <div class="stat-box">
                <div class="stat-number">∞</div>
                <div class="stat-label">Use Cases</div>
            </div>
        </div>
    </div>
    
    <!-- CORE ICONS -->
    <div class="category">
        <div class="category-header">
            <div class="category-title">Core Icons</div>
            <div class="category-count">4 icons</div>
        </div>
        <div class="icon-grid">
            <div class="icon-card">
                <div class="icon-header">
                    <div class="icon-display">ℹ️</div>
                    <div class="icon-meta">
                        <div class="icon-name">Info</div>
                        <div class="icon-code">icon_type: 'info'</div>
                    </div>
                </div>
                <div class="icon-description">
                    General information and explanations. Use for "what is this?" questions.
                </div>
                <div class="icon-example">
                    Example: "What is liquid democracy?"
                </div>
            </div>
            
            <div class="icon-card">
                <div class="icon-header">
                    <div class="icon-display">❓</div>
                    <div class="icon-meta">
                        <div class="icon-name">Help</div>
                        <div class="icon-code">icon_type: 'help'</div>
                    </div>
                </div>
                <div class="icon-description">
                    How-to guides and user assistance. Use for "how do I...?" questions.
                </div>
                <div class="icon-example">
                    Example: "How to verify your email"
                </div>
            </div>
            
            <div class="icon-card">
                <div class="icon-header">
                    <div class="icon-display">⏳</div>
                    <div class="icon-meta">
                        <div class="icon-name">Preview</div>
                        <div class="icon-code">icon_type: 'preview'</div>
                    </div>
                </div>
                <div class="icon-description">
                    Coming soon features and beta previews. Use for future functionality.
                </div>
                <div class="icon-example">
                    Example: "Analytics dashboard — launching Q2"
                </div>
            </div>
            
            <div class="icon-card">
                <div class="icon-header">
                    <div class="icon-display">⚠️</div>
                    <div class="icon-meta">
                        <div class="icon-name">Warning</div>
                        <div class="icon-code">icon_type: 'warning'</div>
                    </div>
                </div>
                <div class="icon-description">
                    Important notices and cautions. Use for actions that need awareness.
                </div>
                <div class="icon-example">
                    Example: "Account deletion is permanent"
                </div>
            </div>
        </div>
    </div>
    
    <!-- CONTENT TYPE ICONS -->
    <div class="category">
        <div class="category-header">
            <div class="category-title">Content Type Icons</div>
            <div class="category-count">4 icons</div>
        </div>
        <div class="icon-grid">
            <div class="icon-card">
                <div class="icon-header">
                    <div class="icon-display">💡</div>
                    <div class="icon-meta">
                        <div class="icon-name">Tip</div>
                        <div class="icon-code">icon_type: 'tip'</div>
                    </div>
                </div>
                <div class="icon-description">
                    Pro tips and best practices. Use for helpful suggestions and optimization.
                </div>
                <div class="icon-example">
                    Example: "Vote daily for bonus points"
                </div>
            </div>
            
            <div class="icon-card">
                <div class="icon-header">
                    <div class="icon-display">📘</div>
                    <div class="icon-meta">
                        <div class="icon-name">Docs</div>
                        <div class="icon-code">icon_type: 'docs'</div>
                    </div>
                </div>
                <div class="icon-description">
                    Full documentation links. Use when pointing to comprehensive guides.
                </div>
                <div class="icon-example">
                    Example: "Read complete API documentation"
                </div>
            </div>
            
            <div class="icon-card">
                <div class="icon-header">
                    <div class="icon-display">🎓</div>
                    <div class="icon-meta">
                        <div class="icon-name">Tutorial</div>
                        <div class="icon-code">icon_type: 'tutorial'</div>
                    </div>
                </div>
                <div class="icon-description">
                    Step-by-step learning guides. Use for complete walkthroughs.
                </div>
                <div class="icon-example">
                    Example: "Complete 5-step onboarding guide"
                </div>
            </div>
            
            <div class="icon-card">
                <div class="icon-header">
                    <div class="icon-display">🎯</div>
                    <div class="icon-meta">
                        <div class="icon-name">Feature</div>
                        <div class="icon-code">icon_type: 'feature'</div>
                    </div>
                </div>
                <div class="icon-description">
                    Feature highlights and explanations. Use for "what does this do?"
                </div>
                <div class="icon-example">
                    Example: "What's a trust score?"
                </div>
            </div>
        </div>
    </div>
    
    <!-- ACTION/STATUS ICONS -->
    <div class="category">
        <div class="category-header">
            <div class="category-title">Action/Status Icons</div>
            <div class="category-count">4 icons</div>
        </div>
        <div class="icon-grid">
            <div class="icon-card">
                <div class="icon-header">
                    <div class="icon-display">✅</div>
                    <div class="icon-meta">
                        <div class="icon-name">Success</div>
                        <div class="icon-code">icon_type: 'success'</div>
                    </div>
                </div>
                <div class="icon-description">
                    Confirmations and achievements. Use after successful actions.
                </div>
                <div class="icon-example">
                    Example: "Email verified successfully!"
                </div>
            </div>
            
            <div class="icon-card">
                <div class="icon-header">
                    <div class="icon-display">🚀</div>
                    <div class="icon-meta">
                        <div class="icon-name">New</div>
                        <div class="icon-code">icon_type: 'new'</div>
                    </div>
                </div>
                <div class="icon-description">
                    New feature announcements. Use for recent launches (first 30 days).
                </div>
                <div class="icon-example">
                    Example: "NEW: Mentorship matching available"
                </div>
            </div>
            
            <div class="icon-card">
                <div class="icon-header">
                    <div class="icon-display">🔒</div>
                    <div class="icon-meta">
                        <div class="icon-name">Security</div>
                        <div class="icon-code">icon_type: 'security'</div>
                    </div>
                </div>
                <div class="icon-description">
                    Privacy and security information. Use for data protection topics.
                </div>
                <div class="icon-example">
                    Example: "How we protect your privacy"
                </div>
            </div>
            
            <div class="icon-card">
                <div class="icon-header">
                    <div class="icon-display">⭐</div>
                    <div class="icon-meta">
                        <div class="icon-name">Important</div>
                        <div class="icon-code">icon_type: 'important'</div>
                    </div>
                </div>
                <div class="icon-description">
                    High priority notices. Use sparingly for must-read information.
                </div>
                <div class="icon-example">
                    Example: "Action required: verify your phone"
                </div>
            </div>
        </div>
    </div>
    
    <!-- CONTEXT ICONS -->
    <div class="category">
        <div class="category-header">
            <div class="category-title">Context Icons</div>
            <div class="category-count">4 icons</div>
        </div>
        <div class="icon-grid">
            <div class="icon-card">
                <div class="icon-header">
                    <div class="icon-display">📍</div>
                    <div class="icon-meta">
                        <div class="icon-name">Location</div>
                        <div class="icon-code">icon_type: 'location'</div>
                    </div>
                </div>
                <div class="icon-description">
                    Geography-specific information. Use for district or local context.
                </div>
                <div class="icon-example">
                    Example: "Your district: CT-2 details"
                </div>
            </div>
            
            <div class="icon-card">
                <div class="icon-header">
                    <div class="icon-display">🔗</div>
                    <div class="icon-meta">
                        <div class="icon-name">External</div>
                        <div class="icon-code">icon_type: 'external'</div>
                    </div>
                </div>
                <div class="icon-description">
                    Links that leave TPB. Use when directing to third-party resources.
                </div>
                <div class="icon-example">
                    Example: "Visit official government website"
                </div>
            </div>
            
            <div class="icon-card">
                <div class="icon-header">
                    <div class="icon-display">👥</div>
                    <div class="icon-meta">
                        <div class="icon-name">Social</div>
                        <div class="icon-code">icon_type: 'social'</div>
                    </div>
                </div>
                <div class="icon-description">
                    Community and collaboration features. Use for social aspects.
                </div>
                <div class="icon-example">
                    Example: "Join the community discussion"
                </div>
            </div>
            
            <div class="icon-card">
                <div class="icon-header">
                    <div class="icon-display">🎪</div>
                    <div class="icon-meta">
                        <div class="icon-name">Philosophy</div>
                        <div class="icon-code">icon_type: 'philosophy'</div>
                    </div>
                </div>
                <div class="icon-description">
                    TPB vision and core principles. Use for "why we built this" topics.
                </div>
                <div class="icon-example">
                    Example: "Why mutual mentorship matters"
                </div>
            </div>
        </div>
    </div>
    
    <!-- Color Legend -->
    <div class="color-legend">
        <div class="legend-title">🎨 Color Coding System</div>
        <div class="legend-grid">
            <div class="legend-item">
                <div class="legend-swatch" style="background: #4a90e2;"></div>
                <div class="legend-text">Blue — Informational</div>
            </div>
            <div class="legend-item">
                <div class="legend-swatch" style="background: #2ecc71;"></div>
                <div class="legend-text">Green — Positive/Success</div>
            </div>
            <div class="legend-item">
                <div class="legend-swatch" style="background: #f39c12;"></div>
                <div class="legend-text">Orange — Attention</div>
            </div>
            <div class="legend-item">
                <div class="legend-swatch" style="background: #9b59b6;"></div>
                <div class="legend-text">Purple — Special</div>
            </div>
            <div class="legend-item">
                <div class="legend-swatch" style="background: #7f8c8d;"></div>
                <div class="legend-text">Gray — Contextual</div>
            </div>
            <div class="legend-item">
                <div class="legend-swatch" style="background: #c0392b;"></div>
                <div class="legend-text">Red — Philosophy</div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
