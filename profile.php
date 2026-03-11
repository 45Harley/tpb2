<?php
/**
 * The People's Branch - My Profile
 * =================================
 * Your civic identity: who you are, where you are, your journey.
 */

$config = require 'config.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
    );
} catch (PDOException $e) {
    die("Database connection failed");
}

// Load user — uses standard getUser() from get-user.php
require_once __DIR__ . '/includes/get-user.php';
$dbUser = getUser($pdo);

// Session ID still needed for JS
$sessionId = isset($_COOKIE['tpb_civic_session']) ? $_COOKIE['tpb_civic_session'] : null;

// Check for pending zip from map modal
$pendingZip = isset($_COOKIE['tpb_pending_zip']) ? $_COOKIE['tpb_pending_zip'] : null;
$pendingTown = isset($_COOKIE['tpb_pending_town']) ? urldecode($_COOKIE['tpb_pending_town']) : null;
$pendingState = isset($_COOKIE['tpb_pending_state']) ? $_COOKIE['tpb_pending_state'] : null;

// Get states for dropdown
$states = $pdo->query("SELECT state_id, abbreviation, state_name FROM states ORDER BY state_name")->fetchAll();

// Check if minor needing parent consent
$isMinor = $dbUser && ($dbUser['age_bracket'] === '13-17');
$needsParentConsent = $isMinor && !$dbUser['parent_consent'];
$hasParentConsent = $isMinor && $dbUser['parent_consent'];

// Nav variables via helper
// get-user.php already loaded above
$navVars = getNavVarsForUser($dbUser);
extract($navVars);

// Additional profile-specific: nextStep for guidance
$nextStep = 'Set your location';
if ($dbUser) {
    if ($dbUser['phone_verified']) {
        $nextStep = $needsParentConsent ? 'Waiting for parent approval' : 'You\'re fully verified!';
    } elseif ($dbUser['email_verified']) {
        $nextStep = $needsParentConsent ? 'Waiting for parent approval' : 'Add phone for 2FA';
    } elseif ($dbUser['email']) {
        $nextStep = 'Verify your email';
    } elseif ($dbUser['town_name']) {
        $nextStep = 'Add your email';
    }
}

// Volunteer data
$volunteerStatus = null;
$volunteerApp = null;
$userSkills = [];
$allSkillSets = [];
$userBio = '';

if ($dbUser) {
    // Get volunteer application status
    $stmt = $pdo->prepare("
        SELECT * FROM volunteer_applications 
        WHERE user_id = ? 
        ORDER BY applied_at DESC LIMIT 1
    ");
    $stmt->execute([$dbUser['user_id']]);
    $volunteerApp = $stmt->fetch();
    $volunteerStatus = $volunteerApp ? $volunteerApp['status'] : null;
    
    // Get user's skills with primary flag
    $stmt = $pdo->prepare("
        SELECT usp.skill_set_id, usp.is_primary, usp.status as skill_status, ss.set_name, ss.icon
        FROM user_skill_progression usp
        JOIN skill_sets ss ON usp.skill_set_id = ss.skill_set_id
        WHERE usp.user_id = ?
    ");
    $stmt->execute([$dbUser['user_id']]);
    $userSkills = $stmt->fetchAll();
    
    // Get user bio
    $stmt = $pdo->prepare("SELECT bio FROM users WHERE user_id = ?");
    $stmt->execute([$dbUser['user_id']]);
    $bioRow = $stmt->fetch();
    $userBio = $bioRow ? ($bioRow['bio'] ?? '') : '';
}

// Get all skill sets for checkboxes
$allSkillSets = $pdo->query("SELECT skill_set_id, set_name, icon, description FROM skill_sets ORDER BY set_name")->fetchAll();

// Check if user is an approved volunteer
$isVolunteer = ($volunteerStatus === 'accepted');

// Page config for includes
$pageTitle = 'My Profile - The People\'s Branch';
$currentPage = 'profile';

// Page-specific styles
$pageStyles = '
/* Trust Journey */
.journey-steps {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}
.journey-step {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 0.75rem;
    background: #0a0a0f;
    border-radius: 20px;
    font-size: 0.9rem;
}
.journey-step.completed {
    background: #1a3a1a;
    color: #4caf50;
}
.journey-step.current {
    background: #3a3a1a;
    color: #d4af37;
    border: 1px solid #d4af37;
}
.journey-step .icon { font-size: 1rem; }
.journey-step .pts { color: #888; font-size: 0.8rem; }

/* Location display */
.location-display {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
    background: #0a0a0f;
    border-radius: 8px;
}
.location-display .place {
    flex: 1;
}
.location-display .address {
    font-size: 1.1rem;
    font-weight: 500;
}
.location-display .town {
    font-size: 1.1rem;
    font-weight: 500;
}
.location-display .districts {
    color: #888;
    font-size: 0.85rem;
    margin-top: 0.25rem;
}

/* Volunteer Section */
.volunteer-status {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1rem;
    background: #0a0a0f;
    border-radius: 8px;
    margin-bottom: 1rem;
}
.volunteer-status.approved {
    background: #1a3a1a;
    border: 1px solid #2ecc71;
}
.volunteer-status.pending {
    background: #3a3a1a;
    border: 1px solid #f39c12;
}
.volunteer-status .badge {
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: 600;
}
.volunteer-status .badge.approved {
    background: #2ecc71;
    color: #000;
}
.volunteer-status .badge.pending {
    background: #f39c12;
    color: #000;
}
.skills-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 0.75rem;
    margin-bottom: 1rem;
}
.skill-checkbox {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 0.75rem;
    background: #0a0a0f;
    border: 1px solid #333;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
}
.skill-checkbox:hover {
    border-color: #d4af37;
}
.skill-checkbox.selected {
    background: #1a2a1a;
    border-color: #2ecc71;
}
.skill-checkbox.primary {
    background: #2a2a1a;
    border-color: #d4af37;
    box-shadow: 0 0 8px rgba(212, 175, 55, 0.3);
}
.skill-checkbox input[type="checkbox"] {
    accent-color: #2ecc71;
}
.skill-checkbox .icon {
    font-size: 1.1rem;
}
.skill-checkbox .name {
    flex: 1;
    font-size: 0.9rem;
}
.primary-selector {
    margin-top: 1rem;
    padding: 1rem;
    background: #0a0a0f;
    border-radius: 8px;
}
.primary-selector label {
    color: #d4af37;
    font-weight: 600;
    margin-bottom: 0.5rem;
    display: block;
}
.primary-selector select {
    width: 100%;
    padding: 0.75rem;
    background: #1a1a1f;
    border: 1px solid #333;
    border-radius: 6px;
    color: #e0e0e0;
    font-size: 1rem;
}
.bio-textarea {
    width: 100%;
    min-height: 100px;
    padding: 0.75rem;
    background: #1a1a1f;
    border: 1px solid #333;
    border-radius: 8px;
    color: #e0e0e0;
    font-family: inherit;
    font-size: 1rem;
    resize: vertical;
}
.bio-textarea:focus {
    outline: none;
    border-color: #d4af37;
}
.volunteer-link {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.25rem;
    background: #d4af37;
    color: #000 !important;
    text-decoration: none;
    border-radius: 8px;
    font-weight: 600;
    margin-top: 1rem;
}
.volunteer-link:hover {
    background: #e5c048;
}

/* Password Section */
.password-section {
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid #333;
}
.password-section h3 {
    color: #d4af37;
    font-size: 1rem;
    margin-bottom: 1rem;
}
.password-input-group {
    margin-bottom: 1rem;
}
.password-input-group label {
    display: block;
    color: #888;
    font-size: 0.85rem;
    margin-bottom: 0.25rem;
}
.password-input-group input {
    width: 100%;
    padding: 0.75rem;
    background: #0d0d0d;
    border: 1px solid #333;
    border-radius: 8px;
    color: #e0e0e0;
    font-size: 1rem;
}
.password-input-group input:focus {
    outline: none;
    border-color: #d4af37;
}
.password-requirements {
    font-size: 0.8rem;
    color: #666;
    margin-top: 0.25rem;
}
.password-status {
    margin-top: 0.5rem;
}
.password-status .success {
    color: #2ecc71;
}
.password-status .error {
    color: #e74c3c;
}
.password-set-badge {
    display: inline-block;
    padding: 4px 10px;
    background: #1a3a2a;
    color: #2ecc71;
    border-radius: 12px;
    font-size: 0.8rem;
    margin-left: 0.5rem;
}
.lockout-warning {
    background: #3a2a1a;
    border: 1px solid #f39c12;
    color: #f39c12;
    padding: 0.75rem;
    border-radius: 8px;
    margin-bottom: 1rem;
    font-size: 0.9rem;
}
';

// Calculate user trust level for nav
$userTrustLevel = 0;
if ($dbUser) {
    if ($isVolunteer) {
        $userTrustLevel = 4; // Approved volunteer
    } elseif ($volunteerStatus === 'pending') {
        $userTrustLevel = 3; // Application pending
    } elseif ($dbUser['phone_verified']) {
        $userTrustLevel = 2; // 2FA verified
    } elseif ($dbUser['email_verified']) {
        $userTrustLevel = 1; // Email verified
    }
}

// Nav variables for email and town
$userEmail = $dbUser ? ($dbUser['email'] ?? '') : '';
$userTownName = $dbUser ? ($dbUser['town_name'] ?? '') : '';
$userTownSlug = $userTownName ? strtolower(str_replace(' ', '-', $userTownName)) : '';
$userStateAbbr = $dbUser ? strtolower($dbUser['state_abbrev'] ?? '') : '';
$userStateDisplay = $dbUser ? ($dbUser['state_abbrev'] ?? '') : '';
$isLoggedIn = (bool)$dbUser;

// Include header
require 'includes/header.php';

// Include nav
require 'includes/nav.php';
?>
    
    <main class="main narrow">
        <h1>My Profile</h1>
        <p class="subtitle">Your civic identity</p>
        
        <!-- Trust Journey -->
        <div class="card">
            <h2>📈 Your Journey</h2>
            <div class="journey-steps">
                <div class="journey-step completed">
                    <span class="icon">👋</span>
                    <span>Arrived</span>
                    <span class="pts">+1</span>
                </div>
                <div class="journey-step <?= ($dbUser && $dbUser['town_name']) ? 'completed' : 'current' ?>" id="step-location">
                    <span class="icon">📍</span>
                    <span>Location</span>
                    <span class="pts">+50</span>
                </div>
                <div class="journey-step <?= ($dbUser && $dbUser['first_name']) ? 'completed' : '' ?>" id="step-name">
                    <span class="icon">👤</span>
                    <span>Name</span>
                    <span class="pts">+50</span>
                </div>
                <div class="journey-step <?= ($dbUser && $dbUser['email_verified']) ? 'completed' : '' ?>" id="step-email">
                    <span class="icon">✉️</span>
                    <span>Email</span>
                    <span class="pts">+50</span>
                </div>
                <?php if ($isMinor): ?>
                <div class="journey-step <?= $hasParentConsent ? 'completed' : 'current' ?>" id="step-parent">
                    <span class="icon">👨‍👩‍👧</span>
                    <span>Parent OK</span>
                    <span class="pts">required</span>
                </div>
                <?php endif; ?>
                <div class="journey-step <?= ($dbUser && $dbUser['phone_verified']) ? 'completed' : '' ?>" id="step-phone">
                    <span class="icon">📱</span>
                    <span>2FA</span>
                    <span class="pts">+50</span>
                </div>
            </div>
            
            <!-- Civic Points -->
            <div style="text-align: center; padding: 1rem 0; border-top: 1px solid #333; margin-top: 1rem;">
                <div style="font-size: 2rem; font-weight: bold; color: #d4af37;"><?= $points ?></div>
                <div style="color: #888; font-size: 0.9rem;">civic points</div>
            </div>
        </div>
        
        <!-- Location -->
        <div class="card" id="town">
            <h2>📍 Location</h2>
            <?php if ($dbUser && $dbUser['town_name']): ?>
                <div class="location-display">
                    <div class="place">
                        <div class="address-edit-row" style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem;">
                            <div id="streetAddressDisplay" style="flex: 1;">
                                <?php if ($dbUser['street_address']): ?>
                                    <div class="address"><?= htmlspecialchars($dbUser['street_address']) ?></div>
                                <?php else: ?>
                                    <div class="address" style="color: #666; font-style: italic;">No street address</div>
                                <?php endif; ?>
                            </div>
                            <button class="btn btn-secondary" id="editStreetBtn" style="padding: 0.25rem 0.6rem; font-size: 0.8rem;">Edit</button>
                        </div>
                        <div id="streetAddressEdit" style="display: none; margin-bottom: 0.5rem;">
                            <div style="display: flex; gap: 0.5rem;">
                                <input type="text" id="streetAddressInput" 
                                       value="<?= htmlspecialchars($dbUser['street_address'] ?? '') ?>"
                                       placeholder="Enter street address"
                                       style="flex: 1; padding: 0.5rem; font-size: 0.9rem; background: #0a0a1a; border: 1px solid #333; color: #e0e0e0; border-radius: 6px;">
                                <button class="btn btn-primary" id="saveStreetBtn" style="padding: 0.5rem 0.8rem; font-size: 0.85rem; background: #d4af37; color: #000; border: none; border-radius: 6px; cursor: pointer;">Save</button>
                                <button class="btn btn-secondary" id="cancelStreetBtn" style="padding: 0.5rem 0.8rem; font-size: 0.85rem; border-radius: 6px; cursor: pointer;">Cancel</button>
                            </div>
                            <div id="streetSaveStatus" style="margin-top: 0.3rem; font-size: 0.85rem;"></div>
                        </div>
                        <div class="town"><?= htmlspecialchars($dbUser['town_name']) ?>, <?= htmlspecialchars($dbUser['state_abbrev']) ?><?= $dbUser['zip_code'] ? ' ' . htmlspecialchars($dbUser['zip_code']) : '' ?></div>
                        <div class="districts">
                            <?php 
                            $districts = array();
                            if ($dbUser['us_congress_district']) $districts[] = $dbUser['us_congress_district'];
                            if ($dbUser['state_senate_district']) $districts[] = 'Senate ' . $dbUser['state_senate_district'];
                            if ($dbUser['state_house_district']) $districts[] = 'House ' . $dbUser['state_house_district'];
                            echo $districts ? htmlspecialchars(implode(' • ', $districts)) : 'Districts not set';
                            ?>
                        </div>
                    </div>
                    <button class="btn btn-secondary" id="changeLocationBtn">Change</button>
                </div>
                <?php 
                // Build town page link with fallback
                $townSlug = strtolower(str_replace(' ', '-', $dbUser['town_name']));
                $stateSlug = strtolower($dbUser['state_abbrev']);
                $townPagePath = $_SERVER['DOCUMENT_ROOT'] . "/z-states/{$stateSlug}/{$townSlug}/index.php";
                $statePagePath = $_SERVER['DOCUMENT_ROOT'] . "/z-states/{$stateSlug}/index.php";
                
                // Check what exists: town page > state page > home
                if (file_exists($townPagePath)) {
                    $targetUrl = "/z-states/{$stateSlug}/{$townSlug}/";
                    $buttonText = "🏠 Go to My Town";
                } elseif (file_exists($statePagePath)) {
                    $targetUrl = "/z-states/{$stateSlug}/";
                    $buttonText = "🏛️ Go to My State";
                } else {
                    $targetUrl = "/";
                    $buttonText = "🏠 Go Home";
                }
                ?>
                <div style="margin-top: 1rem; text-align: center;">
                    <?php if (!file_exists($townPagePath) && $dbUser['town_name']): ?>
                        <p style="color: #888; font-size: 0.9em; margin-bottom: 0.5rem;">
                            <?= htmlspecialchars($dbUser['town_name']) ?> page coming soon!
                        </p>
                    <?php endif; ?>
                    <a href="<?= htmlspecialchars($targetUrl) ?>" class="btn btn-primary" style="background: #d4af37; color: #000;">
                        <?= $buttonText ?>
                    </a>
                </div>
            <?php else: ?>
                <p style="color: #888; margin-bottom: 1rem;">Set your location to see your representatives and local thoughts.</p>
                <button class="btn btn-primary" id="setLocationBtn">📍 Set My Location</button>
            <?php endif; ?>
        </div>
        
        <!-- Identity -->
        <div class="card">
            <h2>👤 Identity</h2>
            <form id="identityForm">
                <!-- Bot detection fields -->
                <div style="position:absolute;left:-9999px;"><input type="text" name="website_url" id="website_url" tabindex="-1" autocomplete="off"></div>
                <input type="hidden" name="_form_load_time" id="_form_load_time" value="<?= time() ?>">
                
                <div class="form-row" style="margin-bottom: 1rem;">
                    <div class="form-group" style="flex: 1;">
                        <label>First Name <?= ($dbUser && $dbUser['first_name']) ? '<span class="check">✓</span>' : '' ?></label>
                        <input type="text" id="firstName" value="<?= htmlspecialchars($dbUser['first_name'] ?? '') ?>" placeholder="First name">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label>Last Name <?= ($dbUser && $dbUser['last_name']) ? '<span class="check">✓</span>' : '' ?></label>
                        <input type="text" id="lastName" value="<?= htmlspecialchars($dbUser['last_name'] ?? '') ?>" placeholder="Last name">
                    </div>
                </div>
                <div class="form-group">
                    <label>Age Range</label>
                    <select id="ageBracket">
                        <option value="">Select age range...</option>
                        <option value="13-17" <?= ($dbUser && $dbUser['age_bracket'] == '13-17') ? 'selected' : '' ?>>13-17</option>
                        <option value="18-24" <?= ($dbUser && $dbUser['age_bracket'] == '18-24') ? 'selected' : '' ?>>18-24</option>
                        <option value="25-34" <?= ($dbUser && $dbUser['age_bracket'] == '25-34') ? 'selected' : '' ?>>25-34</option>
                        <option value="35-44" <?= ($dbUser && $dbUser['age_bracket'] == '35-44') ? 'selected' : '' ?>>35-44</option>
                        <option value="45-54" <?= ($dbUser && $dbUser['age_bracket'] == '45-54') ? 'selected' : '' ?>>45-54</option>
                        <option value="55-64" <?= ($dbUser && $dbUser['age_bracket'] == '55-64') ? 'selected' : '' ?>>55-64</option>
                        <option value="65+" <?= ($dbUser && $dbUser['age_bracket'] == '65+') ? 'selected' : '' ?>>65+</option>
                    </select>
                </div>
                
                <!-- Privacy / Display Settings -->
                <div class="form-group" style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #333;">
                    <label style="color: #d4af37;">🔒 Privacy Settings</label>
                    <p style="color: #666; font-size: 0.85rem; margin-bottom: 0.75rem;">Choose what others see when you post</p>
                    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                        <label class="checkbox-label">
                            <input type="checkbox" id="showFirstName" <?= ($dbUser && ($dbUser['show_first_name'] ?? 1)) ? 'checked' : '' ?>>
                            <span>Show first name</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" id="showLastName" <?= ($dbUser && $dbUser['show_last_name']) ? 'checked' : '' ?>>
                            <span>Show last name</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" id="showAgeBracket" <?= ($dbUser && $dbUser['show_age_bracket']) ? 'checked' : '' ?>>
                            <span>Show age bracket</span>
                        </label>
                    </div>
                    <p id="displayPreview" style="color: #888; font-size: 0.85rem; margin-top: 0.75rem; font-style: italic;">
                        Preview: <?php
                            $preview = array();
                            if ($dbUser) {
                                if (($dbUser['show_first_name'] ?? 1) && $dbUser['first_name']) $preview[] = $dbUser['first_name'];
                                if ($dbUser['show_last_name'] && $dbUser['last_name']) $preview[] = $dbUser['last_name'];
                                if ($dbUser['show_age_bracket'] && $dbUser['age_bracket']) $preview[] = '(' . $dbUser['age_bracket'] . ')';
                            }
                            echo $preview ? htmlspecialchars(implode(' ', $preview)) : 'Anonymous';
                        ?>
                    </p>
                </div>

                <!-- Notification Preferences -->
                <div class="form-group" style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #333;">
                    <label style="color: #d4af37;">Notifications</label>
                    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                        <label class="checkbox-label">
                            <input type="checkbox" id="notifyThreatBulletin" <?= ($dbUser && !empty($dbUser['notify_threat_bulletin'])) ? 'checked' : '' ?> <?= (!$dbUser || ($dbUser['identity_level_id'] ?? 0) < 2) ? 'disabled' : '' ?>>
                            <span>Daily threat bulletin email (8 AM ET)</span>
                        </label>
                        <?php if (!$dbUser || ($dbUser['identity_level_id'] ?? 0) < 2): ?>
                        <p style="color: #666; font-size: 0.8rem; font-style: italic;">Verify your email to enable threat alerts</p>
                        <?php endif; ?>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">Save Changes</button>
                <div id="identityStatus"></div>
            </form>
        </div>

        <!-- Verification -->
        <div class="card" id="email">
            <h2>✓ Verification</h2>
            
            <!-- Email -->
            <div class="form-group">
                <label>Email <?= ($dbUser && $dbUser['email_verified']) ? '<span class="check">✓ Verified</span>' : '' ?></label>
                <div class="form-row">
                    <input type="email" id="emailInput" value="<?= htmlspecialchars($dbUser['email'] ?? '') ?>" 
                           placeholder="your@email.com" style="flex: 1;"
                           <?= ($dbUser && $dbUser['email_verified']) ? 'disabled' : '' ?>>
                    <?php if ($dbUser && $dbUser['email_verified']): ?>
                        <button type="button" class="btn btn-secondary" id="changeEmailBtn">Change</button>
                    <?php else: ?>
                        <button type="button" class="btn btn-primary" id="verifyEmailBtn">Verify</button>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Phone -->
            <div class="form-group">
                <label>Phone 2FA <?= ($dbUser && $dbUser['phone_verified']) ? '<span class="check">✓ Verified</span>' : '' ?></label>
                <div class="form-row">
                    <input type="tel" id="phone" value="<?= htmlspecialchars($dbUser['phone'] ?? '') ?>" 
                           placeholder="(555) 123-4567" style="flex: 1;"
                           <?= ($dbUser && $dbUser['phone_verified']) ? 'disabled' : '' ?>>
                    <?php if ($dbUser && $dbUser['phone_verified']): ?>
                        <button type="button" class="btn btn-secondary" id="changePhoneBtn">Change</button>
                    <?php elseif ($dbUser && $dbUser['email_verified']): ?>
                        <button type="button" class="btn btn-primary" id="verifyPhoneBtn">Verify</button>
                    <?php else: ?>
                        <button type="button" class="btn btn-secondary" disabled title="Verify email first">Verify</button>
                    <?php endif; ?>
                </div>
                <?php if (!$dbUser || !$dbUser['email_verified']): ?>
                    <p style="color: #666; font-size: 0.85rem; margin-top: 0.5rem;">Verify your email first to enable phone 2FA</p>
                <?php endif; ?>
            </div>
            
            <div id="verifyStatus"></div>
            
            <!-- Password Section (show after email verified) -->
            <?php if ($dbUser && $dbUser['email_verified']): ?>
            <div class="password-section" id="password">
                <h3>🔐 Password <?= !empty($dbUser['password_hash']) ? '<span class="password-set-badge">✓ Set</span>' : '' ?></h3>
                
                <div id="passwordLockout" class="lockout-warning" style="display: none;"></div>
                
                <?php if (!empty($dbUser['password_hash'])): ?>
                <!-- Change Password -->
                <div class="password-input-group">
                    <label>Current Password</label>
                    <input type="password" id="currentPassword" placeholder="Enter current password">
                </div>
                <?php endif; ?>
                
                <div class="password-input-group">
                    <label><?= !empty($dbUser['password_hash']) ? 'New Password' : 'Create Password' ?></label>
                    <input type="password" id="newPassword" placeholder="<?= !empty($dbUser['password_hash']) ? 'Enter new password' : 'Create a password' ?>">
                    <p class="password-requirements">At least 8 characters</p>
                </div>
                
                <div class="password-input-group">
                    <label>Confirm Password</label>
                    <input type="password" id="confirmPassword" placeholder="Confirm password">
                </div>
                
                <button type="button" class="btn btn-primary" id="savePasswordBtn" style="color:#000!important">
                    <?= !empty($dbUser['password_hash']) ? 'Change Password' : 'Set Password' ?>
                </button>
                
                <div id="passwordStatus" class="password-status"></div>
                
                <?php if (empty($dbUser['password_hash'])): ?>
                <p style="color: #888; font-size: 0.85rem; margin-top: 1rem;">
                    Setting a password lets you log in from any browser or device.
                </p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Volunteer Section (only show if 2FA verified) -->
        <?php if ($dbUser && $dbUser['phone_verified']): ?>
        <div class="card">
            <h2>🔨 Volunteer</h2>
            
            <!-- Volunteer Status -->
            <?php if ($volunteerStatus === 'accepted'): ?>
            <div class="volunteer-status approved">
                <span class="badge approved">✓ Approved Volunteer</span>
                <span>You're part of the team!</span>
            </div>
            <?php elseif ($volunteerStatus === 'pending'): ?>
            <div class="volunteer-status pending">
                <span class="badge pending">⏳ Application Pending</span>
                <span>We're reviewing your application</span>
            </div>
            <?php else: ?>
            <div class="volunteer-status">
                <span>Want to help build TPB?</span>
                <a href="/volunteer/apply.php" class="btn btn-primary" style="margin-left: auto;">Apply to Volunteer</a>
            </div>
            <?php endif; ?>
            
            <?php if ($isVolunteer): ?>
            <!-- Skills Selection -->
            <div class="form-group">
                <label style="color: #d4af37; margin-bottom: 0.75rem; display: block;">🛠️ My Skills</label>
                <p style="color: #888; font-size: 0.85rem; margin-bottom: 1rem;">Select the areas where you can contribute</p>
                <div class="skills-grid" id="skillsGrid">
                    <?php 
                    $userSkillIds = array_column($userSkills, 'skill_set_id');
                    $primarySkillId = null;
                    foreach ($userSkills as $us) {
                        if ($us['is_primary']) $primarySkillId = $us['skill_set_id'];
                    }
                    foreach ($allSkillSets as $skill): 
                        $isSelected = in_array($skill['skill_set_id'], $userSkillIds);
                        $isPrimary = ($skill['skill_set_id'] == $primarySkillId);
                    ?>
                    <label class="skill-checkbox <?= $isSelected ? 'selected' : '' ?> <?= $isPrimary ? 'primary' : '' ?>">
                        <input type="checkbox" name="skills[]" value="<?= $skill['skill_set_id'] ?>" <?= $isSelected ? 'checked' : '' ?>>
                        <span class="icon"><?= htmlspecialchars($skill['icon']) ?></span>
                        <span class="name"><?= htmlspecialchars($skill['set_name']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Primary Skill -->
            <div class="primary-selector">
                <label>⭐ Primary Skill</label>
                <p style="color: #888; font-size: 0.85rem; margin-bottom: 0.5rem;">Your main area of focus</p>
                <select id="primarySkill">
                    <option value="">Select your primary skill...</option>
                    <?php foreach ($allSkillSets as $skill): ?>
                    <option value="<?= $skill['skill_set_id'] ?>" <?= ($skill['skill_set_id'] == $primarySkillId) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($skill['icon'] . ' ' . $skill['set_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Bio -->
            <div class="form-group" style="margin-top: 1.5rem;">
                <label style="color: #d4af37;">📝 About Me</label>
                <p style="color: #888; font-size: 0.85rem; margin-bottom: 0.5rem;">Tell other volunteers about yourself</p>
                <textarea class="bio-textarea" id="volunteerBio" placeholder="What drives you to help build TPB? What's your background?"><?= htmlspecialchars($userBio) ?></textarea>
            </div>
            
            <button type="button" class="btn btn-primary" id="saveVolunteerBtn">Save Volunteer Info</button>
            <div id="volunteerStatus" style="margin-top: 0.5rem;"></div>
            
            <!-- Link to workspace -->
            <a href="/volunteer/" class="volunteer-link">
                📋 Go to Volunteer Workspace →
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </main>
    
<?php require 'includes/footer.php'; ?>
    
    <!-- Define API_BASE before loading location module -->
    <script>const API_BASE = 'api';</script>
    
    <!-- Location Module -->
    <script src="assets/location-module.js"></script>
    
    <script>
    (function() {
        'use strict';
        
        const STATES = <?= json_encode($states) ?>;
        
        // Session
        let sessionId = document.cookie.split('; ').find(row => row.startsWith('tpb_civic_session='));
        sessionId = sessionId ? sessionId.split('=')[1] : null;
        if (!sessionId) {
            sessionId = 'civic_' + Math.random().toString(36).substr(2, 9) + '_' + Date.now();
            document.cookie = 'tpb_civic_session=' + sessionId + '; path=/; max-age=31536000';
        }
        
        // Check for pending zip from map modal (will pre-fill if user opens location modal)
        const pendingZip = <?= json_encode($pendingZip) ?>;
        const pendingTown = <?= json_encode($pendingTown) ?>;
        const pendingState = <?= json_encode($pendingState) ?>;
        
        // Location buttons
        const setLocationBtn = document.getElementById('setLocationBtn');
        const changeLocationBtn = document.getElementById('changeLocationBtn');
        
        if (setLocationBtn) {
            setLocationBtn.addEventListener('click', openLocationModal);
        }
        if (changeLocationBtn) {
            changeLocationBtn.addEventListener('click', openLocationModal);
        }
        
        // Street address edit
        const editStreetBtn = document.getElementById('editStreetBtn');
        const saveStreetBtn = document.getElementById('saveStreetBtn');
        const cancelStreetBtn = document.getElementById('cancelStreetBtn');
        
        if (editStreetBtn) {
            editStreetBtn.addEventListener('click', function() {
                document.getElementById('streetAddressDisplay').style.display = 'none';
                editStreetBtn.style.display = 'none';
                document.getElementById('streetAddressEdit').style.display = 'block';
                document.getElementById('streetAddressInput').focus();
            });
        }
        if (cancelStreetBtn) {
            cancelStreetBtn.addEventListener('click', function() {
                document.getElementById('streetAddressDisplay').style.display = '';
                editStreetBtn.style.display = '';
                document.getElementById('streetAddressEdit').style.display = 'none';
                document.getElementById('streetSaveStatus').innerHTML = '';
            });
        }
        if (saveStreetBtn) {
            saveStreetBtn.addEventListener('click', async function() {
                const input = document.getElementById('streetAddressInput');
                const status = document.getElementById('streetSaveStatus');
                saveStreetBtn.disabled = true;
                saveStreetBtn.textContent = 'Saving...';
                status.innerHTML = '';
                
                try {
                    const response = await fetch(API_BASE + '/save-profile.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'include',
                        body: JSON.stringify({ street_address: input.value.trim() })
                    });
                    const result = await response.json();
                    
                    if (result.status === 'success') {
                        status.innerHTML = '<span style="color: #4caf50;">✅ Saved!</span>';
                        setTimeout(function() { window.location.reload(); }, 800);
                    } else {
                        status.innerHTML = '<span style="color: #e63946;">' + (result.message || 'Error') + '</span>';
                    }
                } catch (err) {
                    status.innerHTML = '<span style="color: #e63946;">Save failed</span>';
                }
                saveStreetBtn.disabled = false;
                saveStreetBtn.textContent = 'Save';
            });
        }
        
        function openLocationModal() {
            // Only use pending zip if user has no location yet
            const hasLocation = <?= ($dbUser && $dbUser['town_name']) ? 'true' : 'false' ?>;
            const prefill = (!hasLocation && pendingZip) ? pendingZip : null;
            
            // Pass current location for replacement warning
            const currentLocation = hasLocation ? {
                town: <?= json_encode($dbUser['town_name'] ?? '') ?>,
                state: <?= json_encode($dbUser['state_abbrev'] ?? '') ?>
            } : null;
            
            TPBLocation.showZipEntryModal({
                prefillZip: prefill,
                currentLocation: currentLocation,
                onSaved: function(locationData) {
                    // Clear pending cookies
                    document.cookie = 'tpb_pending_zip=; path=/; max-age=0';
                    document.cookie = 'tpb_pending_town=; path=/; max-age=0';
                    document.cookie = 'tpb_pending_state=; path=/; max-age=0';
                    window.location.reload();
                },
                onSkip: function() {}
            });
        }
        
        // Identity form
        document.getElementById('identityForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const btn = this.querySelector('button[type="submit"]');
            const status = document.getElementById('identityStatus');
            btn.disabled = true;
            btn.textContent = 'Saving...';
            
            try {
                const response = await fetch(API_BASE + '/save-profile.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        first_name: document.getElementById('firstName').value.trim(),
                        last_name: document.getElementById('lastName').value.trim(),
                        age_bracket: document.getElementById('ageBracket').value,
                        // Privacy settings
                        show_first_name: document.getElementById('showFirstName').checked ? 1 : 0,
                        show_last_name: document.getElementById('showLastName').checked ? 1 : 0,
                        show_age_bracket: document.getElementById('showAgeBracket').checked ? 1 : 0,
                        // Notification preferences
                        notify_threat_bulletin: document.getElementById('notifyThreatBulletin').checked ? 1 : 0,
                        // Bot detection
                        website_url: document.getElementById('website_url').value,
                        _form_load_time: document.getElementById('_form_load_time').value
                    })
                });
                
                const result = await response.json();
                
                if (result.status === 'success') {
                    status.innerHTML = '<div class="status-msg success">Saved!</div>';
                    setTimeout(function() { window.location.reload(); }, 1000);
                } else {
                    status.innerHTML = '<div class="status-msg error">' + (result.message || 'Error saving') + '</div>';
                }
            } catch (err) {
                status.innerHTML = '<div class="status-msg error">Error saving changes</div>';
            }
            
            btn.disabled = false;
            btn.textContent = 'Save Changes';
        });
        
        // Email verification
        const verifyEmailBtn = document.getElementById('verifyEmailBtn');
        if (verifyEmailBtn) {
            verifyEmailBtn.addEventListener('click', async function() {
                const email = document.getElementById('emailInput').value.trim();
                if (!email || !email.includes('@')) {
                    alert('Please enter a valid email address');
                    return;
                }
                
                this.disabled = true;
                this.textContent = 'Sending...';
                
                try {
                    const response = await fetch(API_BASE + '/send-magic-link.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            email: email,
                            session_id: sessionId
                        })
                    });
                    
                    const result = await response.json();
                    
                    if (result.status === 'success' || result.status === 'warning') {
                        document.getElementById('verifyStatus').innerHTML = 
                            '<div class="status-msg success">Check your email for the verification link!</div>';
                    } else {
                        document.getElementById('verifyStatus').innerHTML = 
                            '<div class="status-msg error">' + (result.message || 'Error sending link') + '</div>';
                    }
                } catch (err) {
                    document.getElementById('verifyStatus').innerHTML = 
                        '<div class="status-msg error">Error sending verification link</div>';
                }
                
                this.disabled = false;
                this.textContent = 'Verify';
            });
        }
        
        // Change email button
        const changeEmailBtn = document.getElementById('changeEmailBtn');
        if (changeEmailBtn) {
            changeEmailBtn.addEventListener('click', function() {
                const input = document.getElementById('emailInput');
                input.disabled = false;
                input.focus();
                this.textContent = 'Save';
                this.onclick = async function() {
                    const newEmail = input.value.trim();
                    if (!newEmail || !newEmail.includes('@')) {
                        alert('Please enter a valid email address');
                        return;
                    }
                    
                    try {
                        await fetch(API_BASE + '/save-profile.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ email: newEmail })
                        });
                        window.location.reload();
                    } catch (err) {
                        alert('Error saving email');
                    }
                };
            });
        }
        
        // Phone verification
        const verifyPhoneBtn = document.getElementById('verifyPhoneBtn');
        if (verifyPhoneBtn) {
            verifyPhoneBtn.addEventListener('click', async function() {
                const phone = document.getElementById('phone').value.trim();
                if (!phone || phone.length < 10) {
                    alert('Please enter a valid phone number');
                    return;
                }
                
                this.disabled = true;
                this.textContent = 'Sending...';
                
                try {
                    const response = await fetch(API_BASE + '/send-phone-verify-link.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            phone: phone,
                            session_id: sessionId
                        })
                    });
                    
                    const result = await response.json();
                    
                    if (result.status === 'success') {
                        document.getElementById('verifyStatus').innerHTML = 
                            '<div class="status-msg success">Check your phone for the verification link!</div>';
                    } else {
                        document.getElementById('verifyStatus').innerHTML = 
                            '<div class="status-msg error">' + (result.message || 'Error sending link') + '</div>';
                    }
                } catch (err) {
                    document.getElementById('verifyStatus').innerHTML = 
                        '<div class="status-msg error">Error sending verification link</div>';
                }
                
                this.disabled = false;
                this.textContent = 'Verify';
            });
        }
        
        // Display preview update
        function updateDisplayPreview() {
            const firstName = document.getElementById('firstName').value.trim();
            const lastName = document.getElementById('lastName').value.trim();
            const ageBracket = document.getElementById('ageBracket').value;
            const showFirst = document.getElementById('showFirstName').checked;
            const showLast = document.getElementById('showLastName').checked;
            const showAge = document.getElementById('showAgeBracket').checked;
            
            const parts = [];
            if (showFirst && firstName) parts.push(firstName);
            if (showLast && lastName) parts.push(lastName);
            if (showAge && ageBracket) parts.push('(' + ageBracket + ')');
            
            document.getElementById('displayPreview').textContent = 
                'Preview: ' + (parts.length ? parts.join(' ') : 'Anonymous');
        }
        
        // Attach preview update listeners
        document.getElementById('showFirstName').addEventListener('change', updateDisplayPreview);
        document.getElementById('showLastName').addEventListener('change', updateDisplayPreview);
        document.getElementById('showAgeBracket').addEventListener('change', updateDisplayPreview);
        document.getElementById('firstName').addEventListener('input', updateDisplayPreview);
        document.getElementById('lastName').addEventListener('input', updateDisplayPreview);
        document.getElementById('ageBracket').addEventListener('change', updateDisplayPreview);
        
        // Volunteer section handlers
        const skillsGrid = document.getElementById('skillsGrid');
        const primarySkillSelect = document.getElementById('primarySkill');
        const saveVolunteerBtn = document.getElementById('saveVolunteerBtn');
        
        if (skillsGrid) {
            // Toggle skill checkbox visual
            skillsGrid.querySelectorAll('.skill-checkbox input').forEach(cb => {
                cb.addEventListener('change', function() {
                    const label = this.closest('.skill-checkbox');
                    if (this.checked) {
                        label.classList.add('selected');
                    } else {
                        label.classList.remove('selected');
                        label.classList.remove('primary');
                        // If this was primary, clear primary select
                        if (primarySkillSelect && primarySkillSelect.value === this.value) {
                            primarySkillSelect.value = '';
                        }
                    }
                });
            });
        }
        
        if (primarySkillSelect) {
            primarySkillSelect.addEventListener('change', function() {
                // Update primary visual
                skillsGrid.querySelectorAll('.skill-checkbox').forEach(cb => {
                    cb.classList.remove('primary');
                });
                if (this.value) {
                    const checkbox = skillsGrid.querySelector(`input[value="${this.value}"]`);
                    if (checkbox) {
                        checkbox.checked = true;
                        checkbox.closest('.skill-checkbox').classList.add('selected', 'primary');
                    }
                }
            });
        }
        
        if (saveVolunteerBtn) {
            saveVolunteerBtn.addEventListener('click', async function() {
                const statusDiv = document.getElementById('volunteerStatus');
                
                // Gather selected skills
                const selectedSkills = [];
                skillsGrid.querySelectorAll('input[type="checkbox"]:checked').forEach(cb => {
                    selectedSkills.push(parseInt(cb.value));
                });
                
                const primarySkill = primarySkillSelect ? parseInt(primarySkillSelect.value) || null : null;
                const bio = document.getElementById('volunteerBio')?.value.trim() || '';
                
                this.disabled = true;
                this.textContent = 'Saving...';
                statusDiv.innerHTML = '';
                
                try {
                    const response = await fetch(API_BASE + '/save-volunteer-profile.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            skills: selectedSkills,
                            primary_skill: primarySkill,
                            bio: bio
                        })
                    });
                    
                    const result = await response.json();
                    
                    if (result.status === 'success') {
                        statusDiv.innerHTML = '<div class="status-msg success">Volunteer info saved!</div>';
                    } else {
                        statusDiv.innerHTML = '<div class="status-msg error">' + (result.message || 'Error saving') + '</div>';
                    }
                } catch (err) {
                    statusDiv.innerHTML = '<div class="status-msg error">Error saving volunteer info</div>';
                }
                
                this.disabled = false;
                this.textContent = 'Save Volunteer Info';
            });
        }
        
        // Password section handler
        const savePasswordBtn = document.getElementById('savePasswordBtn');
        if (savePasswordBtn) {
            savePasswordBtn.addEventListener('click', async function() {
                const currentPassword = document.getElementById('currentPassword')?.value || '';
                const newPassword = document.getElementById('newPassword').value;
                const confirmPassword = document.getElementById('confirmPassword').value;
                const statusDiv = document.getElementById('passwordStatus');
                const lockoutDiv = document.getElementById('passwordLockout');
                
                // Validation
                if (newPassword.length < 8) {
                    statusDiv.innerHTML = '<div class="error">Password must be at least 8 characters</div>';
                    return;
                }
                
                if (newPassword !== confirmPassword) {
                    statusDiv.innerHTML = '<div class="error">Passwords do not match</div>';
                    return;
                }
                
                this.disabled = true;
                this.textContent = 'Saving...';
                statusDiv.innerHTML = '';
                lockoutDiv.style.display = 'none';
                
                try {
                    const response = await fetch('/api/change-password.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            current_password: currentPassword,
                            new_password: newPassword
                        })
                    });
                    
                    const result = await response.json();
                    
                    if (result.status === 'success') {
                        statusDiv.innerHTML = '<div class="success">✓ Password ' + (result.action === 'set' ? 'set' : 'changed') + ' successfully!</div>';
                        // Clear inputs
                        if (document.getElementById('currentPassword')) {
                            document.getElementById('currentPassword').value = '';
                        }
                        document.getElementById('newPassword').value = '';
                        document.getElementById('confirmPassword').value = '';
                        
                        // If password was just set, reload to show "Change Password" UI
                        if (result.action === 'set') {
                            setTimeout(() => location.reload(), 1500);
                        }
                    } else if (result.status === 'locked') {
                        lockoutDiv.innerHTML = '⚠️ Too many attempts. Please wait ' + result.minutes_remaining + ' minutes.';
                        lockoutDiv.style.display = 'block';
                        statusDiv.innerHTML = '';
                    } else {
                        statusDiv.innerHTML = '<div class="error">' + (result.message || 'Error saving password') + '</div>';
                    }
                } catch (err) {
                    statusDiv.innerHTML = '<div class="error">Error saving password</div>';
                }
                
                this.disabled = false;
                this.textContent = document.getElementById('currentPassword') ? 'Change Password' : 'Set Password';
            });
        }
        
    })();
    </script>
