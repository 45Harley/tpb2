<?php
/**
 * The People's Branch - Join / Verify
 * ====================================
 * One email = one identity = any device
 * Continue with Email — one-time verify per device
 */

// Database connection
$config = [
    'host' => 'localhost',
    'database' => 'sandge5_tpb2',
    'username' => 'sandge5_tpb2',
    'password' => '.YeO6kSJAHh5',
    'charset' => 'utf8mb4'
];

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

// AJAX handler for town autocomplete
if (isset($_GET['ajax']) && $_GET['ajax'] === 'towns') {
    header('Content-Type: application/json');
    $query = $_GET['q'] ?? '';
    $stateId = $_GET['state_id'] ?? null;
    
    if (strlen($query) < 1) {
        echo json_encode([]);
        exit;
    }
    
    $sql = "SELECT t.town_id, t.town_name, s.abbreviation as state_abbrev 
            FROM towns t 
            JOIN states s ON t.state_id = s.state_id 
            WHERE t.town_name LIKE ?";
    $params = [$query . '%'];
    
    if ($stateId) {
        $sql .= " AND t.state_id = ?";
        $params[] = $stateId;
    }
    
    $sql .= " ORDER BY t.town_name LIMIT 10";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $towns = $stmt->fetchAll();
    
    echo json_encode($towns);
    exit;
}

// Get all states from database for JavaScript
$statesResult = $pdo->query("SELECT state_id, abbreviation, state_name FROM states ORDER BY state_name");
$states = $statesResult->fetchAll();

// Check if already verified on this device
require_once __DIR__ . '/includes/get-user.php';
$dbUser = getUser($pdo);
$sessionId = $_COOKIE['tpb_civic_session'] ?? null;
$alreadyVerified = false;
$userName = '';

if ($sessionId) {
    // Check user_devices table for this session
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.first_name, COALESCE(uis.email_verified, 0) as email_verified
        FROM user_devices ud
        INNER JOIN users u ON ud.user_id = u.user_id
        LEFT JOIN user_identity_status uis ON u.user_id = uis.user_id
        WHERE ud.device_session = ? AND ud.is_active = 1
    ");
    $stmt->execute([$sessionId]);
    $user = $stmt->fetch();
    
    if ($user && $user['email_verified']) {
        $alreadyVerified = true;
        $userName = $user['first_name'];
    }
}

// Check for verification callback
$justVerified = isset($_GET['verified']) && $_GET['verified'] === 'success';
$deviceAdded = isset($_GET['verified']) && $_GET['verified'] === 'device_added';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Join | The People's Branch</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Georgia', serif;
            background: #0a0a0a;
            color: #e0e0e0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            width: 100%;
            max-width: 400px;
            text-align: center;
        }
        
        .logo {
            font-size: 3em;
            margin-bottom: 10px;
        }
        
        h1 {
            color: #d4af37;
            font-size: 1.6em;
            margin-bottom: 8px;
        }
        
        .tagline {
            color: #888;
            margin-bottom: 30px;
            font-style: italic;
        }
        
        .card {
            background: #1a1a1a;
            border: 1px solid #333;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }
        
        label {
            display: block;
            color: #888;
            margin-bottom: 6px;
            font-size: 0.9em;
        }
        
        input {
            width: 100%;
            padding: 14px;
            background: #0a0a0a;
            border: 1px solid #333;
            border-radius: 8px;
            color: #e0e0e0;
            font-size: 1em;
        }
        
        input:focus {
            outline: none;
            border-color: #d4af37;
        }
        
        /* Dark blue button - readable */
        button {
            width: 100%;
            background: #1a3a5c;
            color: #ffffff;
            border: none;
            padding: 14px;
            font-size: 1.1em;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.2s;
        }
        
        button:hover {
            background: #2a4a6c;
        }
        
        button:disabled {
            background: #333;
            color: #666;
            cursor: not-allowed;
        }
        
        .btn-subtext {
            display: block;
            font-size: 0.75em;
            font-weight: normal;
            margin-top: 4px;
            opacity: 0.9;
        }
        
        .success-card {
            background: #1a2a1a;
            border: 1px solid #4caf50;
        }
        
        .success-card h2 {
            color: #4caf50;
            margin-bottom: 15px;
        }
        
        .verified-card {
            background: #1a2a1a;
            border: 1px solid #4caf50;
        }
        
        .verified-card h2 {
            color: #4caf50;
            margin-bottom: 15px;
        }
        
        .links {
            margin-top: 20px;
        }
        
        .links a {
            display: block;
            color: #d4af37;
            text-decoration: none;
            padding: 12px;
            margin: 8px 0;
            border: 1px solid #333;
            border-radius: 8px;
            transition: all 0.2s;
        }
        
        .links a:hover {
            border-color: #d4af37;
            background: rgba(212, 175, 55, 0.1);
        }
        
        .sent-msg {
            display: none;
            color: #4caf50;
            margin-top: 15px;
        }
        
        .info-text {
            color: #888;
            font-size: 0.85em;
            margin-top: 15px;
            line-height: 1.4;
        }
        
        .footer {
            margin-top: 30px;
            color: #555;
            font-size: 0.85em;
        }
        
        .footer a {
            color: #888;
        }
        
        /* Location Confirm Modal */
        .location-confirm-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.85);
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .location-confirm-modal {
            background: #1a1a2a;
            border: 2px solid #d4af37;
            border-radius: 12px;
            padding: 30px;
            max-width: 400px;
            width: 100%;
            text-align: center;
        }
        
        .location-confirm-modal h3 {
            color: #d4af37;
            margin-bottom: 15px;
        }
        
        .location-confirm-modal .detected-location {
            font-size: 1.3em;
            color: #fff;
            margin: 20px 0;
            padding: 15px;
            background: #0a0a0a;
            border-radius: 8px;
        }
        
        .location-confirm-modal .btn-group {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }
        
        .location-confirm-modal button {
            flex: 1;
            padding: 12px 20px;
            border-radius: 8px;
            font-size: 1em;
            cursor: pointer;
            border: none;
        }
        
        .location-confirm-modal .btn-yes {
            background: #4caf50;
            color: white;
        }
        
        .location-confirm-modal .btn-no {
            background: #333;
            color: #e0e0e0;
            border: 1px solid #555;
        }
        
        .location-confirm-modal .note {
            color: #888;
            font-size: 0.85em;
            margin-top: 15px;
        }
        
        /* Town Autocomplete */
        .town-autocomplete-container {
            position: relative;
            margin-top: 20px;
            text-align: left;
        }
        
        .town-autocomplete-container label {
            display: block;
            color: #888;
            margin-bottom: 8px;
            font-size: 0.9em;
        }
        
        .town-autocomplete-container input {
            width: 100%;
            padding: 12px 15px;
            font-size: 1.1em;
            background: #0a0a0a;
            border: 1px solid #333;
            border-radius: 8px;
            color: #e0e0e0;
        }
        
        .town-autocomplete-container input:focus {
            outline: none;
            border-color: #d4af37;
        }
        
        .town-autocomplete-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #1a1a2a;
            border: 1px solid #333;
            border-radius: 0 0 8px 8px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 100;
            display: none;
        }
        
        .town-autocomplete-results.show {
            display: block;
        }
        
        .town-autocomplete-results div {
            padding: 12px 15px;
            cursor: pointer;
            border-bottom: 1px solid #222;
        }
        
        .town-autocomplete-results div:hover {
            background: #2a2a3a;
        }
        
        .town-autocomplete-results div:last-child {
            border-bottom: none;
        }
        
        .town-autocomplete-results .town-name {
            color: #e0e0e0;
        }
        
        .town-autocomplete-results .state-abbrev {
            color: #888;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">🏛️</div>
        <h1>The People's Branch</h1>
        <h2>Create New Account</h2>
        <p class="tagline">Your voice matters</p>
        <p style="margin-top: 0.5rem;"><a href="/login.php" style="color: #7eb8da;">Already have an account? Login to Existing Account</a></p>
        
        <?php if ($justVerified): ?>
        <!-- Just verified - prompt for location -->
        <div class="card success-card" id="verifiedCard">
            <h2>✓ Verified!</h2>
            <p>You're in. One more step to see your representatives:</p>
            
            <div id="locationStep" style="margin-top: 20px;">
                <button type="button" id="geolocateBtn" style="width: 100%;">
                    📍 Enable Location
                    <span class="btn-subtext">Find your exact reps</span>
                </button>
                <p style="color: #666; font-size: 0.8em; margin-top: 10px;">
                    Or <a href="index.php" style="color: #d4af37;">skip for now →</a>
                </p>
            </div>
            
            <div id="locationSuccess" style="display: none; margin-top: 20px;">
                <p style="color: #4caf50;">✓ Location set!</p>
                <p id="locationDisplay" style="color: #888; font-size: 0.9em;"></p>
            </div>
        </div>
        <div class="links" id="verifiedLinks" style="display: none;">
            <a href="reps.php?my=1">👤 My Representatives</a>
            <a href="thought.php">📝 Share a thought</a>
            <a href="index.php">🏛️ Full platform</a>
        </div>
        
        <?php elseif ($deviceAdded): ?>
        <!-- Device added - returning user -->
        <div class="card success-card">
            <h2>✓ Device Added!</h2>
            <p>This device is now linked to your account.</p>
        </div>
        <div class="links">
            <a href="thought.php">📝 Share a thought</a>
            <a href="read.php">📖 Read what others think</a>
            <a href="index.php">🏛️ Full platform</a>
        </div>
        
        <?php elseif ($alreadyVerified): ?>
        <!-- Already verified on this device -->
        <div class="card verified-card">
            <h2>Welcome back<?= $userName ? ', ' . htmlspecialchars($userName) : '' ?>!</h2>
            <p>You're verified on this device.</p>
        </div>
        <div class="links">
            <a href="thought.php">📝 Share a thought</a>
            <a href="read.php">📖 Read what others think</a>
            <a href="index.php">🏛️ Full platform</a>
        </div>
        
        <?php else: ?>
        <!-- Join/verify form -->
        <!-- 
            DUAL ONBOARDING PATHS (keep in sync with index.php):
            - join.php: Dedicated page, linkable from external sources, town pages
            - index.php: Inline form for users already browsing
            Both call send-magic-link.php which handles NEW and RETURNING users.
            Returning user = email exists in DB = links this device to existing account.
        -->
        <div class="card">
            <form id="joinForm">
                <!-- Bot detection - honeypot and timestamp -->
                <div style="position:absolute;left:-9999px;"><input type="text" name="website_url" id="website_url" tabindex="-1" autocomplete="off"></div>
                <input type="hidden" name="_form_load_time" id="_form_load_time" value="">
                
                <!-- Location from map (shown if passed via URL) -->
                <div id="mapLocationInfo" style="display:none; background: #1a2a1a; border: 1px solid #3a7a3a; border-radius: 8px; padding: 12px 15px; margin-bottom: 15px; text-align: center;">
                    <p style="color: #6ee7b7; margin: 0; font-size: 0.95em;">📍 <span class="map-loc-text"></span></p>
                    <p style="color: #888; margin: 4px 0 0; font-size: 0.75em;">Location selected from map — will be saved with your account</p>
                </div>
                
                <div class="form-group">
                    <label>Email *</label>
                    <input type="email" id="email" placeholder="your@email.com" required>
                </div>
                <div style="display: flex; gap: 10px;">
                    <div class="form-group" style="flex: 1;">
                        <label>First name</label>
                        <input type="text" id="firstName" placeholder="First">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label>Last name</label>
                        <input type="text" id="lastName" placeholder="Last">
                    </div>
                </div>
                <div class="form-group">
                    <label>Phone</label>
                    <input type="tel" id="phone" placeholder="(555) 123-4567">
                    <p style="font-size: 0.75em; color: #666; margin-top: 4px;">You can verify your phone later for extra trust points</p>
                </div>
                <div class="form-group">
                    <label>Age range</label>
                    <select id="ageBracket" style="width: 100%; padding: 12px; background: #1a1a2e; border: 1px solid #333; border-radius: 8px; color: #e0e0e0; font-size: 1em;">
                        <option value="">Select...</option>
                        <option value="under13">Under 13</option>
                        <option value="13-17">13 - 17</option>
                        <option value="18-24">18 - 24</option>
                        <option value="25-44">25 - 44</option>
                        <option value="45-64">45 - 64</option>
                        <option value="65+">65+</option>
                    </select>
                </div>
                
                <!-- Too Young Message -->
                <div id="tooYoungMsg" style="display: none; background: #2a2a4a; border: 1px solid #d4af37; border-radius: 8px; padding: 15px; margin-bottom: 15px; text-align: center;">
                    <p style="color: #d4af37; margin: 0 0 8px; font-size: 1.1em;">🌱 Thanks for your interest!</p>
                    <p style="color: #aaa; margin: 0; font-size: 0.9em;">TPB is for ages 13+. Talk to your parents about civic engagement — democracy will need you when you're older!</p>
                </div>
                
                <!-- Parent Consent Fields (13-17) -->
                <div id="parentFields" style="display: none; background: #1a2a3a; border: 1px solid #3a5a7a; border-radius: 8px; padding: 15px; margin-bottom: 15px;">
                    <p style="color: #7ab8e0; margin: 0 0 10px; font-size: 0.95em;">🎉 Young voices matter! Since you're under 18, we need a parent or guardian to confirm.</p>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label style="color: #7ab8e0;">Parent/Guardian Email *</label>
                        <input type="email" id="parentEmail" placeholder="parent@email.com" style="width: 100%; padding: 12px; background: #0a1a2a; border: 1px solid #3a5a7a; border-radius: 8px; color: #e0e0e0; font-size: 1em;">
                    </div>
                    <p style="color: #888; margin: 10px 0 0; font-size: 0.8em;">We'll send them a quick note explaining TPB and asking them to approve.</p>
                </div>
                
                <p style="font-size: 0.8em; color: #888; margin: 0 0 15px; text-align: center;">Name, phone & age optional — but help us serve you better</p>
                <button type="submit" id="submitBtn">
                    Continue with Email
                    <span class="btn-subtext">one-time verify per device</span>
                </button>
                <p class="sent-msg" id="sentMsg">✓ Check your email for the verification link!</p>
                <p class="info-text">Same email works on all your devices — phone, tablet, PC.</p>
                <p style="font-size: 0.85em; color: #d4af37; margin-top: 12px; text-align: center;">Already verified before? Just enter your email — we'll recognize you and link this device.</p>
            </form>
        </div>
        
        <div class="links">
            <a href="read.php">📖 Read thoughts (no account needed)</a>
            <a href="index.php">🏛️ Full platform</a>
        </div>
        <?php endif; ?>
        
        <div class="footer">
            <a href="index.php">The People's Branch</a> · Your voice, aggregated
        </div>
    </div>
    
    <script>
    const API_BASE = 'https://tpb2.sandgems.net/api';
    
    // States list from database
    const STATES = <?= json_encode($states) ?>;
    
    // Pending geolocation data (before user confirms)
    let pendingGeoData = null;
    
    // Read location from URL params (passed from map)
    const urlParams = new URLSearchParams(window.location.search);
    const mapLocation = {
        state_code: urlParams.get('state_code') || '',
        town_name: urlParams.get('town_name') || '',
        zip_code: urlParams.get('zip_code') || '',
        lat: urlParams.get('lat') || '',
        lng: urlParams.get('lng') || '',
        county: urlParams.get('county') || '',
        us_congress_district: urlParams.get('us_congress_district') || '',
        state_senate_district: urlParams.get('state_senate_district') || '',
        state_house_district: urlParams.get('state_house_district') || ''
    };
    const hasMapLocation = mapLocation.state_code && mapLocation.town_name;
    
    // Show location confirmation if we have map data
    if (hasMapLocation) {
        const locDiv = document.getElementById('mapLocationInfo');
        if (locDiv) {
            locDiv.style.display = 'block';
            locDiv.querySelector('.map-loc-text').textContent = 
                mapLocation.town_name + ', ' + mapLocation.state_code + 
                (mapLocation.zip_code ? ' ' + mapLocation.zip_code : '');
        }
    }
    
    // Ensure session cookie exists
    let sessionId = document.cookie.split('; ').find(row => row.startsWith('tpb_civic_session='))?.split('=')[1];
    if (!sessionId) {
        sessionId = 'civic_' + Math.random().toString(36).substr(2, 9) + '_' + Date.now();
        document.cookie = 'tpb_civic_session=' + sessionId + '; path=/; max-age=31536000';
    }
    
    // Geolocation handler
    document.getElementById('geolocateBtn')?.addEventListener('click', async function() {
        if (!navigator.geolocation) {
            alert('Geolocation not supported by your browser.');
            showLinks();
            return;
        }
        
        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '📍 Detecting...';
        
        navigator.geolocation.getCurrentPosition(
            async function(position) {
                const lat = position.coords.latitude;
                const lon = position.coords.longitude;
                const accuracy = position.coords.accuracy;
                
                try {
                    btn.innerHTML = '📍 Getting location...';
                    
                    // Reverse geocode
                    const geoResponse = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lon}`);
                    const geoData = await geoResponse.json();
                    
                    const town = geoData.address.city || geoData.address.town || geoData.address.village || geoData.address.hamlet || '';
                    const stateName = geoData.address.state || '';
                    const stateObj = STATES.find(s => s.state_name === stateName);
                    const stateCode = stateObj ? stateObj.abbreviation : '';
                    const stateId = stateObj ? stateObj.state_id : null;
                    
                    // Get districts (with timeout)
                    btn.innerHTML = '📍 Finding districts...';
                    let districts = { us_congress_district: null, state_senate_district: null, state_house_district: null };
                    
                    try {
                        const controller = new AbortController();
                        const timeoutId = setTimeout(() => controller.abort(), 5000); // 5 second timeout
                        
                        const districtResponse = await fetch(`${API_BASE}/lookup-districts.php`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ latitude: lat, longitude: lon }),
                            signal: controller.signal
                        });
                        clearTimeout(timeoutId);
                        
                        const districtData = await districtResponse.json();
                        if (districtData.status === 'success' && districtData.districts) {
                            districts = districtData.districts;
                        }
                    } catch (e) {
                        console.log('District lookup failed or timed out:', e);
                    }
                    
                    // Store pending data
                    pendingGeoData = {
                        town: town,
                        state: stateCode,
                        stateId: stateId,
                        stateName: stateName,
                        latitude: lat,
                        longitude: lon,
                        accuracy: accuracy,
                        districts: districts
                    };
                    
                    btn.disabled = false;
                    btn.innerHTML = '📍 Enable Location<span class="btn-subtext">Find your exact reps</span>';
                    
                    // Show confirmation modal
                    showLocationConfirmModal(town, stateCode, accuracy);
                    
                } catch (err) {
                    console.error('Geolocation error:', err);
                    btn.disabled = false;
                    btn.innerHTML = '📍 Enable Location<span class="btn-subtext">Find your exact reps</span>';
                    alert('Could not determine location. You can set it later in the platform.');
                    showLinks();
                }
            },
            function(error) {
                btn.disabled = false;
                btn.innerHTML = '📍 Enable Location<span class="btn-subtext">Find your exact reps</span>';
                if (error.code === error.PERMISSION_DENIED) {
                    // Show manual entry modal instead of just alert
                    const modal = document.createElement('div');
                    modal.className = 'location-confirm-overlay';
                    modal.id = 'locationConfirmModal';
                    modal.innerHTML = `
                        <div class="location-confirm-modal">
                            <h3>📍 Location Access Denied</h3>
                            <p style="color: #aaa; margin-bottom: 15px;">No problem! You can enter your location manually.</p>
                        </div>
                    `;
                    document.body.appendChild(modal);
                    showTownAutocomplete(modal);
                } else {
                    alert('Could not get location. You can try again later.');
                }
                showLinks();
            }
        );
    });
    
    function showLocationConfirmModal(town, stateCode, accuracy) {
        const locationStr = town + (stateCode ? ', ' + stateCode : '');
        const isLowAccuracy = accuracy > 1000;
        
        const modal = document.createElement('div');
        modal.className = 'location-confirm-overlay';
        modal.id = 'locationConfirmModal';
        modal.innerHTML = `
            <div class="location-confirm-modal">
                <h3>📍 Is this your location?</h3>
                <div class="detected-location">${locationStr || 'Unknown location'}</div>
                ${isLowAccuracy ? '<p class="note">Note: Desktop computers use approximate location based on your internet connection.</p>' : ''}
                <div class="btn-group">
                    <button class="btn-yes" onclick="confirmLocation(true)">Yes, that's right</button>
                    <button class="btn-no" onclick="confirmLocation(false)">No, let me change</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }
    
    async function confirmLocation(confirmed) {
        const modal = document.getElementById('locationConfirmModal');
        
        if (confirmed && pendingGeoData) {
            // User confirmed - save the location
            await saveConfirmedLocation(pendingGeoData);
            modal.remove();
            pendingGeoData = null;
        } else {
            // User wants to change - show autocomplete
            showTownAutocomplete(modal);
        }
    }
    
    async function saveConfirmedLocation(geoData) {
        // Save to profile
        await fetch(`${API_BASE}/save-profile.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                session_id: sessionId,
                town: geoData.town,
                state: geoData.state,
                latitude: geoData.latitude,
                longitude: geoData.longitude,
                us_congress_district: geoData.districts.us_congress_district,
                state_senate_district: geoData.districts.state_senate_district,
                state_house_district: geoData.districts.state_house_district
            })
        });
        
        // Show success
        document.getElementById('locationStep').style.display = 'none';
        document.getElementById('locationSuccess').style.display = 'block';
        document.getElementById('locationDisplay').textContent = geoData.town + (geoData.state ? ', ' + geoData.state : '');
        showLinks();
    }
    
    function showTownAutocomplete(modal) {
        const modalContent = modal.querySelector('.location-confirm-modal');
        modalContent.innerHTML = `
            <h3>📍 Set your location</h3>
            <p class="note">Due to gerrymandering, district boundaries can zigzag through neighborhoods. Your neighbor across the street might have different representatives. Exact location matters.</p>
            <div class="town-autocomplete-container">
                <label><strong>Option 1:</strong> Begin typing your town:</label>
                <input type="text" id="townAutocompleteInput" placeholder="e.g. Hartford" autocomplete="off">
                <div class="town-autocomplete-results" id="townAutocompleteResults"></div>
            </div>
            <div style="text-align: center; margin: 15px 0; color: #666;">— or —</div>
            <div class="town-autocomplete-container">
                <label><strong>Option 2:</strong> Paste coordinates from Google Maps:</label>
                <input type="text" id="coordsPasteInput" placeholder="e.g. 41.8968, -71.8676" autocomplete="off">
                <p style="color: #666; font-size: 0.8em; margin-top: 5px;">Right-click your home in Google Maps → copy the numbers</p>
            </div>
            <div class="btn-group" style="margin-top: 20px;">
                <button class="btn-yes" id="useCoordsBtn" style="display:none;">Use These Coordinates</button>
                <button class="btn-no" onclick="cancelLocationChange()">Cancel</button>
            </div>
        `;
        
        const input = document.getElementById('townAutocompleteInput');
        const results = document.getElementById('townAutocompleteResults');
        let debounceTimer;
        
        input.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            const query = this.value.trim();
            
            if (query.length < 2) {
                results.classList.remove('show');
                return;
            }
            
            debounceTimer = setTimeout(async () => {
                try {
                    // Use Nominatim for US town search - increased limit to filter
                    const url = `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&countrycodes=us&limit=20&addressdetails=1`;
                    
                    const response = await fetch(url);
                    const places = await response.json();
                    
                    // Filter out counties - only show actual towns/cities/villages
                    const towns = places.filter(p => {
                        // Skip if it's a county (name contains "County" and no town-level address)
                        const isCounty = p.type === 'administrative' && p.name && p.name.includes('County');
                        // Must have a town-level address component
                        const hasTown = p.address && (p.address.city || p.address.town || p.address.village || p.address.hamlet);
                        // Include if it's a town/city/village type OR has town in address (and not a county)
                        return !isCounty && (hasTown || ['city', 'town', 'village', 'hamlet'].includes(p.type));
                    }).slice(0, 8);  // Limit to 8 after filtering
                    
                    if (towns.length > 0) {
                        results.innerHTML = towns.map(p => {
                            const townName = p.address.city || p.address.town || p.address.village || p.address.hamlet || p.name || '';
                            const stateName = p.address.state || '';
                            const lat = p.lat;
                            const lon = p.lon;
                            return `
                                <div onclick="selectNominatimTown('${townName.replace(/'/g, "\\'")}', '${stateName.replace(/'/g, "\\'")}', ${lat}, ${lon})">
                                    <span class="town-name">${townName}</span>, 
                                    <span class="state-abbrev">${stateName}</span>
                                </div>
                            `;
                        }).join('');
                        results.classList.add('show');
                    } else {
                        results.innerHTML = '<div style="color: #888;">No towns found</div>';
                        results.classList.add('show');
                    }
                } catch (err) {
                    console.error('Town search error:', err);
                }
            }, 300);
        });
        
        // Coordinates paste handler
        const coordsInput = document.getElementById('coordsPasteInput');
        const useCoordsBtn = document.getElementById('useCoordsBtn');
        
        coordsInput.addEventListener('input', function() {
            const value = this.value.trim();
            // Check if it looks like coordinates (two numbers separated by comma)
            const coordMatch = value.match(/^(-?\d+\.?\d*)\s*,\s*(-?\d+\.?\d*)$/);
            if (coordMatch) {
                useCoordsBtn.style.display = 'block';
                useCoordsBtn.onclick = () => useCoordinates(parseFloat(coordMatch[1]), parseFloat(coordMatch[2]));
            } else {
                useCoordsBtn.style.display = 'none';
            }
        });
        
        input.focus();
    }
    
    async function useCoordinates(lat, lon) {
        try {
            // Reverse geocode to get town/state
            const response = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lon}`);
            const data = await response.json();
            
            const town = data.address.city || data.address.town || data.address.village || data.address.hamlet || '';
            const state = data.address.state || '';
            const stateObj = STATES.find(s => s.state_name === state);
            const stateCode = stateObj ? stateObj.abbreviation : '';
            
            // Lookup districts (with timeout)
            let districts = {
                us_congress_district: null,
                state_senate_district: null,
                state_house_district: null
            };
            
            try {
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 5000);
                
                const districtResponse = await fetch(`${API_BASE}/lookup-districts.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ latitude: lat, longitude: lon }),
                    signal: controller.signal
                });
                clearTimeout(timeoutId);
                
                const districtData = await districtResponse.json();
                if (districtData.status === 'success' && districtData.districts) {
                    districts = districtData.districts;
                }
            } catch (e) {
                console.log('District lookup failed or timed out:', e);
            }
            
            // Update pending data with pasted coords
            pendingGeoData = {
                town: town,
                state: stateCode,
                latitude: lat,
                longitude: lon,
                districts: districts
            };
            
            // Save the location
            const modal = document.getElementById('locationConfirmModal');
            modal.remove();
            await saveConfirmedLocation(pendingGeoData);
            pendingGeoData = null;
            
        } catch (err) {
            console.error('Error using coordinates:', err);
            alert('Could not look up that location. Please check the coordinates and try again.');
        }
    }
    
    async function selectNominatimTown(townName, stateName, lat, lon) {
        try {
            // Map state name to abbreviation
            const stateObj = STATES.find(s => s.state_name === stateName);
            const stateAbbrev = stateObj ? stateObj.abbreviation : '';
            
            // Lookup districts (with timeout)
            let districts = {
                us_congress_district: null,
                state_senate_district: null,
                state_house_district: null
            };
            
            if (lat && lon) {
                try {
                    const controller = new AbortController();
                    const timeoutId = setTimeout(() => controller.abort(), 5000);
                    
                    const districtResponse = await fetch(`${API_BASE}/lookup-districts.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ latitude: lat, longitude: lon }),
                        signal: controller.signal
                    });
                    clearTimeout(timeoutId);
                    
                    const districtData = await districtResponse.json();
                    if (districtData.status === 'success' && districtData.districts) {
                        districts = districtData.districts;
                    }
                } catch (e) {
                    console.log('District lookup failed or timed out:', e);
                }
            }
            
            // Set pending data
            pendingGeoData = {
                town: townName,
                state: stateAbbrev,
                latitude: lat,
                longitude: lon,
                districts: districts
            };
            
            // Save and close modal
            const modal = document.getElementById('locationConfirmModal');
            if (modal) modal.remove();
            await saveConfirmedLocation(pendingGeoData);
            pendingGeoData = null;
            
        } catch (err) {
            console.error('Error selecting town:', err);
            alert('Error selecting town. Please try again.');
        }
    }

    async function selectTown(townId, townName, stateAbbrev) {
        // Get coordinates for selected town via Nominatim
        try {
            const searchQuery = `${townName}, ${stateAbbrev}, USA`;
            const response = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(searchQuery)}&limit=1`);
            const data = await response.json();
            
            let lat = null, lon = null;
            if (data && data.length > 0) {
                lat = parseFloat(data[0].lat);
                lon = parseFloat(data[0].lon);
            }
            
            // Lookup districts for new coordinates (with timeout)
            let districts = {
                us_congress_district: null,
                state_senate_district: null,
                state_house_district: null
            };
            
            if (lat && lon) {
                try {
                    const controller = new AbortController();
                    const timeoutId = setTimeout(() => controller.abort(), 5000);
                    
                    const districtResponse = await fetch(`${API_BASE}/lookup-districts.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ latitude: lat, longitude: lon }),
                        signal: controller.signal
                    });
                    clearTimeout(timeoutId);
                    
                    const districtData = await districtResponse.json();
                    if (districtData.status === 'success' && districtData.districts) {
                        districts = districtData.districts;
                    }
                } catch (e) {
                    console.log('District lookup failed or timed out:', e);
                }
            }
            
            // Update pending data with corrected info
            pendingGeoData = {
                town: townName,
                state: stateAbbrev,
                latitude: lat,
                longitude: lon,
                districts: districts
            };
            
            // Save the corrected location
            const modal = document.getElementById('locationConfirmModal');
            modal.remove();
            await saveConfirmedLocation(pendingGeoData);
            pendingGeoData = null;
            
        } catch (err) {
            console.error('Error getting town coordinates:', err);
            alert('Error selecting town. Please try again.');
        }
    }
    
    function cancelLocationChange() {
        const modal = document.getElementById('locationConfirmModal');
        if (modal) modal.remove();
        pendingGeoData = null;
        showLinks();
    }
    
    function showLinks() {
        const links = document.getElementById('verifiedLinks');
        if (links) links.style.display = 'block';
    }
    
    // Set form load timestamp for bot detection
    document.getElementById('_form_load_time').value = Math.floor(Date.now() / 1000);
    
    // Age bracket change handler
    document.getElementById('ageBracket')?.addEventListener('change', function() {
        const tooYoungMsg = document.getElementById('tooYoungMsg');
        const parentFields = document.getElementById('parentFields');
        const submitBtn = document.getElementById('submitBtn');
        
        // Reset
        tooYoungMsg.style.display = 'none';
        parentFields.style.display = 'none';
        submitBtn.disabled = false;
        
        if (this.value === 'under13') {
            // Too young
            tooYoungMsg.style.display = 'block';
            submitBtn.disabled = true;
        } else if (this.value === '13-17') {
            // Minor - need parent consent
            parentFields.style.display = 'block';
        }
    });
    
    document.getElementById('joinForm')?.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const firstName = document.getElementById('firstName').value.trim();
        const lastName = document.getElementById('lastName').value.trim();
        const email = document.getElementById('email').value.trim();
        const phone = document.getElementById('phone').value.trim();
        const ageBracket = document.getElementById('ageBracket').value;
        const parentEmail = document.getElementById('parentEmail')?.value.trim() || '';
        const honeypot = document.getElementById('website_url').value;
        const formLoadTime = document.getElementById('_form_load_time').value;
        
        if (!email) {
            alert('Please enter your email.');
            return;
        }
        
        // If minor (13-17), require parent email
        if (ageBracket === '13-17' && !parentEmail) {
            alert('Please enter your parent/guardian\'s email.');
            document.getElementById('parentEmail').focus();
            return;
        }
        
        const btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.innerHTML = 'Sending...';
        
        try {
            const response = await fetch(`${API_BASE}/send-magic-link.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    email: email,
                    first_name: firstName || null,
                    last_name: lastName || null,
                    phone: phone || null,
                    age_bracket: ageBracket || null,
                    parent_email: parentEmail || null,
                    session_id: sessionId,
                    website_url: honeypot,
                    _form_load_time: parseInt(formLoadTime) || null,
                    state_code: hasMapLocation ? mapLocation.state_code : null,
                    town_name: hasMapLocation ? mapLocation.town_name : null,
                    zip_code: hasMapLocation ? mapLocation.zip_code : null,
                    lat: hasMapLocation ? mapLocation.lat : null,
                    lng: hasMapLocation ? mapLocation.lng : null,
                    us_congress_district: hasMapLocation ? mapLocation.us_congress_district : null,
                    state_senate_district: hasMapLocation ? mapLocation.state_senate_district : null,
                    state_house_district: hasMapLocation ? mapLocation.state_house_district : null
                })
            });
            
            const data = await response.json();
            
            if (data.status === 'success' || data.status === 'warning') {
                btn.style.display = 'none';
                document.getElementById('sentMsg').style.display = 'block';
                
                // Show parent consent message if minor
                if (ageBracket === '13-17') {
                    document.getElementById('sentMsg').innerHTML = '✓ Check your email! We also sent a note to your parent/guardian for approval.';
                }
            } else {
                alert(data.message || 'Failed to send. Please try again.');
                btn.disabled = false;
                btn.innerHTML = 'Continue with Email<span class="btn-subtext">one-time verify per device</span>';
            }
        } catch (err) {
            alert('Error. Please try again.');
            btn.disabled = false;
            btn.innerHTML = 'Continue with Email<span class="btn-subtext">one-time verify per device</span>';
        }
    });
    </script>

</body>
</html>
