<?php
/**
 * TPB Shared Navigation (2-Row Layout)
 * =====================================
 * Include after header.php
 * 
 * Required variables:
 *   $currentPage - string: 'profile', 'voice', 'government', 'town', 'poll', 'power', 'volunteer', 'story', 'login', 'home'
 * 
 * Optional variables (for status display):
 *   $trustLevel - string, e.g., 'Verified (2FA)'
 *   $points - int, civic points
 *   $userTrustLevel - int, 0-4 for routing (if not set, defaults to 0)
 *   $isLoggedIn - bool, whether user is logged in (if not set, checks for visitor)
 *   $userEmail - string, user's email address
 *   $userTownName - string, e.g., 'Putnam' (display name)
 *   $userTownSlug - string, e.g., 'putnam' (for URL)
 *   $userStateAbbr - string, e.g., 'ct' (lowercase, for URL)
 *   $userStateDisplay - string, e.g., 'CT' (uppercase, for display)
 */

$currentPage = isset($currentPage) ? $currentPage : '';
$trustLevel = isset($trustLevel) ? $trustLevel : 'Visitor';
$points = isset($points) ? (int)$points : 0;
$userTrustLevel = isset($userTrustLevel) ? (int)$userTrustLevel : 0;
$isLoggedIn = isset($isLoggedIn) ? $isLoggedIn : ($trustLevel !== 'Visitor');

// Variables for email and town
$userEmail = isset($userEmail) ? $userEmail : '';
$userTownName = isset($userTownName) ? $userTownName : '';
$userTownSlug = isset($userTownSlug) ? $userTownSlug : '';
$userStateAbbr = isset($userStateAbbr) ? $userStateAbbr : '';
$userStateDisplay = isset($userStateDisplay) ? $userStateDisplay : strtoupper($userStateAbbr);

$hasTown = !empty($userTownSlug) && !empty($userStateAbbr);
$hasEmail = !empty($userEmail);

// Truncate email after @+5 chars if needed
$emailDisplay = $userEmail;
if ($hasEmail) {
    $atPos = strpos($userEmail, '@');
    if ($atPos !== false && strlen($userEmail) > $atPos + 6) {
        $emailDisplay = substr($userEmail, 0, $atPos + 6) . '...';
    }
}
?>
    <!-- Navigation (2-row) -->
    <style>
    .top-nav {
        background: linear-gradient(180deg, #1a1a2e 0%, #16213e 100%);
        border-bottom: 1px solid #d4af37;
        position: sticky;
        top: 0;
        z-index: 1000;
    }
    .nav-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.3rem 1rem;
        max-width: 1200px;
        margin: 0 auto;
    }
    .nav-row-1 {
        border-bottom: 1px solid rgba(212, 175, 55, 0.2);
    }
    .nav-row-2 {
        padding: 0.25rem 1rem;
    }
    .nav-brand {
        font-size: 1.4rem;
        font-weight: bold;
        color: #d4af37;
        text-decoration: none;
    }
    .nav-brand:hover {
        color: #e4bf47;
    }
    .nav-status {
        display: flex;
        gap: 0.75rem;
        align-items: center;
        font-size: 0.85rem;
    }
    .nav-status a {
        text-decoration: none;
    }
    .nav-status a:hover {
        text-decoration: underline;
    }
    .nav-status .email-link {
        color: #88c0d0;
    }
    .nav-status .add-link {
        color: #f39c12;
        font-style: italic;
    }
    .nav-status .town-link {
        color: #88c0d0;
    }
    .nav-status .divider {
        color: #555;
    }
    .nav-status .points {
        color: #888;
    }
    .nav-status .level {
        color: #d4af37;
        font-weight: 500;
    }
    .nav-status .logout-link,
    .nav-status .login-link {
        color: #e0e0e0;
        padding: 0.2rem 0.5rem;
        border: 1px solid #555;
        border-radius: 4px;
    }
    .nav-status .logout-link:hover,
    .nav-status .login-link:hover {
        background: rgba(255,255,255,0.1);
        text-decoration: none;
    }
    .nav-links {
        display: flex;
        gap: 0.4rem;
        flex-wrap: wrap;
        justify-content: center;
        width: 100%;
    }
    .nav-links a {
        color: #e0e0e0;
        text-decoration: none;
        padding: 0.4rem 0.7rem;
        border-radius: 4px;
        font-size: 0.9rem;
        transition: all 0.2s;
    }
    .nav-links a:hover {
        background: rgba(212, 175, 55, 0.2);
        color: #fff;
    }
    .nav-links a.active {
        background: rgba(212, 175, 55, 0.3);
        color: #d4af37;
    }
    .nav-links a.add-link {
        color: #f39c12;
        font-style: italic;
    }
    @media (max-width: 900px) {
        .nav-row-1 {
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .nav-status {
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 0.4rem;
            font-size: 0.8rem;
        }
    }
    @media (max-width: 768px) {
        .nav-row { padding: 0.4rem 0.5rem; }
        .nav-links { gap: 0.2rem; }
        .nav-links a { padding: 0.3rem 0.5rem; font-size: 0.8rem; }
        .nav-brand { font-size: 1.2rem; }
    }
    </style>
    
    <nav class="top-nav">
        <!-- Row 1: Brand + Status (Email, Town, Pts, Level, Logout) -->
        <div class="nav-row nav-row-1">
            <a href="/" class="nav-brand">🏛️ TPB</a>
            <div class="nav-status">
                <?php if ($isLoggedIn): ?>
                    <?php if ($hasEmail): ?>
                    <a href="/profile.php#email" class="email-link" title="<?= htmlspecialchars($userEmail) ?>"><?= htmlspecialchars($emailDisplay) ?></a>
                    <?php else: ?>
                    <a href="/profile.php#email" class="add-link">Add Email</a>
                    <?php endif; ?>
                    <span class="divider">|</span>
                    <?php if ($hasTown): ?>
                    <a href="/z-states/<?= htmlspecialchars($userStateAbbr) ?>/<?= htmlspecialchars($userTownSlug) ?>/" class="town-link"><?= htmlspecialchars($userTownName) ?>, <?= htmlspecialchars($userStateDisplay) ?></a>
                    <?php else: ?>
                    <a href="/profile.php#town" class="add-link">Add Town</a>
                    <?php endif; ?>
                    <span class="divider">|</span>
                    <span class="points"><?= $points ?> pts</span>
                    <span class="divider">|</span>
                    <span class="level"><?= htmlspecialchars($trustLevel) ?></span>
                    <span class="divider">|</span>
                    <a href="/logout.php" class="logout-link">Logout</a>
                <?php else: ?>
                    <span class="level"><?= htmlspecialchars($trustLevel) ?></span>
                    <span class="divider">|</span>
                    <a href="/login.php" class="login-link">Login</a>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Row 2: Main Navigation Links -->
        <div class="nav-row nav-row-2">
            <div class="nav-links">
                <?php if ($isLoggedIn): ?>
                <a href="/profile.php" <?= $currentPage === 'profile' ? 'class="active"' : '' ?>>Me</a>
                <?php if ($hasTown): ?>
                <a href="/z-states/<?= htmlspecialchars($userStateAbbr) ?>/<?= htmlspecialchars($userTownSlug) ?>/" <?= $currentPage === 'town' ? 'class="active"' : '' ?>>My Town</a>
                <?php else: ?>
                <a href="/profile.php#town" class="add-link">Add Town</a>
                <?php endif; ?>
                <a href="/voice.php" <?= $currentPage === 'voice' ? 'class="active"' : '' ?>>My Voice</a>
                <a href="/reps.php?my=1" <?= $currentPage === 'government' ? 'class="active"' : '' ?>>My Government</a>
                <?php else: ?>
                <a href="/login.php" <?= $currentPage === 'login' ? 'class="active"' : '' ?>>Login</a>
                <?php endif; ?>
                <a href="/poll/" <?= $currentPage === 'poll' ? 'class="active"' : '' ?>>Polls</a>
                <a href="/0t/" <?= $currentPage === 'power' ? 'class="active"' : '' ?>>My Power</a>
                <a href="/story.php" <?= $currentPage === 'story' ? 'class="active"' : '' ?>>Our Story</a>
                <a href="#" onclick="handleVolunteerClick(); return false;" <?= $currentPage === 'volunteer' ? 'class="active"' : '' ?>>Volunteer</a>
            </div>
        </div>
    </nav>
    
    <script>
    var TPB_USER_TRUST_LEVEL = <?= $userTrustLevel ?>;
    function handleVolunteerClick() {
        if (TPB_USER_TRUST_LEVEL === 0 || TPB_USER_TRUST_LEVEL === 1) {
            alert('To volunteer, you\'ll need to set up your Two-Factor Authentication profile first (email and phone verification).');
            window.location.href = '/profile.php';
        } else if (TPB_USER_TRUST_LEVEL === 2 || TPB_USER_TRUST_LEVEL === 3) {
            window.location.href = '/volunteer/apply.php';
        } else {
            window.location.href = '/volunteer/';
        }
    }
    </script>
