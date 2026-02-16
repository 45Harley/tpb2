<?php
/**
 * TPB Map Page — Standalone Interactive Map
 * ==========================================
 * Separate from home page. Uses Google Maps (dark mode).
 * 
 * Modes:
 *   ?mode=onboarding  — New visitor: state → town → pin → save location
 *   ?mode=mymap       — Logged-in user: see/update your pin, manage places
 *   ?mode=directory   — (FUTURE) Browse businesses on map
 *   ?mode=info        — (FUTURE) Civic data overlays
 * 
 * Default: onboarding if not logged in, mymap if logged in
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
    $pdo = null;
}

// Use centralized auth
require_once __DIR__ . '/includes/get-user.php';
$dbUser = $pdo ? getUser($pdo) : null;
$sessionId = isset($_COOKIE['tpb_civic_session']) ? $_COOKIE['tpb_civic_session'] : null;
$cookieUserId = $dbUser ? (int)$dbUser['user_id'] : 0;

// Determine mode
$mode = isset($_GET['mode']) ? $_GET['mode'] : '';
if (!$mode) {
    $mode = ($dbUser && $dbUser['state_abbrev']) ? 'mymap' : 'onboarding';
}

// Nav variables via helper (get-user.php already loaded above)
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'map';

// User location for JS
$userLat = $dbUser ? ($dbUser['latitude'] ?? '') : '';
$userLng = $dbUser ? ($dbUser['longitude'] ?? '') : '';
$userStateName = $dbUser ? ($dbUser['state_name'] ?? '') : '';

$pageTitle = 'My Map - The People\'s Branch';

// Page-specific styles passed to header
$pageStyles = <<<'CSS'
    /* Map page layout */
    .map-page {
        display: flex;
        flex-direction: column;
        height: calc(100vh - 120px);
        max-width: 100%;
        margin: 0;
        padding: 0;
    }
    .map-toolbar {
        background: #1a1a2e;
        border-bottom: 1px solid #333;
        padding: 0.75rem 1rem;
        display: flex;
        align-items: center;
        gap: 1rem;
        flex-wrap: wrap;
    }
    .map-toolbar h1 {
        font-size: 1.3rem;
        margin: 0;
        white-space: nowrap;
    }
    .map-toolbar .mode-tabs {
        display: flex;
        gap: 0.3rem;
    }
    .mode-tab {
        padding: 0.4rem 0.8rem;
        border-radius: 6px;
        font-size: 0.85rem;
        text-decoration: none;
        color: #888;
        border: 1px solid #333;
        transition: all 0.2s;
    }
    .mode-tab:hover { color: #e0e0e0; border-color: #555; }
    .mode-tab.active { color: #d4af37; border-color: #d4af37; background: rgba(212,175,55,0.1); }

    .map-body {
        display: flex;
        flex: 1;
        min-height: 0;
    }

    /* Sidebar */
    .map-sidebar {
        width: 340px;
        background: #12121f;
        border-right: 1px solid #333;
        overflow-y: auto;
        padding: 1rem;
        flex-shrink: 0;
    }
    .sidebar-section {
        margin-bottom: 1.25rem;
    }
    .sidebar-section h3 {
        color: #d4af37;
        font-size: 0.95rem;
        margin-bottom: 0.5rem;
        display: flex;
        align-items: center;
        gap: 0.4rem;
    }
    .sidebar-section p {
        color: #888;
        font-size: 0.85rem;
        margin-bottom: 0.5rem;
    }

    /* Controls in sidebar */
    .map-select, .map-input {
        width: 100%;
        padding: 0.6rem 0.75rem;
        font-size: 0.95rem;
        background: #0a0a0f;
        border: 1px solid #333;
        border-radius: 6px;
        color: #e0e0e0;
        margin-bottom: 0.5rem;
    }
    .map-select:focus, .map-input:focus {
        outline: none;
        border-color: #d4af37;
    }
    .map-select:disabled, .map-input:disabled {
        color: #555;
    }

    /* Town autocomplete */
    .autocomplete-wrapper {
        position: relative;
    }
    .autocomplete-list {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: #1a1a2e;
        border: 1px solid #d4af37;
        border-top: none;
        border-radius: 0 0 6px 6px;
        max-height: 200px;
        overflow-y: auto;
        z-index: 50;
        display: none;
    }
    .autocomplete-list .item {
        padding: 0.5rem 0.75rem;
        cursor: pointer;
        font-size: 0.9rem;
        border-bottom: 1px solid #2a2a3e;
    }
    .autocomplete-list .item:hover {
        background: rgba(212,175,55,0.15);
        color: #d4af37;
    }

    /* Place card */
    .place-card {
        background: #1a1a2e;
        border: 1px solid #333;
        border-radius: 8px;
        padding: 1rem;
        display: none;
    }
    .place-card.visible { display: block; }
    .place-card h4 {
        color: #d4af37;
        margin-bottom: 0.5rem;
    }
    .place-card .detail-row {
        display: flex;
        justify-content: space-between;
        padding: 0.3rem 0;
        font-size: 0.85rem;
        border-bottom: 1px solid #1a1a2e;
    }
    .place-card .detail-row .label { color: #888; }
    .place-card .detail-row .value { color: #e0e0e0; text-align: right; }

    /* Districts card */
    .districts-card {
        background: #1a2a1a;
        border: 1px solid #2a4a2a;
        border-radius: 8px;
        padding: 0.75rem 1rem;
        margin-top: 0.75rem;
        display: none;
    }
    .districts-card.visible { display: block; }
    .districts-card h4 {
        color: #4caf50;
        font-size: 0.9rem;
        margin-bottom: 0.4rem;
    }
    .districts-card .district-row {
        display: flex;
        justify-content: space-between;
        padding: 0.25rem 0;
        font-size: 0.85rem;
    }
    .districts-card .district-row .label { color: #6a9a6a; }
    .districts-card .district-row .value { color: #e0e0e0; }

    /* Save button */
    .btn-save-location {
        width: 100%;
        padding: 0.75rem;
        margin-top: 0.75rem;
        font-size: 1rem;
        font-weight: 600;
    }

    /* Status messages in sidebar */
    .save-status {
        padding: 0.5rem 0.75rem;
        border-radius: 6px;
        font-size: 0.85rem;
        margin-top: 0.5rem;
        display: none;
    }
    .save-status.success { display: block; background: #1a3a1a; color: #4caf50; }
    .save-status.error { display: block; background: #3a1a1a; color: #e63946; }

    /* Map container */
    #map {
        flex: 1;
        min-height: 400px;
    }

    /* Instructions overlay on map */
    .map-instructions {
        position: absolute;
        bottom: 20px;
        left: 50%;
        transform: translateX(-50%);
        background: rgba(26,26,46,0.9);
        border: 1px solid #d4af37;
        border-radius: 8px;
        padding: 0.5rem 1rem;
        color: #e0e0e0;
        font-size: 0.9rem;
        z-index: 10;
        pointer-events: none;
        transition: opacity 0.3s;
    }

    /* My Map mode - current location banner */
    .current-location-banner {
        background: rgba(212,175,55,0.1);
        border: 1px solid rgba(212,175,55,0.3);
        border-radius: 8px;
        padding: 0.75rem;
        margin-bottom: 1rem;
    }
    .current-location-banner .town-name {
        color: #d4af37;
        font-size: 1.1rem;
        font-weight: 600;
    }
    .current-location-banner .details {
        color: #888;
        font-size: 0.85rem;
        margin-top: 0.25rem;
    }

    @media (max-width: 768px) {
        .map-body { flex-direction: column; }
        .map-sidebar { 
            width: 100%; 
            max-height: 40vh; 
            border-right: none;
            border-bottom: 1px solid #333;
        }
        #map { min-height: 300px; }
        .map-toolbar { padding: 0.5rem; }
        .map-toolbar h1 { font-size: 1.1rem; }
    }
CSS;

include 'includes/header.php';
include 'includes/nav.php';
?>

    <div class="map-page">
        <!-- Toolbar -->
        <div class="map-toolbar">
            <h1>📍 My Map</h1>
            <div class="mode-tabs">
                <a href="?mode=onboarding" class="mode-tab <?= $mode === 'onboarding' ? 'active' : '' ?>">Set Location</a>
                <a href="?mode=mymap" class="mode-tab <?= $mode === 'mymap' ? 'active' : '' ?>">My Map</a>
                <!-- FUTURE: <a href="?mode=directory" class="mode-tab">Businesses</a> -->
                <!-- FUTURE: <a href="?mode=info" class="mode-tab">Civic Data</a> -->
            </div>
        </div>

        <div class="map-body">
            <!-- Sidebar -->
            <div class="map-sidebar">

                <?php if ($mode === 'mymap' && $dbUser && $dbUser['town_name']): ?>
                <!-- ===== MY MAP MODE ===== -->
                <div class="current-location-banner">
                    <div class="town-name"><?= htmlspecialchars($dbUser['town_name']) ?>, <?= htmlspecialchars($dbUser['state_abbrev']) ?></div>
                    <div class="details">
                        <?php if ($dbUser['zip_code']): ?>ZIP: <?= htmlspecialchars($dbUser['zip_code']) ?><?php endif; ?>
                        <?php if ($dbUser['street_address']): ?> · <?= htmlspecialchars($dbUser['street_address']) ?><?php endif; ?>
                    </div>
                    <?php if ($dbUser['us_congress_district']): ?>
                    <div class="details">
                        Congress: <?= htmlspecialchars($dbUser['us_congress_district']) ?>
                        <?php if ($dbUser['state_senate_district']): ?> · Senate: <?= htmlspecialchars($dbUser['state_senate_district']) ?><?php endif; ?>
                        <?php if ($dbUser['state_house_district']): ?> · House: <?= htmlspecialchars($dbUser['state_house_district']) ?><?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="sidebar-section">
                    <h3>📌 Update Location</h3>
                    <p>Click the map to move your pin, or change your town below.</p>
                </div>
                <?php endif; ?>

                <?php if ($mode === 'onboarding' || ($mode === 'mymap' && (!$dbUser || !$dbUser['town_name']))): ?>
                <!-- ===== ONBOARDING / NO LOCATION ===== -->
                <div class="sidebar-section">
                    <h3>🏠 Set Your Location</h3>
                    <p>Tell us where you are so we can connect you with your representatives and community.</p>
                </div>
                <?php endif; ?>

                <!-- State selector (both modes) -->
                <div class="sidebar-section">
                    <h3>1. State</h3>
                    <select id="stateSelect" class="map-select">
                        <option value="">-- Select State --</option>
                    </select>
                </div>

                <!-- Town autocomplete (both modes) -->
                <div class="sidebar-section">
                    <h3>2. Town</h3>
                    <div class="autocomplete-wrapper">
                        <input type="text" id="townInput" class="map-input" placeholder="Start typing town name..." disabled>
                        <div id="townList" class="autocomplete-list"></div>
                    </div>
                </div>

                <!-- Pin / drop instruction -->
                <div class="sidebar-section">
                    <h3>3. Drop Pin</h3>
                    <p id="pinInstruction">Select state and town first, then click the map to place your pin.</p>
                </div>

                <!-- Profile fields (appear after pin drop) -->
                <div id="profileFields" class="sidebar-section" style="display:none;">
                    <h3>4. Your Info <span style="color:#888;font-size:0.8rem;font-weight:normal;">(optional)</span></h3>
                    <input type="text" id="pfAddress" class="map-input" placeholder="Street address">
                    <div style="display:flex; gap:0.5rem;">
                        <input type="text" id="pfFirstName" class="map-input" placeholder="First name" value="<?= htmlspecialchars($dbUser['first_name'] ?? '') ?>">
                        <input type="text" id="pfLastName" class="map-input" placeholder="Last name" value="<?= htmlspecialchars($dbUser['last_name'] ?? '') ?>">
                    </div>
                    <?php if (!$dbUser || !$dbUser['email'] || strpos($dbUser['email'], '@anonymous.tpb') !== false): ?>
                    <input type="email" id="pfEmail" class="map-input" placeholder="Email (for verification later)">
                    <?php else: ?>
                    <input type="email" id="pfEmail" class="map-input" value="<?= htmlspecialchars($dbUser['email']) ?>" disabled title="Email already set">
                    <?php endif; ?>
                </div>

                <!-- Place card (populated after pin drop) -->
                <div id="placeCard" class="place-card">
                    <h4>📍 Your Location</h4>
                    <div class="detail-row"><span class="label">Address</span><span class="value" id="pcAddress">—</span></div>
                    <div class="detail-row"><span class="label">Town</span><span class="value" id="pcTown">—</span></div>
                    <div class="detail-row"><span class="label">State</span><span class="value" id="pcState">—</span></div>
                    <div class="detail-row"><span class="label">ZIP</span><span class="value" id="pcZip">—</span></div>
                    <div class="detail-row"><span class="label">County</span><span class="value" id="pcCounty">—</span></div>
                    <div class="detail-row"><span class="label">Lat / Lon</span><span class="value" id="pcLatLon">—</span></div>
                </div>

                <!-- Districts card (populated after pin drop via Civic API) -->
                <div id="districtsCard" class="districts-card">
                    <h4>🏛️ Your Districts</h4>
                    <div class="district-row"><span class="label">US Congress</span><span class="value" id="dcCongress">—</span></div>
                    <div class="district-row"><span class="label">State Senate</span><span class="value" id="dcSenate">—</span></div>
                    <div class="district-row"><span class="label">State House</span><span class="value" id="dcHouse">—</span></div>
                </div>

                <!-- Save button -->
                <button id="saveBtn" class="btn btn-primary btn-save-location" style="display:none;">
                    💾 Save My Location
                </button>
                <div id="saveStatus" class="save-status"></div>

                <!-- ============================================
                     FUTURE: Street View Panel
                     ============================================
                     Google Street View API — embed 360° panorama
                     after pin drop so user sees their actual street.
                     
                     <div class="sidebar-section" id="streetViewSection" style="display:none;">
                         <h3>👁️ Street View</h3>
                         <div id="streetViewPano" style="height:200px;border-radius:8px;overflow:hidden;"></div>
                     </div>
                     
                     JS: new google.maps.StreetViewPanorama(element, {
                         position: pinLatLng,
                         pov: { heading: 0, pitch: 0 },
                         zoom: 1
                     });
                     API: Maps JavaScript API (already enabled)
                     ============================================ -->

                <!-- ============================================
                     FUTURE: Air Quality Panel
                     ============================================
                     Google Air Quality API — show current AQI for
                     the user's pinned location. Civic engagement:
                     "Your air quality today is..."
                     
                     Endpoint: POST https://airquality.googleapis.com/v1/currentConditions:lookup
                     Body: { "location": { "latitude": lat, "longitude": lng } }
                     Returns: aqi, category, dominantPollutant, color
                     API: Air Quality API (needs enabling in console)
                     Free tier: 500 requests/day
                     ============================================ -->

                <!-- ============================================
                     FUTURE: Weather Panel
                     ============================================
                     Google Weather API — current conditions for
                     the pinned location.
                     
                     Endpoint: GET https://weather.googleapis.com/v1/currentConditions:lookup
                     Body: { "location": { "latitude": lat, "longitude": lng } }
                     Returns: temperature, humidity, weatherCondition, windSpeed
                     API: Weather API (needs enabling in console)
                     ============================================ -->

                <!-- ============================================
                     FUTURE: Solar Potential Panel
                     ============================================
                     Google Solar API — show solar potential for
                     buildings at the pinned location. Environmental
                     civic engagement.
                     
                     Endpoint: GET https://solar.googleapis.com/v1/buildingInsights:findClosest
                     Params: location.latitude, location.longitude
                     Returns: solarPotential, maxArrayPanelsCount, 
                              yearlyEnergyDcKwh, financialAnalyses
                     API: Solar API (needs enabling in console)
                     ============================================ -->

                <!-- ============================================
                     FUTURE: Places / Business Directory Panel
                     ============================================
                     Google Places API (New) — search nearby businesses
                     for directory mode. Supplement directory_listings
                     table with Google's data.
                     
                     Nearby Search:
                     POST https://places.googleapis.com/v1/places:searchNearby
                     Body: { "locationRestriction": { "circle": { 
                         "center": { "latitude": lat, "longitude": lng },
                         "radius": 5000 
                     }}, "includedTypes": ["restaurant","store",...] }
                     
                     Place Details:
                     GET https://places.googleapis.com/v1/places/{placeId}
                     Returns: displayName, formattedAddress, rating, 
                              currentOpeningHours, photos, reviews
                     
                     API: Places API (New) (needs enabling)
                     Free tier: 5,000 requests/month (Essentials)
                     ============================================ -->

                <!-- ============================================
                     FUTURE: Address Validation Panel
                     ============================================
                     Google Address Validation API — verify and
                     standardize the address from reverse geocode
                     before saving to DB.
                     
                     Endpoint: POST https://addressvalidation.googleapis.com/v1:validateAddress
                     Body: { "address": { "addressLines": ["123 Main St, Putnam, CT 06260"] } }
                     Returns: verdict (complete/incomplete), standardized components,
                              geocode, uspsData (USPS validation)
                     
                     API: Address Validation API (needs enabling)
                     Free tier: 100 validations/month (Essentials)
                     ============================================ -->

                <!-- ============================================
                     FUTURE: Elevation Data
                     ============================================
                     Google Elevation API — get elevation at pinned
                     location. Could be useful for flood zone
                     awareness or terrain context.
                     
                     Endpoint: GET https://maps.googleapis.com/maps/api/elevation/json
                     Params: locations=lat,lng
                     Returns: elevation (meters), resolution
                     API: Elevation API (needs enabling)
                     ============================================ -->

                <!-- ============================================
                     FUTURE: user_places Table (Multi-Pin)
                     ============================================
                     For My Map mode — allow users to save multiple
                     named locations (home, work, parent's house).
                     
                     CREATE TABLE user_places (
                         place_id INT AUTO_INCREMENT PRIMARY KEY,
                         user_id INT NOT NULL,
                         place_name VARCHAR(100) NOT NULL,
                         place_type ENUM('home','work','family','other') DEFAULT 'other',
                         latitude DECIMAL(9,6),
                         longitude DECIMAL(9,6),
                         street_address VARCHAR(255),
                         town_id INT,
                         zip_code VARCHAR(5),
                         state_id INT,
                         us_congress_district VARCHAR(10),
                         state_senate_district VARCHAR(20),
                         state_house_district VARCHAR(20),
                         is_primary TINYINT(1) DEFAULT 0,
                         is_public TINYINT(1) DEFAULT 0,
                         note TEXT,
                         created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                         updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                         FOREIGN KEY (user_id) REFERENCES users(user_id),
                         FOREIGN KEY (town_id) REFERENCES towns(town_id),
                         FOREIGN KEY (state_id) REFERENCES states(state_id)
                     );
                     ============================================ -->

            </div>

            <!-- Map -->
            <div id="map" style="position:relative;">
                <div class="map-instructions" id="mapInstructions">
                    Select your state and town, then click the map to drop your pin
                </div>
            </div>
        </div>
    </div>

<?php include 'includes/footer.php'; ?>

    <script>
    /**
     * TPB Map — Core JavaScript
     * =========================
     * Google Maps dark mode with state/town selection,
     * pin drop, reverse geocode, district lookup, and DB save.
     */

    // =========================================================
    // CONFIG
    // =========================================================
    const MAP_CONFIG = {
        mode: '<?= htmlspecialchars($mode) ?>',
        userId: <?= $dbUser ? (int)$dbUser['user_id'] : 'null' ?>,
        sessionId: document.cookie.match(/tpb_civic_session=([^;]+)/)?.[1] || '',
        isLoggedIn: <?= $isLoggedIn ? 'true' : 'false' ?>,
        // Pre-fill from DB if user has location
        userLat: <?= $userLat ? (float)$userLat : 'null' ?>,
        userLng: <?= $userLng ? (float)$userLng : 'null' ?>,
        userState: '<?= htmlspecialchars($userStateAbbr) ?>'.toUpperCase(),
        userTown: '<?= htmlspecialchars(addslashes($userTownName)) ?>',
        userStateName: '<?= htmlspecialchars(addslashes($userStateName)) ?>'
    };

    // =========================================================
    // STATE NAMES + CENTERS (for zoom)
    // =========================================================
    const stateNames = {
        'AL':'Alabama','AK':'Alaska','AZ':'Arizona','AR':'Arkansas','CA':'California',
        'CO':'Colorado','CT':'Connecticut','DE':'Delaware','DC':'District of Columbia',
        'FL':'Florida','GA':'Georgia','HI':'Hawaii','ID':'Idaho','IL':'Illinois',
        'IN':'Indiana','IA':'Iowa','KS':'Kansas','KY':'Kentucky','LA':'Louisiana',
        'ME':'Maine','MD':'Maryland','MA':'Massachusetts','MI':'Michigan','MN':'Minnesota',
        'MS':'Mississippi','MO':'Missouri','MT':'Montana','NE':'Nebraska','NV':'Nevada',
        'NH':'New Hampshire','NJ':'New Jersey','NM':'New Mexico','NY':'New York',
        'NC':'North Carolina','ND':'North Dakota','OH':'Ohio','OK':'Oklahoma',
        'OR':'Oregon','PA':'Pennsylvania','RI':'Rhode Island','SC':'South Carolina',
        'SD':'South Dakota','TN':'Tennessee','TX':'Texas','UT':'Utah','VT':'Vermont',
        'VA':'Virginia','WA':'Washington','WV':'West Virginia','WI':'Wisconsin','WY':'Wyoming'
    };
    const stateCenters = {
        'AL':{lat:32.8,lng:-86.8},'AK':{lat:64.2,lng:-152.5},'AZ':{lat:34.2,lng:-111.6},
        'AR':{lat:34.8,lng:-92.2},'CA':{lat:37.2,lng:-119.5},'CO':{lat:39.0,lng:-105.5},
        'CT':{lat:41.6,lng:-72.7},'DE':{lat:39.0,lng:-75.5},'DC':{lat:38.9,lng:-77.0},
        'FL':{lat:28.6,lng:-82.4},'GA':{lat:32.7,lng:-83.5},'HI':{lat:20.8,lng:-156.3},
        'ID':{lat:44.4,lng:-114.6},'IL':{lat:40.0,lng:-89.2},'IN':{lat:39.8,lng:-86.2},
        'IA':{lat:42.0,lng:-93.5},'KS':{lat:38.5,lng:-98.3},'KY':{lat:37.8,lng:-85.7},
        'LA':{lat:31.0,lng:-92.0},'ME':{lat:45.4,lng:-69.2},'MD':{lat:39.0,lng:-76.8},
        'MA':{lat:42.2,lng:-71.8},'MI':{lat:44.3,lng:-84.5},'MN':{lat:46.3,lng:-94.3},
        'MS':{lat:32.7,lng:-89.7},'MO':{lat:38.4,lng:-92.5},'MT':{lat:47.0,lng:-109.6},
        'NE':{lat:41.5,lng:-99.8},'NV':{lat:39.8,lng:-116.4},'NH':{lat:43.7,lng:-71.6},
        'NJ':{lat:40.1,lng:-74.7},'NM':{lat:34.5,lng:-106.0},'NY':{lat:42.9,lng:-75.5},
        'NC':{lat:35.5,lng:-79.4},'ND':{lat:47.5,lng:-100.5},'OH':{lat:40.4,lng:-82.8},
        'OK':{lat:35.6,lng:-97.5},'OR':{lat:44.0,lng:-120.5},'PA':{lat:41.0,lng:-77.5},
        'RI':{lat:41.7,lng:-71.5},'SC':{lat:34.0,lng:-81.0},'SD':{lat:44.4,lng:-100.2},
        'TN':{lat:35.8,lng:-86.4},'TX':{lat:31.5,lng:-99.3},'UT':{lat:39.3,lng:-111.7},
        'VT':{lat:44.1,lng:-72.6},'VA':{lat:37.5,lng:-79.0},'WA':{lat:47.4,lng:-120.7},
        'WV':{lat:38.6,lng:-80.6},'WI':{lat:44.6,lng:-89.8},'WY':{lat:43.0,lng:-107.6}
    };

    // =========================================================
    // MAP VARIABLES
    // =========================================================
    let map, geocoder, marker;
    let selectedState = MAP_CONFIG.userState || '';
    let selectedTown = MAP_CONFIG.userTown || '';
    let towns = [];
    let locationData = null;

    // =========================================================
    // INIT MAP
    // =========================================================
    function initMap() {
        // Default center: USA overview, or user's location if known
        let center = { lat: 39.8, lng: -98.6 };
        let zoom = 4;

        if (MAP_CONFIG.userLat && MAP_CONFIG.userLng) {
            center = { lat: MAP_CONFIG.userLat, lng: MAP_CONFIG.userLng };
            zoom = 14;
        } else if (selectedState && stateCenters[selectedState]) {
            center = stateCenters[selectedState];
            zoom = 7;
        }

        map = new google.maps.Map(document.getElementById('map'), {
            center: center,
            zoom: zoom,
            colorScheme: google.maps.ColorScheme.DARK,
            disableDefaultUI: false,
            zoomControl: true,
            mapTypeControl: false,
            streetViewControl: true,
            fullscreenControl: true
        });

        geocoder = new google.maps.Geocoder();

        // Click to drop pin
        map.addListener('click', function(e) {
            if (!selectedState) {
                showInstruction('Please select your state first');
                return;
            }
            dropPin(e.latLng);
        });

        // If user has saved location, show their pin
        if (MAP_CONFIG.userLat && MAP_CONFIG.userLng) {
            const pos = new google.maps.LatLng(MAP_CONFIG.userLat, MAP_CONFIG.userLng);
            dropPin(pos);
        }

        // Populate state dropdown
        populateStates();

        // Pre-select state if known
        if (selectedState) {
            document.getElementById('stateSelect').value = selectedState;
            document.getElementById('townInput').disabled = false;
            loadTowns(selectedState);
        }
    }

    // =========================================================
    // STATE DROPDOWN
    // =========================================================
    function populateStates() {
        const sel = document.getElementById('stateSelect');
        const sorted = Object.keys(stateNames).sort(function(a, b) {
            return stateNames[a].localeCompare(stateNames[b]);
        });
        sorted.forEach(function(code) {
            const opt = document.createElement('option');
            opt.value = code;
            opt.textContent = stateNames[code];
            sel.appendChild(opt);
        });

        sel.addEventListener('change', function() {
            selectedState = this.value;
            selectedTown = '';
            document.getElementById('townInput').value = '';
            document.getElementById('townInput').disabled = !selectedState;
            hidePlaceCard();

            if (selectedState && stateCenters[selectedState]) {
                map.setCenter(stateCenters[selectedState]);
                map.setZoom(7);
                loadTowns(selectedState);
                showInstruction('Now type your town name');
            } else {
                showInstruction('Select your state to begin');
            }
        });
    }

    // =========================================================
    // TOWN AUTOCOMPLETE
    // =========================================================
    function loadTowns(stateCode) {
        fetch('/api/zip-lookup.php?action=get_state_towns&state_code=' + stateCode)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.status === 'success') {
                    towns = data.data;
                }
            });
    }

    (function() {
        const input = document.getElementById('townInput');
        const list = document.getElementById('townList');
        let debounceTimer;

        input.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            const val = this.value.trim().toLowerCase();
            if (val.length < 2) { list.style.display = 'none'; return; }

            debounceTimer = setTimeout(function() {
                const matches = towns.filter(function(t) {
                    return t.toLowerCase().indexOf(val) !== -1;
                }).slice(0, 15);

                list.innerHTML = '';
                if (matches.length === 0) {
                    list.style.display = 'none';
                    return;
                }
                matches.forEach(function(t) {
                    const div = document.createElement('div');
                    div.className = 'item';
                    div.textContent = t;
                    div.addEventListener('click', function() {
                        selectTown(t);
                    });
                    list.appendChild(div);
                });
                list.style.display = 'block';
            }, 150);
        });

        // Hide list on blur (with delay for click)
        input.addEventListener('blur', function() {
            setTimeout(function() { list.style.display = 'none'; }, 200);
        });
    })();

    function selectTown(townName) {
        selectedTown = townName;
        document.getElementById('townInput').value = townName;
        document.getElementById('townList').style.display = 'none';

        // Zoom to town using coords from DB
        fetch('/api/zip-lookup.php?action=get_coords&town=' + encodeURIComponent(townName) + '&state=' + selectedState)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.status === 'success' && data.data) {
                    const lat = parseFloat(data.data.latitude);
                    const lng = parseFloat(data.data.longitude);
                    if (!isNaN(lat) && !isNaN(lng)) {
                        map.setCenter({ lat: lat, lng: lng });
                        map.setZoom(14);
                        showInstruction('Now click the map to drop your pin');
                    }
                }
            });
    }

    // =========================================================
    // PIN DROP + REVERSE GEOCODE
    // =========================================================
    function dropPin(latLng) {
        const lat = latLng.lat();
        const lng = latLng.lng();

        // Place or move marker
        if (marker) {
            marker.setPosition(latLng);
        } else {
            marker = new google.maps.Marker({
                position: latLng,
                map: map,
                draggable: true,
                animation: google.maps.Animation.DROP
            });
            // Re-geocode on drag
            marker.addListener('dragend', function() {
                reverseGeocode(marker.getPosition());
                lookupDistricts(marker.getPosition());
            });
        }

        reverseGeocode(latLng);
        lookupDistricts(latLng);
        hideInstruction();
    }

    function reverseGeocode(latLng) {
        const lat = latLng.lat().toFixed(6);
        const lng = latLng.lng().toFixed(6);

        locationData = {
            latitude: lat,
            longitude: lng,
            state_code: selectedState,
            town_name: selectedTown
        };

        // Update place card immediately with coords
        document.getElementById('pcLatLon').textContent = lat + ', ' + lng;
        showPlaceCard();

        geocoder.geocode({ location: latLng }, function(results, status) {
            if (status === 'OK' && results[0]) {
                const r = results[0];
                const components = r.address_components;

                let address = r.formatted_address || '';
                let town = '', state = '', zip = '', county = '';

                components.forEach(function(c) {
                    if (c.types.indexOf('locality') !== -1) town = c.long_name;
                    if (c.types.indexOf('administrative_area_level_1') !== -1) state = c.short_name;
                    if (c.types.indexOf('postal_code') !== -1) zip = c.short_name;
                    if (c.types.indexOf('administrative_area_level_2') !== -1) county = c.long_name;
                    // Some areas use sublocality or neighborhood
                    if (!town && c.types.indexOf('sublocality') !== -1) town = c.long_name;
                    if (!town && c.types.indexOf('neighborhood') !== -1) town = c.long_name;
                });

                document.getElementById('pcAddress').textContent = address;
                document.getElementById('pcTown').textContent = town || selectedTown || '—';
                document.getElementById('pcState').textContent = state || selectedState || '—';
                document.getElementById('pcZip').textContent = zip || '—';
                document.getElementById('pcCounty').textContent = county || '—';

                // Update locationData
                locationData.address = address;
                locationData.town_name = town || selectedTown;
                locationData.state_code = state || selectedState;
                locationData.zip_code = zip;
                locationData.county = county;

                // Show save button
                document.getElementById('saveBtn').style.display = 'block';

                // Show profile fields and pre-fill address
                document.getElementById('profileFields').style.display = 'block';
                document.getElementById('pfAddress').value = address;
            }
        });
    }

    // =========================================================
    // DISTRICT LOOKUP (Google Civic Information API)
    // =========================================================
    function lookupDistricts(latLng) {
        const addr = latLng.lat().toFixed(6) + ',' + latLng.lng().toFixed(6);
        const url = 'https://www.googleapis.com/civicinfo/v2/representatives?address=' + encodeURIComponent(addr) + '&key=AIzaSyBbppmpBODtUtMOx5E9RlZMNrSD44PFZnM';

        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.divisions) return;

                let congress = '—', senate = '—', house = '—';

                Object.keys(data.divisions).forEach(function(ocdId) {
                    if (ocdId.indexOf('/cd:') !== -1) {
                        const m = ocdId.match(/\/cd:(\d+)/);
                        if (m) congress = 'District ' + m[1];
                    }
                    if (ocdId.indexOf('/sldl:') !== -1) {
                        const m = ocdId.match(/\/sldl:(\d+)/);
                        if (m) house = 'District ' + m[1];
                    }
                    if (ocdId.indexOf('/sldu:') !== -1) {
                        const m = ocdId.match(/\/sldu:(\d+)/);
                        if (m) senate = 'District ' + m[1];
                    }
                });

                document.getElementById('dcCongress').textContent = congress;
                document.getElementById('dcSenate').textContent = senate;
                document.getElementById('dcHouse').textContent = house;
                showDistrictsCard();

                // Store in locationData
                locationData.us_congress_district = congress;
                locationData.state_senate_district = senate;
                locationData.state_house_district = house;
            })
            .catch(function() {
                // Civic API may fail — not critical
            });
    }

    /* =========================================================
     * FUTURE: Street View Integration
     * =========================================================
     * After pin drop, show 360° panorama of the location.
     * 
     * function showStreetView(latLng) {
     *     const pano = new google.maps.StreetViewPanorama(
     *         document.getElementById('streetViewPano'), {
     *             position: latLng,
     *             pov: { heading: 0, pitch: 0 },
     *             zoom: 1
     *         }
     *     );
     *     document.getElementById('streetViewSection').style.display = 'block';
     * }
     * 
     * Call after dropPin: showStreetView(latLng);
     * ========================================================= */

    /* =========================================================
     * FUTURE: Air Quality Lookup
     * =========================================================
     * After pin drop, fetch current AQI for that location.
     * 
     * async function lookupAirQuality(lat, lng) {
     *     const resp = await fetch(
     *         'https://airquality.googleapis.com/v1/currentConditions:lookup?key=API_KEY', {
     *         method: 'POST',
     *         headers: { 'Content-Type': 'application/json' },
     *         body: JSON.stringify({
     *             location: { latitude: lat, longitude: lng }
     *         })
     *     });
     *     const data = await resp.json();
     *     // data.indexes[0] -> { aqi, category, dominantPollutant, color }
     *     // Display in air quality panel
     * }
     * 
     * API: Air Quality API (enable in console)
     * ========================================================= */

    /* =========================================================
     * FUTURE: Weather Lookup
     * =========================================================
     * After pin drop, fetch current weather for that location.
     * 
     * async function lookupWeather(lat, lng) {
     *     const resp = await fetch(
     *         'https://weather.googleapis.com/v1/currentConditions:lookup' +
     *         '?location.latitude=' + lat + '&location.longitude=' + lng +
     *         '&key=API_KEY');
     *     const data = await resp.json();
     *     // data.temperature, data.humidity, data.weatherCondition
     * }
     * 
     * API: Weather API (enable in console)
     * ========================================================= */

    /* =========================================================
     * FUTURE: Solar Potential Lookup
     * =========================================================
     * After pin drop, show solar panel potential for nearby buildings.
     * 
     * async function lookupSolar(lat, lng) {
     *     const resp = await fetch(
     *         'https://solar.googleapis.com/v1/buildingInsights:findClosest' +
     *         '?location.latitude=' + lat + '&location.longitude=' + lng +
     *         '&key=API_KEY');
     *     const data = await resp.json();
     *     // data.solarPotential.maxArrayPanelsCount
     *     // data.solarPotential.yearlyEnergyDcKwh
     * }
     * 
     * API: Solar API (enable in console)
     * ========================================================= */

    /* =========================================================
     * FUTURE: Places / Nearby Business Search
     * =========================================================
     * For directory mode — search nearby businesses after pin drop
     * or town selection. Supplement directory_listings table.
     * 
     * async function searchNearbyPlaces(lat, lng, type) {
     *     const resp = await fetch(
     *         'https://places.googleapis.com/v1/places:searchNearby', {
     *         method: 'POST',
     *         headers: {
     *             'Content-Type': 'application/json',
     *             'X-Goog-Api-Key': 'API_KEY',
     *             'X-Goog-FieldMask': 'places.displayName,places.formattedAddress,places.rating,places.currentOpeningHours'
     *         },
     *         body: JSON.stringify({
     *             locationRestriction: {
     *                 circle: {
     *                     center: { latitude: lat, longitude: lng },
     *                     radius: 5000
     *                 }
     *             },
     *             includedTypes: [type] // 'restaurant', 'store', etc.
     *         })
     *     });
     *     const data = await resp.json();
     *     // data.places[] -> render as markers + list
     * }
     * 
     * API: Places API (New) (enable in console)
     * ========================================================= */

    /* =========================================================
     * FUTURE: Address Validation
     * =========================================================
     * Before saving, validate the reverse-geocoded address.
     * 
     * async function validateAddress(address) {
     *     const resp = await fetch(
     *         'https://addressvalidation.googleapis.com/v1:validateAddress?key=API_KEY', {
     *         method: 'POST',
     *         headers: { 'Content-Type': 'application/json' },
     *         body: JSON.stringify({
     *             address: { addressLines: [address] }
     *         })
     *     });
     *     const data = await resp.json();
     *     // data.result.verdict.addressComplete (true/false)
     *     // data.result.address.formattedAddress (standardized)
     *     // data.result.uspsData (USPS validation)
     * }
     * 
     * API: Address Validation API (enable in console)
     * ========================================================= */

    /* =========================================================
     * FUTURE: Elevation Lookup
     * =========================================================
     * Get elevation at pin for flood zone context.
     * 
     * async function lookupElevation(lat, lng) {
     *     const resp = await fetch(
     *         'https://maps.googleapis.com/maps/api/elevation/json' +
     *         '?locations=' + lat + ',' + lng +
     *         '&key=API_KEY');
     *     const data = await resp.json();
     *     // data.results[0].elevation (meters)
     * }
     * 
     * API: Elevation API (enable in console)
     * ========================================================= */

    // =========================================================
    // SAVE LOCATION TO DATABASE
    // =========================================================
    document.getElementById('saveBtn').addEventListener('click', function() {
        if (!locationData) return;
        this.disabled = true;
        this.textContent = 'Saving...';

        // Single API call — saves everything at once (GET to avoid ModSecurity blocking POST/JSON)
        const pfAddress = document.getElementById('pfAddress').value.trim();
        const pfFirstName = document.getElementById('pfFirstName').value.trim();
        const pfLastName = document.getElementById('pfLastName').value.trim();
        const pfEmailEl = document.getElementById('pfEmail');
        const pfEmail = (!pfEmailEl.disabled) ? pfEmailEl.value.trim() : '';

        // Build URL manually to ensure clean encoding
        let saveUrl = '/api/create-user-location.php?action=save_location'
            + '&user_id=' + encodeURIComponent(MAP_CONFIG.userId || '')
            + '&session_id=' + encodeURIComponent(MAP_CONFIG.sessionId || '')
            + '&state_code=' + encodeURIComponent(locationData.state_code)
            + '&town_name=' + encodeURIComponent(locationData.town_name)
            + '&zip_code=' + encodeURIComponent(locationData.zip_code || '')
            + '&latitude=' + encodeURIComponent(locationData.latitude)
            + '&longitude=' + encodeURIComponent(locationData.longitude)
            + '&street_address=' + encodeURIComponent(pfAddress || locationData.address || '')
            + '&first_name=' + encodeURIComponent(pfFirstName)
            + '&last_name=' + encodeURIComponent(pfLastName)
            + '&email=' + encodeURIComponent(pfEmail);

        // Districts may contain special chars, only add if set
        if (locationData.us_congress_district && locationData.us_congress_district !== '—') {
            saveUrl += '&us_congress_district=' + encodeURIComponent(locationData.us_congress_district);
        }
        if (locationData.state_senate_district && locationData.state_senate_district !== '—') {
            saveUrl += '&state_senate_district=' + encodeURIComponent(locationData.state_senate_district);
        }
        if (locationData.state_house_district && locationData.state_house_district !== '—') {
            saveUrl += '&state_house_district=' + encodeURIComponent(locationData.state_house_district);
        }

        fetch(saveUrl)
        .then(function(r) { return r.json(); })
        .then(function(result) {
            if (result.status !== 'success') {
                throw new Error(result.message || 'Save failed');
            }
            var msg = '✅ Location saved! Welcome to ' + (locationData.town_name || 'your town') + '!';
            if (result.magic_link_sent) {
                msg += '\n\n📧 Check your email to verify your account!';
            }
            showSaveStatus('success', msg);
            document.getElementById('saveBtn').textContent = '✅ Saved!';
            // Log civic points
            logCivicAction('map_save_location', 'map', JSON.stringify({
                state: locationData.state_code,
                town: locationData.town_name,
                lat: locationData.latitude,
                lng: locationData.longitude
            }));
        })
        .catch(function(err) {
            showSaveStatus('error', '❌ ' + (err.message || 'Save failed. Please try again.'));
            document.getElementById('saveBtn').disabled = false;
            document.getElementById('saveBtn').textContent = '💾 Save My Location';
        });
    });

    // =========================================================
    // CIVIC POINTS LOGGING
    // =========================================================
    function logCivicAction(actionType, elementId, extraData) {
        fetch('/api/log-civic-click.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                session_id: MAP_CONFIG.sessionId,
                action_type: actionType,
                element_id: elementId,
                extra_data: extraData || ''
            })
        }).catch(function() { /* non-critical */ });
    }

    // =========================================================
    // UI HELPERS
    // =========================================================
    function showPlaceCard() {
        document.getElementById('placeCard').classList.add('visible');
    }
    function hidePlaceCard() {
        document.getElementById('placeCard').classList.remove('visible');
        document.getElementById('districtsCard').classList.remove('visible');
        document.getElementById('saveBtn').style.display = 'none';
    }
    function showDistrictsCard() {
        document.getElementById('districtsCard').classList.add('visible');
    }
    function showInstruction(text) {
        const el = document.getElementById('mapInstructions');
        el.textContent = text;
        el.style.opacity = '1';
    }
    function hideInstruction() {
        document.getElementById('mapInstructions').style.opacity = '0';
    }
    function showSaveStatus(type, msg) {
        const el = document.getElementById('saveStatus');
        el.className = 'save-status ' + type;
        el.textContent = msg;
    }
    </script>
    <script async defer
        src="https://maps.googleapis.com/maps/api/js?key=AIzaSyBbppmpBODtUtMOx5E9RlZMNrSD44PFZnM&callback=initMap">
    </script>
