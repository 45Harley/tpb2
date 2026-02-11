<?php
/**
 * TPB Shared Navigation (Collapsible)
 * ====================================
 * Include after header.php
 * 
 * Required variables:
 *   $currentPage - string: 'help', 'profile', 'voice', 'government', 'town', 'action', 'power', 'volunteer', 'story', 'login', 'home'
 * 
 * Optional variables (for status display):
 *   $userId - int, user's numeric ID (e.g., 1, 10)
 *   $trustLevel - string, e.g., 'Verified (2FA)'
 *   $points - int, civic points
 *   $userTrustLevel - int, 0-4 for routing (if not set, defaults to 0)
 *   $isLoggedIn - bool, whether user is logged in (if not set, checks for visitor)
 *   $userEmail - string, user's email address
 *   $userTownName - string, e.g., 'Putnam' (display name)
 *   $userTownSlug - string, e.g., 'putnam' (for URL)
 *   $userStateAbbr - string, e.g., 'ct' (lowercase, for URL)
 *   $userStateDisplay - string, e.g., 'CT' (uppercase, for display)
 * 
 * For secondary nav (town/state pages), set $secondaryNav array to add a third row:
 *   $secondaryNav = [
 *     ['label' => 'Overview', 'anchor' => 'overview'],      // anchor link (#overview)
 *     ['label' => 'TA Reports', 'url' => 'ta-reports.html'], // full URL link
 *     ...
 *   ];
 * 
 * When $secondaryNav is set:
 *   - Toggle button (▲/▼) appears next to brand
 *   - Row 2 (main nav) can collapse to make room for secondary nav
 *   - Collapse state saved in localStorage
 */

$currentPage = isset($currentPage) ? $currentPage : '';
$userId = isset($userId) ? (int)$userId : 0;
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

// Secondary nav (optional - set by town/state pages)
// Can be set as $secondaryNav or legacy $townNav
// Optional $secondaryNavBrand for labeling (e.g., "Putnam")
$secondaryNav = isset($secondaryNav) ? $secondaryNav : (isset($townNav) ? $townNav : []);
$secondaryNavBrand = isset($secondaryNavBrand) ? $secondaryNavBrand : '';
$hasSecondaryNav = !empty($secondaryNav);

// Truncate email after @+5 chars if needed
$emailDisplay = $userEmail;
if ($hasEmail) {
    $atPos = strpos($userEmail, '@');
    if ($atPos !== false && strlen($userEmail) > $atPos + 6) {
        $emailDisplay = substr($userEmail, 0, $atPos + 6) . '...';
    }
}

// Election site base URL
$electionSite = 'https://tpb.sandgems.net';
?>
    <!-- Navigation (Collapsible) -->
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
        max-height: 50px;
        overflow: visible;
        transition: max-height 0.3s ease, padding 0.3s ease;
    }
    .nav-row-2.collapsed {
        max-height: 0;
        padding-top: 0;
        padding-bottom: 0;
        overflow: hidden;
    }
    .nav-row-3 {
        padding: 0.2rem 1rem;
        border-top: 1px solid rgba(212, 175, 55, 0.15);
        background: rgba(0, 0, 0, 0.2);
    }
    .nav-brand-group {
        display: flex;
        align-items: center;
        gap: 0.5rem;
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
    .nav-toggle {
        background: none;
        border: 1px solid #555;
        color: #e0e0e0;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        cursor: pointer;
        font-size: 1rem;
        line-height: 1;
        transition: all 0.2s;
    }
    .nav-toggle:hover {
        background: rgba(212, 175, 55, 0.2);
        border-color: #d4af37;
    }
    .nav-toggle .icon-expanded,
    .nav-toggle.collapsed .icon-collapsed { display: inline; }
    .nav-toggle .icon-collapsed,
    .nav-toggle.collapsed .icon-expanded { display: none; }
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
    .nav-status .user-id {
        color: #888;
        font-family: monospace;
        font-size: 0.9em;
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
        align-items: center;
    }
    .nav-links > a {
        color: #e0e0e0;
        text-decoration: none;
        padding: 0.4rem 0.7rem;
        border-radius: 4px;
        font-size: 0.9rem;
        transition: all 0.2s;
    }
    .nav-links > a:hover {
        background: rgba(212, 175, 55, 0.2);
        color: #fff;
    }
    .nav-links > a.active {
        background: rgba(212, 175, 55, 0.3);
        color: #d4af37;
    }
    .nav-links a.add-link {
        color: #f39c12;
        font-style: italic;
    }
    
    /* Secondary nav (row 3) */
    .secondary-nav-links {
        display: flex;
        gap: 0.3rem;
        flex-wrap: wrap;
        justify-content: center;
        width: 100%;
        align-items: center;
    }
    .secondary-nav-brand {
        color: #d4af37;
        font-weight: 600;
        font-size: 0.85rem;
        margin-right: 0.5rem;
        padding-right: 0.75rem;
        border-right: 1px solid rgba(212, 175, 55, 0.3);
    }
    .secondary-nav-links > a {
        color: #aaa;
        text-decoration: none;
        padding: 0.3rem 0.6rem;
        border-radius: 4px;
        font-size: 0.8rem;
        transition: all 0.2s;
    }
    .secondary-nav-links > a:hover {
        background: rgba(212, 175, 55, 0.15);
        color: #e0e0e0;
    }
    .secondary-nav-links > a.active {
        background: rgba(212, 175, 55, 0.25);
        color: #d4af37;
    }
    
    /* Dropdown styles */
    .nav-dropdown {
        position: relative;
        display: inline-block;
    }
    .nav-dropdown-toggle {
        color: #e0e0e0;
        text-decoration: none;
        padding: 0.4rem 0.7rem;
        border-radius: 4px;
        font-size: 0.9rem;
        transition: all 0.2s;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        background: none;
        border: none;
    }
    .nav-dropdown-toggle:hover,
    .nav-dropdown:hover .nav-dropdown-toggle {
        background: rgba(212, 175, 55, 0.2);
        color: #fff;
    }
    .nav-dropdown-toggle.active {
        background: rgba(212, 175, 55, 0.3);
        color: #d4af37;
    }
    .nav-dropdown-toggle::after {
        content: '▾';
        font-size: 0.7rem;
        opacity: 0.7;
    }
    .nav-dropdown-menu {
        display: none;
        position: absolute;
        top: 100%;
        left: 0;
        background: #1a1a2e;
        border: 1px solid #d4af37;
        border-radius: 4px;
        min-width: 180px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        z-index: 1001;
        padding: 0.3rem 0;
    }
    .nav-dropdown:hover .nav-dropdown-menu {
        display: block;
    }
    .nav-dropdown-menu a {
        display: block;
        color: #e0e0e0;
        text-decoration: none;
        padding: 0.5rem 1rem;
        font-size: 0.85rem;
        transition: all 0.2s;
    }
    .nav-dropdown-menu a:hover {
        background: rgba(212, 175, 55, 0.2);
        color: #fff;
    }
    .nav-dropdown-menu a.external::after {
        content: ' ↗';
        font-size: 0.7rem;
        opacity: 0.6;
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
        .nav-links > a, .nav-dropdown-toggle { padding: 0.3rem 0.5rem; font-size: 0.8rem; }
        .nav-brand { font-size: 1.2rem; }
        .nav-dropdown-menu { min-width: 160px; }
        .nav-dropdown-menu a { padding: 0.4rem 0.8rem; font-size: 0.8rem; }
        .secondary-nav-links > a { padding: 0.25rem 0.4rem; font-size: 0.75rem; }
    }
    </style>
    
    <nav class="top-nav">
        <!-- Row 1: Brand + Toggle (if secondary nav) + Status -->
        <div class="nav-row nav-row-1">
            <div class="nav-brand-group">
                <a href="/" class="nav-brand">🏛️ TPB</a>
                <?php if ($hasSecondaryNav): ?>
                <button class="nav-toggle" id="navToggle" title="Toggle main menu">
                    <span class="icon-expanded">▲</span>
                    <span class="icon-collapsed">▼</span>
                </button>
                <?php endif; ?>
            </div>
            <div class="nav-status">
                <?php if ($isLoggedIn): ?>
                    <span class="user-id"><?= $userId ?></span>
                    <span class="divider">|</span>
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
                    <div class="nav-dropdown">
                        <span class="nav-dropdown-toggle login-link">Login</span>
                        <div class="nav-dropdown-menu">
                            <a href="/join.php">New User</a>
                            <a href="/login.php">Existing User</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Row 2: Main Navigation Links -->
        <div class="nav-row nav-row-2">
            <div class="nav-links">
                <div class="nav-dropdown">
                    <span class="nav-dropdown-toggle <?= $currentPage === 'help' ? 'active' : '' ?>">Help</span>
                    <div class="nav-dropdown-menu">
                        <a href="/help/tpb-getting-started-tutorial.html">🎓 Getting Started</a>
                        <a href="/help/icons.php">🎨 Modal Icons</a>
                    </div>
                </div>
                <?php if ($isLoggedIn): ?>
                <a href="/profile.php" <?= $currentPage === 'profile' ? 'class="active"' : '' ?>>Me</a>
                <?php if ($hasTown): ?>
                <a href="/z-states/<?= htmlspecialchars($userStateAbbr) ?>/<?= htmlspecialchars($userTownSlug) ?>/" <?= $currentPage === 'town' ? 'class="active"' : '' ?>>My Town</a>
                <?php else: ?>
                <a href="/profile.php#town" class="add-link">Add Town</a>
                <?php endif; ?>
                <a href="/voice.php" <?= $currentPage === 'voice' ? 'class="active"' : '' ?>>My Voice</a>
                
                <!-- My Government Dropdown -->
                <div class="nav-dropdown">
                    <span class="nav-dropdown-toggle <?= $currentPage === 'government' ? 'active' : '' ?>">My Government</span>
                    <div class="nav-dropdown-menu">
                        <a href="/aspirations.php">Our Aspirations</a>
                        <a href="/reps.php?my=1">My Reps</a>
                        <a href="/constitution/">The Constitution</a>
                    </div>
                </div>
                
                <?php else: ?>
                <div class="nav-dropdown">
                    <span class="nav-dropdown-toggle <?= $currentPage === 'login' ? 'active' : '' ?>">Login</span>
                    <div class="nav-dropdown-menu">
                        <a href="/join.php">New User</a>
                        <a href="/login.php">Existing User</a>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Action Dropdown -->
                <div class="nav-dropdown">
                    <span class="nav-dropdown-toggle <?= $currentPage === 'action' ? 'active' : '' ?>">Action</span>
                    <div class="nav-dropdown-menu">
                        <a href="<?= $electionSite ?>/" class="external">Election 2026</a>
                        <a href="/28/">Amendment 28</a>
                        <a href="/poll/">My Opinion</a>
                        <a href="<?= $electionSite ?>/threats.php" class="external">Threats</a>
                        <a href="<?= $electionSite ?>/mobsters.php" class="external">Gangs &amp; Mobsters</a>
                        <a href="<?= $electionSite ?>/the-fight.php" class="external">The Fight</a>
                        <a href="<?= $electionSite ?>/war.php" class="external">The War</a>
                    </div>
                </div>
                
                <a href="/0t/" <?= $currentPage === 'power' ? 'class="active"' : '' ?>>My Power</a>
                <a href="/story.php" <?= $currentPage === 'story' ? 'class="active"' : '' ?>>Our Story</a>
                <a href="#" onclick="handleVolunteerClick(); return false;" <?= $currentPage === 'volunteer' ? 'class="active"' : '' ?>>Volunteer</a>
            </div>
        </div>
        
        <?php if ($hasSecondaryNav): ?>
        <!-- Row 3: Secondary Navigation (town/state anchors) -->
        <div class="nav-row nav-row-3">
            <div class="secondary-nav-links">
                <?php if ($secondaryNavBrand): ?>
                <span class="secondary-nav-brand"><?= htmlspecialchars($secondaryNavBrand) ?> ›</span>
                <?php endif; ?>
                <?php foreach ($secondaryNav as $item): ?>
                <?php if (isset($item['url'])): ?>
                <a href="<?= htmlspecialchars($item['url']) ?>"<?= isset($item['target']) ? ' target="' . htmlspecialchars($item['target']) . '"' : '' ?>><?= htmlspecialchars($item['label']) ?></a>
                <?php else: ?>
                <a href="#<?= htmlspecialchars($item['anchor']) ?>"><?= htmlspecialchars($item['label']) ?></a>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
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
    
    // Toggle main nav (row 2) - only if secondary nav exists
    <?php if ($hasSecondaryNav): ?>
    (function() {
        var toggle = document.getElementById('navToggle');
        var row2 = document.querySelector('.nav-row-2');
        var storageKey = 'tpb_nav_collapsed';
        
        if (!toggle || !row2) return;
        
        // Check saved state
        if (localStorage.getItem(storageKey) === 'true') {
            row2.classList.add('collapsed');
            toggle.classList.add('collapsed');
        }
        
        toggle.addEventListener('click', function() {
            var isCollapsed = row2.classList.toggle('collapsed');
            toggle.classList.toggle('collapsed', isCollapsed);
            localStorage.setItem(storageKey, isCollapsed);
        });
    })();
    <?php endif; ?>
    </script>
