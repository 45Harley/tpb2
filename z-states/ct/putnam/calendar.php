<?php
/**
 * Putnam Town Calendar
 * ====================
 * Displays upcoming civic meetings from town iCal feeds
 * /z-states/ct/putnam/calendar.php
 */

// Bootstrap
$config = require __DIR__ . '/../../../config.php';

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

// Town constants
$townId = 119;
$townName = 'Putnam';
$townSlug = 'putnam';
$stateAbbr = 'ct';

// Load user data
require_once __DIR__ . '/../../../includes/get-user.php';
$dbUser = getUser($pdo);

// Calculate trust level for nav
$trustLevel = 'Visitor';
$userTrustLevel = 0;
if ($dbUser) {
    if (!empty($dbUser['phone_verified'])) {
        $trustLevel = 'Verified (2FA)';
        $userTrustLevel = 3;
    } elseif (!empty($dbUser['email_verified'])) {
        $trustLevel = 'Email Verified';
        $userTrustLevel = 2;
    } elseif (!empty($dbUser['email'])) {
        $trustLevel = 'Registered';
        $userTrustLevel = 1;
    }
}

// Nav variables
$isLoggedIn = (bool)$dbUser;
$points = $dbUser ? (int)$dbUser['civic_points'] : 0;
$userEmail = $dbUser['email'] ?? '';
$userTownName = $dbUser['town_name'] ?? '';
$userTownSlug = $userTownName ? strtolower(str_replace(' ', '-', $userTownName)) : '';
$userStateAbbr = strtolower($dbUser['state_abbrev'] ?? '');
$userStateDisplay = strtoupper($userStateAbbr);

// Page config
$currentPage = 'town';
$pageTitle = 'Putnam Town Calendar | The People\'s Branch';

// =====================================================
// SECONDARY NAV - Town-specific navigation
// =====================================================
$secondaryNavBrand = 'Putnam';
$secondaryNav = [
    ['label' => 'Overview', 'url' => 'index.php#overview'],
    ['label' => 'History', 'url' => 'index.php#history'],
    ['label' => 'Government', 'url' => 'index.php#government'],
    ['label' => 'Calendar', 'url' => 'calendar.php'],
    ['label' => 'Budget', 'url' => 'index.php#budget'],
    ['label' => 'Schools', 'url' => 'index.php#schools'],
    ['label' => 'School Budget', 'url' => 'putnam-schools-budget.html'],
    ['label' => 'Living Here', 'url' => 'index.php#living'],
    ['label' => 'Talk', 'url' => '/talk/?town=119'],
];

// =====================================================
// iCAL FEEDS
// =====================================================
$feeds = [
    'Board of Selectmen' => 'https://www.putnamct.us/cf_calendar/feed.cfm?type=ical&feedID=F79AAA5B68B543D3ACC606D1F77C1ED0'
    // Add more feeds here as discovered:
    // 'Planning & Zoning' => 'https://...',
    // 'Board of Education' => 'https://...',
];

/**
 * Fetch and parse an iCal feed
 */
function fetchIcalEvents($url, $sourceName) {
    $events = [];
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'user_agent' => 'TPB Calendar Fetcher'
        ]
    ]);
    
    $ical = @file_get_contents($url, false, $context);
    if ($ical === false) {
        return $events;
    }
    
    // Split into events
    preg_match_all('/BEGIN:VEVENT(.+?)END:VEVENT/s', $ical, $matches);
    
    foreach ($matches[1] as $eventData) {
        $event = [
            'source' => $sourceName,
            'title' => '',
            'date' => null,
            'time' => null,
            'location' => '',
            'description' => '',
            'all_day' => false,
            'document_url' => null
        ];
        
        // Parse SUMMARY (title)
        if (preg_match('/SUMMARY:(.+?)(?:\r?\n(?=[A-Z])|\r?\n$)/s', $eventData, $m)) {
            $event['title'] = trim($m[1]);
        }
        
        // Parse DTSTART
        if (preg_match('/DTSTART;VALUE=DATE:(\d{8})/', $eventData, $m)) {
            // All-day event
            $event['date'] = DateTime::createFromFormat('Ymd', $m[1]);
            $event['all_day'] = true;
        } elseif (preg_match('/DTSTART:(\d{8}T\d{6})/', $eventData, $m)) {
            // Date with time (local)
            $event['date'] = DateTime::createFromFormat('Ymd\THis', $m[1]);
            $event['time'] = $event['date']->format('g:i A');
        } elseif (preg_match('/DTSTART:(\d{8}T\d{6}Z)/', $eventData, $m)) {
            // Date with time (UTC)
            $event['date'] = DateTime::createFromFormat('Ymd\THis\Z', $m[1], new DateTimeZone('UTC'));
            $event['date']->setTimezone(new DateTimeZone('America/New_York'));
            $event['time'] = $event['date']->format('g:i A');
        }
        
        // Parse LOCATION
        if (preg_match('/LOCATION:(.+?)(?:\r?\n(?=[A-Z])|\r?\n$)/s', $eventData, $m)) {
            $event['location'] = trim($m[1]);
        }
        
        // Parse DESCRIPTION (may contain document URLs)
        if (preg_match('/DESCRIPTION:(.+?)(?:\r?\n(?=[A-Z])|\r?\n$)/s', $eventData, $m)) {
            $desc = trim($m[1]);
            // Unescape iCal format
            $desc = str_replace(['\\n', '\\,', '\\;'], ["\n", ',', ';'], $desc);
            $event['description'] = $desc;
            
            // Extract PDF/document links
            if (preg_match('/\/uploaded\/[^\s]+\.pdf/i', $desc, $docMatch)) {
                $event['document_url'] = 'https://www.putnamct.us' . $docMatch[0];
            }
        }
        
        if ($event['date']) {
            $events[] = $event;
        }
    }
    
    return $events;
}

// Fetch all events from all feeds
$allEvents = [];
foreach ($feeds as $name => $url) {
    $events = fetchIcalEvents($url, $name);
    $allEvents = array_merge($allEvents, $events);
}

// Filter to future events only (including today)
$today = new DateTime('today');
$allEvents = array_filter($allEvents, function($e) use ($today) {
    return $e['date'] >= $today;
});

// Sort by date
usort($allEvents, function($a, $b) {
    return $a['date'] <=> $b['date'];
});

// Group by month for display
$eventsByMonth = [];
foreach ($allEvents as $event) {
    $monthKey = $event['date']->format('F Y');
    if (!isset($eventsByMonth[$monthKey])) {
        $eventsByMonth[$monthKey] = [];
    }
    $eventsByMonth[$monthKey][] = $event;
}

// Include header and nav
require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/nav.php';
?>

<style>
.calendar-page {
    max-width: 900px;
    margin: 0 auto;
    padding: 20px;
}
.calendar-header {
    margin-bottom: 30px;
}
.calendar-header h1 {
    color: #e0e0e0;
    margin-bottom: 5px;
}
.calendar-header .subtitle {
    color: #888;
}
.feed-info {
    background: #1a1a2e;
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 25px;
    border-left: 4px solid #d4af37;
    color: #ccc;
}
.feed-info strong {
    color: #d4af37;
}
.feed-info a {
    color: #7ab8e0;
}
.month-header {
    background: linear-gradient(135deg, #1a365d 0%, #2a4a7f 100%);
    color: white;
    padding: 12px 20px;
    margin: 30px 0 15px 0;
    border-radius: 8px;
    font-size: 1.1em;
    font-weight: 500;
}
.month-header:first-of-type {
    margin-top: 0;
}
.event {
    background: #1e1e2e;
    border-left: 4px solid #c41e3a;
    padding: 18px 20px;
    margin-bottom: 12px;
    border-radius: 0 8px 8px 0;
    transition: transform 0.2s, box-shadow 0.2s;
}
.event:hover {
    transform: translateX(5px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.3);
}
.event-date {
    font-weight: 600;
    color: #e0e0e0;
    font-size: 1.1em;
    margin-bottom: 5px;
}
.event-time {
    color: #c41e3a;
    font-weight: 500;
}
.event-title {
    font-size: 1.15em;
    color: #fff;
    margin: 8px 0;
}
.event-location {
    color: #888;
    font-size: 0.95em;
}
.event-location::before {
    content: "📍 ";
}
.event-source {
    display: inline-block;
    background: rgba(212, 175, 55, 0.2);
    color: #d4af37;
    padding: 3px 10px;
    border-radius: 4px;
    font-size: 0.8em;
    margin-top: 10px;
}
.event-document {
    margin-top: 10px;
}
.event-document a {
    color: #7ab8e0;
    text-decoration: none;
    font-size: 0.9em;
    transition: color 0.2s;
}
.event-document a:hover {
    color: #9ecbf0;
    text-decoration: underline;
}
.event-document a::before {
    content: "📄 ";
}
.event.all-day {
    border-left-color: #d69e2e;
    background: #1e1e28;
}
.no-events {
    text-align: center;
    padding: 50px 20px;
    color: #888;
    background: #1e1e2e;
    border-radius: 8px;
}
.calendar-footer {
    text-align: center;
    margin-top: 40px;
    padding-top: 20px;
    border-top: 1px solid #333;
    color: #666;
    font-size: 0.9em;
}
.calendar-footer a {
    color: #7ab8e0;
}
</style>

<main class="calendar-page">
    <div class="calendar-header">
        <h1>📅 <?= htmlspecialchars($townName) ?> Town Calendar</h1>
        <p class="subtitle">Upcoming civic meetings and events</p>
    </div>
    
    <div class="feed-info">
        <strong>Sources:</strong> 
        <?= implode(', ', array_keys($feeds)) ?>
        <br>
        <small>Data provided by <a href="https://www.putnamct.us" target="_blank">Town of Putnam</a> official calendar</small>
    </div>
    
    <?php if (empty($eventsByMonth)): ?>
        <div class="no-events">
            <p>No upcoming events found.</p>
            <p><small>Check back soon or visit <a href="https://www.putnamct.us" target="_blank">putnamct.us</a> for more information.</small></p>
        </div>
    <?php else: ?>
        <?php foreach ($eventsByMonth as $month => $events): ?>
            <div class="month-header"><?= htmlspecialchars($month) ?></div>
            
            <?php foreach ($events as $event): ?>
                <div class="event <?= $event['all_day'] ? 'all-day' : '' ?>">
                    <div class="event-date">
                        <?= $event['date']->format('l, F j') ?>
                        <?php if ($event['time']): ?>
                            <span class="event-time">@ <?= $event['time'] ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="event-title"><?= htmlspecialchars($event['title']) ?></div>
                    <?php if ($event['location']): ?>
                        <div class="event-location"><?= htmlspecialchars($event['location']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($event['document_url'])): ?>
                        <div class="event-document">
                            <a href="<?= htmlspecialchars($event['document_url']) ?>" target="_blank">View Agenda</a>
                        </div>
                    <?php endif; ?>
                    <div class="event-source"><?= htmlspecialchars($event['source']) ?></div>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <div class="calendar-footer">
        <p><a href="index.php">← Back to Putnam Overview</a></p>
    </div>
</main>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
