<?php
/**
 * TPB Landing Page - USA Map with Dialog Flow
 * ===========================================
 * Click state → dialog page (not modal)
 * Every action earns civic points
 * "Would you like to..." conversation
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

// Get states with active users (for gold highlighting on map)
$activeStates = [];
if ($pdo) {
    $stmt = $pdo->query("
        SELECT DISTINCT LOWER(s.abbreviation) as abbr 
        FROM states s 
        JOIN users u ON u.current_state_id = s.state_id
    ");
    $activeStates = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Congressional delegation data for map coloring
$congress = 119;
$stateData = [];
if ($pdo) {
    $reps = $pdo->prepare("
        SELECT eo.party, eo.state_code, rs.chamber
        FROM rep_scorecard rs
        JOIN elected_officials eo ON rs.official_id = eo.official_id
        WHERE rs.congress = ?
    ");
    $reps->execute([$congress]);
    foreach ($reps->fetchAll(PDO::FETCH_ASSOC) as $rep) {
        $sc = $rep['state_code'];
        if (!isset($stateData[$sc])) {
            $stateData[$sc] = ['dem' => 0, 'rep' => 0, 'ind' => 0];
        }
        $p = substr($rep['party'], 0, 1);
        if ($p === 'D') $stateData[$sc]['dem']++;
        elseif ($p === 'R') $stateData[$sc]['rep']++;
        else $stateData[$sc]['ind']++;
    }
    $stRows = $pdo->query("SELECT abbreviation, state_name FROM states")->fetchAll(PDO::FETCH_ASSOC);
    $stateNames = [];
    foreach ($stRows as $s) $stateNames[$s['abbreviation']] = $s['state_name'];
    foreach ($stateData as $sc => &$sd) {
        $sd['total'] = $sd['dem'] + $sd['rep'] + $sd['ind'];
        $sd['name'] = $stateNames[$sc] ?? $sc;
    }
    unset($sd);
}
$stateDataJson = json_encode($stateData, JSON_UNESCAPED_UNICODE);

// Get points_log points for this session (even if not logged in user)
$sessionPoints = 0;
if ($sessionId && $pdo) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(points_earned), 0) FROM points_log WHERE session_id = ?");
    $stmt->execute(array($sessionId));
    $sessionPoints = (int)$stmt->fetchColumn();
}

// Get session history - what have they done?
$sessionHistory = [];
if ($sessionId && $pdo) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT context_type AS action_type, context_id AS element_id, extra_data 
        FROM points_log 
        WHERE session_id = ?
        ORDER BY earned_at DESC
    ");
    $stmt->execute(array($sessionId));
    $sessionHistory = $stmt->fetchAll();
}

// Check if they've clicked a state before
$lastState = null;
$lastTown = null;
$lastZip = null;

// First, use profile location if set
if ($dbUser && $dbUser['state_abbrev']) {
    $lastState = $dbUser['state_abbrev'];
    $lastTown = $dbUser['town_name'];
}

// Fall back to click history if no profile location
if (!$lastState) {
    foreach ($sessionHistory as $h) {
        if ($h['action_type'] === 'state_click' && !$lastState) {
            $extra = json_decode($h['extra_data'], true);
            $lastState = $extra['state_code'] ?? $h['element_id'];
        }
        if ($h['action_type'] === 'zip_lookup' && !$lastTown) {
            $extra = json_decode($h['extra_data'], true);
            $lastTown = $extra['town'] ?? null;
            $lastZip = $extra['zip'] ?? null;
            $lastState = $extra['state_code'] ?? $lastState;
        }
    }
}

// Flag if user has saved profile location
$hasProfileLocation = ($dbUser && $dbUser['state_abbrev']) ? true : false;

// What have they done? (for "would you like to" options)
$hasViewedReps = false;
$hasViewedThoughts = false;
$hasVisitedStory = false;
$hasEnteredZip = false;
foreach ($sessionHistory as $h) {
    if ($h['action_type'] === 'page_visit' && strpos($h['element_id'] ?? '', 'reps') !== false) $hasViewedReps = true;
    if ($h['action_type'] === 'page_visit' && strpos($h['element_id'] ?? '', 'voice') !== false) $hasViewedThoughts = true;
    if ($h['action_type'] === 'page_visit' && strpos($h['element_id'] ?? '', 'story') !== false) $hasVisitedStory = true;
    if ($h['action_type'] === 'zip_lookup') $hasEnteredZip = true;
}

// Nav variables via helper (get-user.php already loaded above)
$navVars = getNavVarsForUser($dbUser, $sessionPoints);
extract($navVars);
$currentPage = 'home';

// Page title for header include
$pageTitle = 'The People\'s Branch - A More Perfect Union';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="The People's Branch - A More Perfect Union. You are the Fourth Branch. Nine branches of government serve you. Find your state and make your voice heard.">
    
    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://4tpb.org/">
    <meta property="og:title" content="USA | The People's Branch">
    <meta property="og:description" content="You are the Fourth Branch. Nine branches of government serve you. Find your state.">
    
    <title>USA - A More Perfect Union | The People's Branch</title>
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Georgia', serif;
            line-height: 1.6;
            color: #e0e0e0;
            background: #0a0a0a;
        }
        
        a {
            color: #d4af37;
            text-decoration: none;
        }
        
        a:hover {
            text-decoration: underline;
        }
        
        /* HERO */
        .hero {
            position: relative;
            min-height: 90vh;
            background: linear-gradient(rgba(0, 0, 0, 0.6), rgba(0, 0, 0, 0.8)),
                        url('0media/PeoplesBranch.png') center center / cover no-repeat,
                        linear-gradient(135deg, #1a1a2a 0%, #0a1a2a 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: white;
            padding: 60px 20px;
        }
        
        .hero-content {
            max-width: 800px;
            animation: fadeIn 1s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .usa-badge {
            display: inline-block;
            background: rgba(212, 175, 55, 0.15);
            border: 2px solid #d4af37;
            padding: 8px 25px;
            border-radius: 30px;
            font-size: 1em;
            margin-bottom: 25px;
            letter-spacing: 3px;
            color: #d4af37;
        }
        
        .hero h1 {
            font-size: 3.5em;
            margin-bottom: 20px;
            text-shadow: 2px 2px 8px rgba(0,0,0,0.8);
            font-weight: normal;
            line-height: 1.2;
        }
        
        .hero h1 span {
            color: #d4af37;
        }
        
        .foundation {
            font-size: 1.2em;
            color: #ccc;
            max-width: 700px;
            margin: 0 auto 40px;
            line-height: 1.8;
        }
        
        .foundation p {
            margin-bottom: 1.2em;
        }
        
        .foundation .highlight {
            color: #d4af37;
            font-style: italic;
        }
        
        .foundation .thought-power {
            font-size: 1.1em;
            color: #fff;
        }
        
        .scroll-hint {
            position: absolute;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            color: #d4af37;
            font-size: 1em;
            animation: pulse 2s infinite;
            cursor: pointer;
        }
        
        .scroll-hint span {
            display: block;
            font-size: 1.5em;
            margin-top: 5px;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 0.6; }
            50% { opacity: 1; }
        }
        
        /* STRUCTURE SECTION */
        .structure {
            background: #0f0f0f;
            padding: 60px 20px;
            border-top: 1px solid #222;
        }
        
        .structure-inner {
            max-width: 800px;
            margin: 0 auto;
            text-align: center;
        }
        
        .structure h2 {
            font-size: 1.8em;
            color: #d4af37;
            margin-bottom: 30px;
        }
        
        .pyramid {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .pyramid-level {
            background: #1a1a2a;
            border: 1px solid #333;
            border-radius: 8px;
            padding: 15px 30px;
            text-align: center;
        }
        
        .pyramid-level.you {
            background: linear-gradient(135deg, #2a2a1a 0%, #1a1a0a 100%);
            border-color: #d4af37;
            font-size: 1.3em;
        }
        
        .pyramid-level.you .label {
            color: #d4af37;
            font-weight: bold;
        }
        
        .pyramid-level .label {
            color: #888;
            font-size: 0.85em;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .pyramid-level .branches {
            color: #666;
            font-size: 0.8em;
            margin-top: 5px;
        }
        
        .pyramid-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .structure-note {
            color: #888;
            font-size: 1em;
            font-style: italic;
            max-width: 500px;
            margin: 0 auto;
        }
        
        /* MAP SECTION */
        .map-section {
            background: #0a0a0a;
            padding: 60px 20px;
        }
        
        .map-section h2 {
            text-align: center;
            font-size: 2em;
            margin-bottom: 10px;
            color: #d4af37;
        }
        
        .map-section h2.usa-title {
            cursor: pointer;
            transition: color 0.2s;
        }
        
        .map-section h2.usa-title:hover {
            color: #62a4d0;
        }
        
        .map-section .section-intro {
            text-align: center;
            max-width: 600px;
            margin: 0 auto 40px;
            color: #aaa;
        }
        
        .map-container {
            max-width: 900px;
            margin: 0 auto;
            position: relative;
        }
        
        .map-container svg {
            width: 100%;
            height: auto;
            display: block;
        }
        
        /* Default state styling — party delegation colors */
        .map-container svg .state path,
        .map-container svg .state circle {
            fill: #1a2035;
            stroke: #1a2035;
            stroke-width: 1.5;
            cursor: pointer;
            transition: fill 0.2s, opacity 0.2s;
        }

        .map-container svg .state path:hover,
        .map-container svg .state circle:hover {
            opacity: 0.85;
            stroke: #f0f2f8 !important;
            stroke-width: 1.5 !important;
        }

        /* Border lines */
        .map-container svg .borders path {
            pointer-events: none !important;
            stroke: #d9dde8 !important;
            stroke-width: 1.5 !important;
            fill: none !important;
        }

        /* State labels */
        .map-container svg .state-label {
            font-size: 10px;
            font-weight: 700;
            fill: #f0f2f8;
            text-anchor: middle;
            dominant-baseline: central;
            pointer-events: none;
            letter-spacing: 0.5px;
        }
        .map-container svg .state-label.small-state {
            font-size: 9px;
        }
        .map-container svg .label-line {
            stroke: #8892a8;
            stroke-width: 0.7;
            pointer-events: none;
            opacity: 0.6;
        }
        
        /* Tooltip — fixed center under map title */
        .map-tooltip {
            position: absolute;
            top: 8px;
            left: 50%;
            transform: translateX(-50%);
            background: #1a1a1a;
            border: 1px solid #5080a0;
            color: #e0e0e0;
            padding: 10px 15px;
            border-radius: 6px;
            font-size: 0.9em;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.2s ease;
            z-index: 100;
            white-space: nowrap;
        }
        
        .map-tooltip.visible {
            opacity: 1;
        }
        
        .map-tooltip .state-name {
            font-weight: bold;
            color: #62a4d0;
        }
        
        .map-tooltip .state-stats {
            color: #888;
            font-size: 0.85em;
            margin-top: 3px;
        }
        
        .map-tooltip .state-stats.coming-soon {
            color: #666;
            font-style: italic;
        }
        
        /* Back to USA button */
        .btn-back-usa {
            background: rgba(212, 175, 55, 0.15);
            border: 1px solid #d4af37;
            color: #d4af37;
            padding: 0.5rem 1.2rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.95rem;
            margin-bottom: 1rem;
            transition: all 0.2s;
        }
        .btn-back-usa:hover {
            background: rgba(212, 175, 55, 0.3);
        }
        
        /* SVG / Google Maps crossfade */
        #svgLayer, #gmapLayer {
            transition: opacity 0.5s ease;
        }
        #svgLayer.fading { opacity: 0; pointer-events: none; }
        #gmapLayer.fading { opacity: 0; }
        
        /* Google Maps layout */
        .gmap-body {
            display: flex;
            height: 500px;
            border: 1px solid #333;
            border-radius: 8px;
            overflow: hidden;
        }
        .gmap-sidebar {
            width: 300px;
            background: #12121f;
            border-right: 1px solid #333;
            overflow-y: auto;
            padding: 1rem;
            flex-shrink: 0;
        }
        .gmap-sidebar h3 {
            color: #d4af37;
            font-size: 0.95rem;
            margin-bottom: 0.5rem;
        }
        .gmap-sidebar p {
            color: #888;
            font-size: 0.85rem;
            margin-bottom: 0.5rem;
        }
        .gmap-sidebar .sidebar-section {
            margin-bottom: 1rem;
        }
        .gmap-input {
            width: 100%;
            padding: 0.6rem 0.75rem;
            font-size: 0.95rem;
            background: #0a0a0f;
            border: 1px solid #333;
            border-radius: 6px;
            color: #e0e0e0;
            margin-bottom: 0.5rem;
        }
        .gmap-input:focus {
            outline: none;
            border-color: #d4af37;
        }
        .gmap-input:disabled {
            color: #555;
        }
        
        /* OR divider */
        .or-divider {
            text-align: center;
            margin: 0.5rem 0;
            color: #666;
            font-size: 0.85rem;
        }
        
        /* Address lookup */
        .address-input-wrapper {
            display: flex;
            gap: 0.5rem;
        }
        .address-input-wrapper .gmap-input {
            flex: 1;
            margin-bottom: 0;
        }
        .btn-address-lookup {
            background: #5080a0;
            color: #fff;
            border: none;
            padding: 0.6rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            white-space: nowrap;
        }
        .btn-address-lookup:hover {
            background: #62a4d0;
        }
        .address-error {
            color: #e74c3c;
            font-size: 0.85rem;
            margin-top: 0.3rem;
            display: none;
        }
        .address-error.visible {
            display: block;
        }
        
        #googleMap {
            flex: 1;
            min-height: 400px;
        }
        
        /* Autocomplete in gmap sidebar */
        .gmap-sidebar .autocomplete-wrapper { position: relative; }
        .gmap-sidebar .autocomplete-list {
            position: absolute;
            top: 100%;
            left: 0; right: 0;
            background: #1a1a2e;
            border: 1px solid #d4af37;
            border-top: none;
            border-radius: 0 0 6px 6px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 50;
            display: none;
        }
        .gmap-sidebar .autocomplete-list .item {
            padding: 0.5rem 0.75rem;
            cursor: pointer;
            font-size: 0.9rem;
            border-bottom: 1px solid #2a2a3e;
        }
        .gmap-sidebar .autocomplete-list .item:hover {
            background: rgba(212,175,55,0.15);
            color: #d4af37;
        }
        
        /* Place card in gmap sidebar */
        .gmap-sidebar .place-card {
            background: #1a1a2e;
            border: 1px solid #333;
            border-radius: 8px;
            padding: 0.75rem;
            display: none;
            margin-bottom: 0.5rem;
        }
        .gmap-sidebar .place-card.visible { display: block; }
        .gmap-sidebar .place-card h4 { color: #d4af37; margin-bottom: 0.4rem; font-size: 0.9rem; }
        .gmap-sidebar .place-card .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 0.2rem 0;
            font-size: 0.8rem;
        }
        .gmap-sidebar .place-card .detail-row .label { color: #888; }
        .gmap-sidebar .place-card .detail-row .value { color: #e0e0e0; text-align: right; }
        
        /* Districts card in gmap sidebar */
        .gmap-sidebar .districts-card {
            background: #1a2a1a;
            border: 1px solid #2a4a2a;
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            display: none;
            margin-bottom: 0.5rem;
        }
        .gmap-sidebar .districts-card.visible { display: block; }
        .gmap-sidebar .districts-card h4 { color: #4caf50; font-size: 0.85rem; margin-bottom: 0.3rem; }
        .gmap-sidebar .district-row {
            display: flex;
            justify-content: space-between;
            padding: 0.2rem 0;
            font-size: 0.8rem;
        }
        .gmap-sidebar .district-row .label { color: #6a9a6a; }
        .gmap-sidebar .district-row .value { color: #e0e0e0; }
        
        /* Save button in gmap sidebar */
        .btn-save-location {
            width: 100%;
            padding: 0.65rem;
            margin-top: 0.5rem;
            font-size: 0.95rem;
            font-weight: 600;
            background: #d4af37;
            color: #000;
            border: none;
            border-radius: 6px;
            cursor: pointer;
        }
        .btn-save-location:hover { background: #e4bf47; }
        .btn-save-location:disabled { opacity: 0.5; cursor: not-allowed; }
        .street-view-link {
            display: block;
            text-align: center;
            margin-top: 0.5rem;
            color: #aaa;
            font-size: 0.85rem;
            text-decoration: none;
        }
        .street-view-link:hover { color: #d4af37; }
        .save-status {
            padding: 0.4rem 0.6rem;
            border-radius: 6px;
            font-size: 0.8rem;
            margin-top: 0.4rem;
            display: none;
        }
        .save-status.success { display: block; background: #1a3a1a; color: #4caf50; }
        .save-status.error { display: block; background: #3a1a1a; color: #e63946; }
        
        @media (max-width: 768px) {
            .gmap-body { flex-direction: column; height: auto; }
            .gmap-sidebar { width: 100%; max-height: 40vh; border-right: none; border-bottom: 1px solid #333; }
            #googleMap { min-height: 300px; }
        }
        
        /* State Info Modal - floats beside state on hover, draggable */
        .state-info-modal {
            display: none;
            position: absolute;
            background: #1a1a2e;
            border: 1px solid #d4af37;
            border-radius: 8px;
            padding: 20px;
            min-width: 280px;
            max-width: 320px;
            z-index: 1000;
            box-shadow: 0 4px 20px rgba(0,0,0,0.5);
            cursor: grab;
        }
        .state-info-modal.dragging {
            cursor: grabbing;
            opacity: 0.92;
        }
        
        .state-info-modal .modal-close-btn {
            position: absolute;
            top: 8px;
            right: 10px;
            background: none;
            border: none;
            color: #888;
            font-size: 1.5em;
            cursor: pointer;
            line-height: 1;
            padding: 0;
        }
        
        .state-info-modal .modal-close-btn:hover {
            color: #d4af37;
        }
        
        .state-info-modal.visible {
            display: block;
            animation: fadeIn 0.2s ease;
        }
        
        .state-info-modal h3 {
            color: #d4af37;
            margin: 0 0 15px 0;
            font-size: 1.3em;
            border-bottom: 1px solid #333;
            padding-bottom: 10px;
        }
        
        .state-info-modal .stat-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            border-bottom: 1px solid #222;
        }
        
        .state-info-modal .stat-row:last-child {
            border-bottom: none;
        }
        
        .state-info-modal .stat-label {
            color: #888;
        }
        
        .state-info-modal .stat-value {
            color: #e0e0e0;
            font-weight: 500;
        }
        
        .state-info-modal .voter-section {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #333;
        }
        
        .state-info-modal .voter-section h4 {
            color: #aaa;
            font-size: 0.9em;
            margin: 0 0 10px 0;
        }
        
        .state-info-modal .voter-bar {
            display: flex;
            height: 20px;
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 8px;
        }
        
        .state-info-modal .voter-bar .dem {
            background: #3b82f6;
        }
        
        .state-info-modal .voter-bar .rep {
            background: #ef4444;
        }
        
        .state-info-modal .voter-bar .ind {
            background: #a855f7;
        }
        
        .state-info-modal .voter-legend {
            display: flex;
            justify-content: space-between;
            font-size: 0.8em;
        }
        
        .state-info-modal .voter-legend span {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .state-info-modal .voter-legend .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        
        .state-info-modal .voter-legend .dot.dem { background: #3b82f6; }
        .state-info-modal .voter-legend .dot.rep { background: #ef4444; }
        .state-info-modal .voter-legend .dot.ind { background: #a855f7; }
        
        .state-info-modal .no-party-reg {
            color: #666;
            font-style: italic;
            font-size: 0.9em;
        }
        
        .state-info-modal .pop-pct {
            color: #888;
            font-size: 0.85em;
            font-weight: normal;
        }
        
        .state-info-modal .gov-party {
            font-size: 0.85em;
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 3px;
            margin-left: 5px;
        }
        
        .state-info-modal .gov-party.dem {
            background: #3b82f6;
            color: white;
        }
        
        .state-info-modal .gov-party.rep {
            background: #ef4444;
            color: white;
        }
        
        .state-info-modal .gov-party.ind {
            background: #a855f7;
            color: white;
        }
        
        .state-info-modal .modal-links {
            display: flex;
            gap: 15px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #333;
            justify-content: center;
        }
        
        .state-info-modal .modal-links a {
            color: #d4af37;
            font-size: 0.85em;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .state-info-modal .modal-links a:hover {
            text-decoration: underline;
        }
        
        .state-info-modal .modal-links a.hidden {
            display: none;
        }
        
        .state-info-modal .modal-actions {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #333;
            display: flex;
            gap: 10px;
        }
        
        .state-info-modal .btn-set-state {
            flex: 1;
            background: #5080a0;
            color: #fff;
            border: none;
            padding: 10px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.2s;
        }
        
        .state-info-modal .btn-set-state:hover {
            background: #62a4d0;
        }
        
        .state-info-modal .btn-view-state {
            flex: 1;
            background: transparent;
            color: #5080a0;
            border: 1px solid #5080a0;
            padding: 10px 15px;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .state-info-modal .btn-view-state:hover {
            background: rgba(80, 128, 160, 0.1);
        }
        
        /* Welcome Back section for returning users */
        .state-info-modal .welcome-back {
            display: none;
            text-align: center;
            padding: 10px 0;
        }
        .state-info-modal .welcome-back.visible {
            display: block;
        }
        .state-info-modal .welcome-back h4 {
            color: #d4af37;
            margin: 0 0 10px 0;
            font-size: 1.1em;
        }
        .state-info-modal .welcome-back .profile-summary {
            background: rgba(212, 175, 55, 0.1);
            border: 1px solid rgba(212, 175, 55, 0.3);
            border-radius: 8px;
            padding: 12px;
            margin: 10px 0;
        }
        .state-info-modal .welcome-back .profile-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 5px 0;
            font-size: 0.9em;
        }
        .state-info-modal .welcome-back .profile-row .icon {
            width: 20px;
            text-align: center;
        }
        .state-info-modal .welcome-back .btn-profile {
            display: inline-block;
            background: #d4af37;
            color: #1a1a2e;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            margin-top: 10px;
            text-decoration: none;
        }
        .state-info-modal .welcome-back .btn-profile:hover {
            background: #e4bf47;
        }
        .state-info-modal .welcome-back .skip-link {
            display: block;
            margin-top: 10px;
            color: #888;
            font-size: 0.85em;
            cursor: pointer;
        }
        .state-info-modal .welcome-back .skip-link:hover {
            color: #aaa;
        }
        
        /* Hide new user content when returning */
        .state-info-modal.returning-user .new-user-content {
            display: block; /* Show state info for returning users too */
        }
        .state-info-modal.returning-user .welcome-back {
            display: block;
        }
        /* Hide "This is My State" button for returning users - prevents overwriting location */
        .state-info-modal.returning-user .btn-set-state {
            display: none;
        }
        /* Make View State button full width when alone */
        .state-info-modal.returning-user .modal-actions {
            justify-content: center;
        }
        .state-info-modal.returning-user .btn-view-state {
            flex: none;
            padding: 10px 30px;
        }
        
        /* Map Legend */
        .map-legend {
            display: flex;
            justify-content: center;
            gap: 30px;
            margin-top: 30px;
            flex-wrap: wrap;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9em;
            color: #888;
        }
        
        .legend-color {
            width: 20px;
            height: 20px;
            border-radius: 3px;
        }
        
        .legend-color.dem {
            background: #2563eb;
        }

        .legend-color.mixed {
            background: #7c3aed;
        }

        .legend-color.rep {
            background: #dc2626;
        }

        .legend-color.nodata {
            background: #1a2035;
            border: 1px solid #444;
        }
        
        /* FOOTER */
        /* Responsive */
        @media (max-width: 768px) {
            .hero h1 {
                font-size: 2.4em;
            }
            
            .foundation {
                font-size: 1.05em;
            }
            
            .pyramid-row {
                flex-direction: column;
                align-items: center;
            }
            
            .pyramid-level {
                width: 100%;
                max-width: 280px;
            }
            
            .option-buttons {
                max-width: 100%;
            }
        }
    </style>
</head>
<body>
    <?php require 'includes/nav.php'; ?>

    <!-- Election 2026 Badge -->
    <a href="https://tpb.sandgems.net" class="election-badge" id="electionBadge" target="_blank">
        <span class="badge-text" id="badgeText">No Kings</span>
    </a>
    
    <style>
        .election-badge {
            position: fixed;
            top: 100px; /* fallback, JS will override */
            left: 15px;
            background: linear-gradient(135deg, #c41e3a 0%, #8b0000 100%);
            color: #fff;
            padding: 12px 20px;
            border-radius: 8px;
            font-family: 'Georgia', serif;
            font-size: 1.1rem;
            font-weight: bold;
            text-decoration: none;
            z-index: 99;
            box-shadow: 0 4px 15px rgba(196, 30, 58, 0.4);
            border: 2px solid #d4af37;
            min-width: 120px;
            text-align: center;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .election-badge:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 20px rgba(196, 30, 58, 0.6);
            text-decoration: none;
        }
        .badge-text {
            display: inline-block;
        }
        .badge-text.fade-out {
            opacity: 0;
            transition: opacity 0.3s;
        }
        .badge-text.fade-in {
            opacity: 1;
            transition: opacity 0.3s;
        }
        @media (max-width: 768px) {
            .election-badge {
                left: 10px;
                font-size: 0.95rem;
                padding: 10px 15px;
                min-width: 100px;
            }
        }
    </style>
    
    <script>
    (function() {
        // Position badge below nav dynamically
        const nav = document.querySelector('.top-nav');
        const badge = document.getElementById('electionBadge');
        function positionBadge() {
            if (nav && badge) {
                badge.style.top = (nav.offsetHeight + 10) + 'px';
            }
        }
        positionBadge();
        window.addEventListener('resize', positionBadge);
        
        const messages = ['No Kings', 'No Mobs', 'Act Now'];
        const badgeText = document.getElementById('badgeText');
        let currentIndex = 0;
        let rotationCount = 0;
        const maxRotations = 3; // 3 full rotations (9 changes)
        
        function rotateMessage() {
            // Fade out
            badgeText.classList.add('fade-out');
            badgeText.classList.remove('fade-in');
            
            setTimeout(() => {
                currentIndex = (currentIndex + 1) % messages.length;
                badgeText.textContent = messages[currentIndex];
                
                // Fade in
                badgeText.classList.remove('fade-out');
                badgeText.classList.add('fade-in');
                
                // Check if we've completed rotations and stopped on "No Mobs"
                if (currentIndex === 0) {
                    rotationCount++;
                }
                
                // After 3 rotations, stop on "No Mobs" (index 1)
                if (rotationCount >= maxRotations && currentIndex === 1) {
                    return; // Stop rotating
                }
                
                // Continue rotating
                setTimeout(rotateMessage, 2000);
            }, 300);
        }
        
        // Start rotation after 2 seconds
        setTimeout(rotateMessage, 2000);
    })();
    </script>

    <!-- HERO -->
    <section class="hero">
        <div class="hero-content">
            <div class="usa-badge">USA</div>
            <h1>A More <span>Perfect</span> Union</h1>
            <div class="foundation">
                <p>You are the <span class="highlight">Fourth Branch</span>.</p>
                <p>Nine branches of government serve you — from your town hall to the Capitol.</p>
                <p class="thought-power">Find your state. Make your voice heard.</p>
            </div>
        </div>
        <div class="scroll-hint" onclick="document.getElementById('structure').scrollIntoView({behavior: 'smooth'})">
            Scroll to explore
            <span>↓</span>
        </div>
    </section>
    
    <!-- STRUCTURE SECTION -->
    <section class="structure" id="structure">
        <div class="structure-inner">
            <h2>The Structure of Power</h2>
            <div class="pyramid">
                <div class="pyramid-level you">
                    <div class="label">★ You — The Fourth Branch ★</div>
                </div>
                <div class="pyramid-row">
                    <div class="pyramid-level">
                        <div class="label">Town</div>
                        <div class="branches">Legislative · Executive · Judicial</div>
                    </div>
                    <div class="pyramid-level">
                        <div class="label">State</div>
                        <div class="branches">Legislative · Executive · Judicial</div>
                    </div>
                    <div class="pyramid-level">
                        <div class="label">Nation</div>
                        <div class="branches">Legislative · Executive · Judicial</div>
                    </div>
                </div>
            </div>
            
            <p class="structure-note">No kings. Only citizens.<br>The Fourth Branch is not yet built. You are building it.</p>
        </div>
    </section>
    
    <!-- MAP SECTION -->
    <section class="map-section" id="map">
        <div id="mapAnchor"></div>
        <h2 class="usa-title" id="usaTitle">United States of America</h2>
        <p class="section-intro" id="mapIntro">Hover over a state for info. Click to select.</p>
        
        <!-- Back to USA button (hidden initially) -->
        <button class="btn-back-usa" id="btnBackUSA" style="display:none;">
            ← Back to USA
        </button>
        
        <div class="map-container" id="mapContainer">
            <!-- SVG layer -->
            <div class="map-tooltip" id="mapTooltip">
                <div class="state-name"></div>
                <div class="state-stats"></div>
            </div>
            
            <div id="svgLayer">
                <div id="mapHolder"></div>
            </div>
            
            <!-- State Info Modal - floats beside state on hover -->
            <div class="state-info-modal" id="stateInfoModal">
                <button class="modal-close-btn" id="modalCloseBtn">&times;</button>
                
                <!-- Welcome Back section (shown for returning users) -->
                <div class="welcome-back" id="welcomeBackSection">
                    <h4>Welcome back!</h4>
                    <div class="profile-summary">
                        <div class="profile-row">
                            <span class="icon">📍</span>
                            <span id="wbLocation">—</span>
                        </div>
                        <div class="profile-row">
                            <span class="icon">🏆</span>
                            <span id="wbPoints">0 civic points</span>
                        </div>
                        <div class="profile-row">
                            <span class="icon">✓</span>
                            <span id="wbLevel">Level 1: Anonymous</span>
                        </div>
                    </div>
                    <a href="/profile.php" class="btn-profile">Go to My Profile</a>
                </div>
                
                <!-- New user content (state info) -->
                <div class="new-user-content">
                    <h3 id="modalStateName">State Name</h3>
                    <div class="stat-row">
                        <span class="stat-label">Population</span>
                        <span class="stat-value"><span id="modalPopulation">-</span> <span id="modalPopPct" class="pop-pct"></span></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">Capital</span>
                        <span class="stat-value" id="modalCapital">-</span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">Largest City</span>
                        <span class="stat-value" id="modalLargestCity">-</span>
                    </div>
                    <div class="stat-row" id="modalGovernorRow">
                        <span class="stat-label">Governor</span>
                        <span class="stat-value"><span id="modalGovernor">-</span> <span id="modalGovParty" class="gov-party"></span></span>
                    </div>
                    <div class="voter-section" id="modalVoterSection">
                        <h4>Registered Voters by Party</h4>
                        <div class="voter-bar" id="modalVoterBar">
                            <div class="dem" id="voterBarDem"></div>
                            <div class="rep" id="voterBarRep"></div>
                            <div class="ind" id="voterBarInd"></div>
                        </div>
                        <div class="voter-legend">
                            <span><span class="dot dem"></span> <span id="modalDemPct">-</span></span>
                            <span><span class="dot rep"></span> <span id="modalRepPct">-</span></span>
                            <span><span class="dot ind"></span> <span id="modalIndPct">-</span></span>
                        </div>
                    </div>
                    <div class="no-party-reg" id="modalNoPartyReg" style="display:none;">
                        This state doesn't register voters by party
                    </div>
                    <div class="modal-links" id="modalLinks">
                        <a href="#" id="linkLegislature" target="_blank" rel="noopener">🏛️ Legislature</a>
                        <a href="#" id="linkGovernor" target="_blank" rel="noopener">👤 Governor</a>
                    </div>
                    <div class="modal-actions">
                        <button class="btn-set-state" id="btnSetState">This is My State</button>
                        <button class="btn-view-state" id="btnViewState">View State</button>
                    </div>
                </div>
            </div>
            
            <!-- Google Maps layer (hidden initially) -->
            <div id="gmapLayer" style="display:none;">
                <div class="gmap-body">
                    <!-- Sidebar -->
                    <div class="gmap-sidebar" id="gmapSidebar">
                        <div class="sidebar-section">
                            <h3 id="gmapStateName">Connecticut</h3>
                            <p id="gmapInstruction">Enter your address below, or select your town and click the map to drop a pin.</p>
                        </div>
                        
                        <!-- Town autocomplete -->
                        <div class="sidebar-section">
                            <h3>Town</h3>
                            <div class="autocomplete-wrapper">
                                <input type="text" id="gmapTownInput" class="gmap-input" placeholder="Start typing town name..." autocomplete="one-time-code" name="tpb_town_search_nofill" data-lpignore="true" data-form-type="other" role="combobox">
                                <div id="gmapTownList" class="autocomplete-list"></div>
                            </div>
                        </div>
                        
                        <!-- OR Address lookup -->
                        <div class="sidebar-section">
                            <div class="or-divider"><span>— or —</span></div>
                            <h3>Enter Address</h3>
                            <div class="address-input-wrapper">
                                <input type="text" id="gmapAddressLookup" class="gmap-input" placeholder="123 Main St, Putnam CT" autocomplete="one-time-code" name="tpb_addr_nofill" data-lpignore="true" data-form-type="other">
                                <button id="gmapAddressBtn" class="btn-address-lookup">Find</button>
                            </div>
                            <div id="gmapAddressError" class="address-error"></div>
                        </div>
                        
                        <!-- Place card -->
                        <div id="gmapPlaceCard" class="place-card">
                            <h4>📍 Your Location</h4>
                            <div class="detail-row"><span class="label">Address</span><span class="value" id="gmapPcAddress">—</span></div>
                            <div class="detail-row"><span class="label">Town</span><span class="value" id="gmapPcTown">—</span></div>
                            <div class="detail-row"><span class="label">ZIP</span><span class="value" id="gmapPcZip">—</span></div>
                            <div class="detail-row"><span class="label">County</span><span class="value" id="gmapPcCounty">—</span></div>
                        </div>
                        
                        <!-- Districts card -->
                        <div id="gmapDistrictsCard" class="districts-card">
                            <h4>🏛️ Your Districts</h4>
                            <div class="district-row"><span class="label">US Congress</span><span class="value" id="gmapDcCongress">—</span></div>
                            <div class="district-row"><span class="label">State Senate</span><span class="value" id="gmapDcSenate">—</span></div>
                            <div class="district-row"><span class="label">State House</span><span class="value" id="gmapDcHouse">—</span></div>
                        </div>
                        
                        <!-- Go to Profile button -->
                        <button id="gmapGoProfileBtn" class="btn btn-primary btn-save-location" style="display:none;">
                            → Create Account
                        </button>
                        
                        <!-- Street View link -->
                        <a id="gmapStreetViewLink" href="#" target="_blank" rel="noopener" class="street-view-link" style="display:none;">
                            👁 Street View
                        </a>
                    </div>
                    
                    <!-- Google Map -->
                    <div id="googleMap"></div>
                </div>
            </div>
        </div>
        
        <div class="map-legend" id="mapLegend">
            <div class="legend-item">
                <div class="legend-color dem"></div>
                <span>All Democrat</span>
            </div>
            <div class="legend-item">
                <div class="legend-color mixed"></div>
                <span>Mixed</span>
            </div>
            <div class="legend-item">
                <div class="legend-color rep"></div>
                <span>All Republican</span>
            </div>
            <div class="legend-item">
                <div class="legend-color nodata"></div>
                <span>No data</span>
            </div>
        </div>
    </section>
    
    <?php require 'includes/footer.php'; ?>
    
    <script>
    (function() {
        // Start user at top of page
        window.scrollTo(0, 0);
        
        // =====================================================
        // CONFIG
        // =====================================================
        const CONFIG = {
            apiBase: 'api',
            page: 'index',
            debug: false,
            userId: <?= $dbUser ? (int)$dbUser['user_id'] : 'null' ?>,
            userLat: <?= ($dbUser && $dbUser['latitude']) ? (float)$dbUser['latitude'] : 'null' ?>,
            userLng: <?= ($dbUser && $dbUser['longitude']) ? (float)$dbUser['longitude'] : 'null' ?>,
            userState: '<?= htmlspecialchars($dbUser['state_abbrev'] ?? '') ?>'.toUpperCase(),
            userTown: '<?= htmlspecialchars(addslashes($dbUser['town_name'] ?? '')) ?>'
        };
        
        // User state for smart dialog
        const USER = {
            level: <?= $dbUser ? (int)$dbUser['identity_level_id'] : 0 ?>,
            levelName: '<?= htmlspecialchars($dbUser['identity_level_name'] ?? 'anonymous') ?>',
            stateAbbr: '<?= htmlspecialchars($dbUser['state_abbrev'] ?? '') ?>'.toUpperCase(),
            townName: '<?= htmlspecialchars(addslashes($dbUser['town_name'] ?? '')) ?>',
            email: '<?= htmlspecialchars($dbUser['email'] ?? '') ?>',
            emailVerified: <?= ($dbUser['email_verified'] ?? 0) ? 'true' : 'false' ?>,
            points: <?= $dbUser ? (int)$dbUser['civic_points'] : (int)$sessionPoints ?>,
            isReturning: <?= ($dbUser && $dbUser['email_verified']) ? 'true' : 'false' ?>
        };
        
        const GOOGLE_API_KEY = 'AIzaSyBbppmpBODtUtMOx5E9RlZMNrSD44PFZnM';
        
        // Active states (from database)
        const activeStates = <?= json_encode(array_map('strtoupper', $activeStates)) ?>;

        // Delegation data for map coloring
        const stateData = <?= $stateDataJson ?>;

        // State names
        const stateNames = {
            'al': 'Alabama', 'ak': 'Alaska', 'az': 'Arizona', 'ar': 'Arkansas', 'ca': 'California',
            'co': 'Colorado', 'ct': 'Connecticut', 'de': 'Delaware', 'fl': 'Florida', 'ga': 'Georgia',
            'hi': 'Hawaii', 'id': 'Idaho', 'il': 'Illinois', 'in': 'Indiana', 'ia': 'Iowa',
            'ks': 'Kansas', 'ky': 'Kentucky', 'la': 'Louisiana', 'me': 'Maine', 'md': 'Maryland',
            'ma': 'Massachusetts', 'mi': 'Michigan', 'mn': 'Minnesota', 'ms': 'Mississippi', 'mo': 'Missouri',
            'mt': 'Montana', 'ne': 'Nebraska', 'nv': 'Nevada', 'nh': 'New Hampshire', 'nj': 'New Jersey',
            'nm': 'New Mexico', 'ny': 'New York', 'nc': 'North Carolina', 'nd': 'North Dakota', 'oh': 'Ohio',
            'ok': 'Oklahoma', 'or': 'Oregon', 'pa': 'Pennsylvania', 'ri': 'Rhode Island', 'sc': 'South Carolina',
            'sd': 'South Dakota', 'tn': 'Tennessee', 'tx': 'Texas', 'ut': 'Utah', 'vt': 'Vermont',
            'va': 'Virginia', 'wa': 'Washington', 'wv': 'West Virginia', 'wi': 'Wisconsin', 'wy': 'Wyoming',
            'dc': 'District of Columbia'
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
        
        // State data cache
        let stateDataCache = {};
        let hoverTimer = null;
        let currentHoverState = null;
        const HOVER_DELAY = 1000;
        
        // Current mode: 'svg' or 'gmap'
        let mapMode = 'svg';
        
        // Google Maps state
        let gmap, geocoder, marker;
        let gmapSelectedState = '';
        let gmapSelectedTown = '';
        let gmapTowns = [];
        let gmapLocationData = null;
        let gmapLoaded = false;
        
        // =====================================================
        // SESSION MANAGEMENT
        // =====================================================
        let sessionId = localStorage.getItem('tpb_civic_session');
        if (!sessionId) {
            sessionId = 'civic_' + Math.random().toString(36).substr(2, 9) + '_' + Date.now();
            localStorage.setItem('tpb_civic_session', sessionId);
        }
        document.cookie = 'tpb_civic_session=' + sessionId + '; path=/; max-age=31536000';
        
        let civicPoints = <?= $points ?>;
        const lastState = <?= json_encode($lastState) ?>;
        const lastTown = <?= json_encode($lastTown) ?>;
        const hasProfileLocation = <?= json_encode($hasProfileLocation) ?>;
        
        // =====================================================
        // CIVIC POINTS
        // =====================================================
        function updatePointsDisplay(newTotal, earned) {
            if (newTotal > civicPoints) {
                civicPoints = newTotal;
                if (window.tpbUpdateNavPoints) window.tpbUpdateNavPoints(newTotal);
            }
        }
        
        async function logCivicAction(actionType, elementId, extraData) {
            try {
                const response = await fetch(CONFIG.apiBase + '/log-civic-click.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action_type: actionType,
                        page_name: CONFIG.page,
                        element_id: elementId || null,
                        session_id: sessionId,
                        timestamp: new Date().toISOString(),
                        ...(extraData || {})
                    })
                });
                const data = await response.json();
                if (data.session && data.session.total_points) {
                    updatePointsDisplay(data.session.total_points, data.points_earned);
                }
            } catch (e) { /* non-critical */ }
        }
        
        // =====================================================
        // SVG → GOOGLE MAPS CROSSFADE
        // =====================================================
        function transitionToGoogleMaps(stateCode) {
            const stateName = stateNames[stateCode] || stateCode.toUpperCase();
            const stateUpper = stateCode.toUpperCase();
            gmapSelectedState = stateUpper;
            gmapSelectedTown = '';
            
            // Update header
            document.getElementById('usaTitle').textContent = stateName;
            document.getElementById('mapIntro').textContent = 'Click the map to drop your pin.';
            document.getElementById('btnBackUSA').style.display = '';
            document.getElementById('mapLegend').style.display = 'none';
            document.getElementById('gmapStateName').textContent = stateName;
            document.getElementById('gmapInstruction').textContent = 'Type your town name, then click the map to drop your pin.';
            
            // Fade out SVG
            const svgLayer = document.getElementById('svgLayer');
            const gmapLayer = document.getElementById('gmapLayer');
            
            svgLayer.classList.add('fading');
            
            setTimeout(function() {
                svgLayer.style.display = 'none';
                gmapLayer.style.display = '';
                gmapLayer.classList.remove('fading');
                
                // Initialize or reposition Google Maps
                if (!gmapLoaded) {
                    loadGoogleMaps(stateUpper);
                } else {
                    const center = stateCenters[stateUpper] || { lat: 39.8, lng: -98.6 };
                    gmap.setCenter(center);
                    gmap.setZoom(7);
                    // Clear existing marker
                    if (marker) { marker.setMap(null); marker = null; }
                    resetGmapSidebar();
                }
                
                // Load towns for this state
                loadGmapTowns(stateUpper);
                
                mapMode = 'gmap';
                
                // Scroll to map anchor so user sees state title at top
                document.getElementById('mapAnchor').scrollIntoView({ behavior: 'smooth' });
            }, 500);
            
            // Log
            logCivicAction('state_click', stateCode, { state_code: stateUpper, state_name: stateName });
        }
        
        function transitionToSVG() {
            const svgLayer = document.getElementById('svgLayer');
            const gmapLayer = document.getElementById('gmapLayer');
            
            gmapLayer.classList.add('fading');
            
            setTimeout(function() {
                gmapLayer.style.display = 'none';
                svgLayer.style.display = '';
                svgLayer.classList.remove('fading');
                
                // Restore header
                document.getElementById('usaTitle').textContent = 'United States of America';
                document.getElementById('mapIntro').textContent = 'Hover over a state for info. Click to select.';
                document.getElementById('btnBackUSA').style.display = 'none';
                document.getElementById('mapLegend').style.display = '';
                
                mapMode = 'svg';
            }, 500);
        }
        
        // Back button
        document.getElementById('btnBackUSA').addEventListener('click', transitionToSVG);
        
        // =====================================================
        // GOOGLE MAPS INITIALIZATION
        // =====================================================
        function loadGoogleMaps(stateCode) {
            const script = document.createElement('script');
            script.src = 'https://maps.googleapis.com/maps/api/js?key=' + GOOGLE_API_KEY + '&callback=onGoogleMapsReady';
            script.async = true;
            script.defer = true;
            document.head.appendChild(script);
            
            // Store which state to zoom to
            window._gmapInitState = stateCode;
        }
        
        window.onGoogleMapsReady = function() {
            gmapLoaded = true;
            const stateCode = window._gmapInitState || 'CT';
            const center = stateCenters[stateCode] || { lat: 39.8, lng: -98.6 };
            
            gmap = new google.maps.Map(document.getElementById('googleMap'), {
                center: center,
                zoom: 7,
                colorScheme: google.maps.ColorScheme.DARK,
                disableDefaultUI: false,
                zoomControl: true,
                mapTypeControl: false,
                streetViewControl: true,
                fullscreenControl: true
            });
            
            geocoder = new google.maps.Geocoder();
            
            gmap.addListener('click', function(e) {
                dropGmapPin(e.latLng);
            });
        };
        
        // =====================================================
        // GOOGLE MAPS: TOWN AUTOCOMPLETE
        // =====================================================
        function loadGmapTowns(stateCode) {
            fetch('/api/zip-lookup.php?action=get_state_towns&state_code=' + stateCode)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.status === 'success') {
                        gmapTowns = data.data;
                    }
                });
        }
        
        (function() {
            const input = document.getElementById('gmapTownInput');
            const list = document.getElementById('gmapTownList');
            let timer;
            
            input.addEventListener('input', function() {
                clearTimeout(timer);
                const val = this.value.trim().toLowerCase();
                if (val.length < 2) { list.style.display = 'none'; return; }
                
                timer = setTimeout(function() {
                    const matches = gmapTowns.filter(function(t) {
                        return t.toLowerCase().indexOf(val) !== -1;
                    }).slice(0, 15);
                    
                    list.innerHTML = '';
                    if (!matches.length) { list.style.display = 'none'; return; }
                    
                    matches.forEach(function(t) {
                        const div = document.createElement('div');
                        div.className = 'item';
                        div.textContent = t;
                        div.addEventListener('click', function() { selectGmapTown(t); });
                        list.appendChild(div);
                    });
                    list.style.display = 'block';
                }, 150);
            });
            
            input.addEventListener('blur', function() {
                setTimeout(function() { list.style.display = 'none'; }, 200);
            });
        })();
        
        function selectGmapTown(townName) {
            gmapSelectedTown = townName;
            document.getElementById('gmapTownInput').value = townName;
            document.getElementById('gmapTownList').style.display = 'none';
            
            // Zoom to town
            fetch('/api/zip-lookup.php?action=get_coords&town=' + encodeURIComponent(townName) + '&state=' + gmapSelectedState)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.status === 'success' && data.data) {
                        const lat = parseFloat(data.data.latitude);
                        const lng = parseFloat(data.data.longitude);
                        if (!isNaN(lat) && !isNaN(lng)) {
                            gmap.setCenter({ lat: lat, lng: lng });
                            gmap.setZoom(14);
                        }
                    }
                });
        }
        
        // =====================================================
        // GOOGLE MAPS: PIN DROP + REVERSE GEOCODE
        // =====================================================
        function dropGmapPin(latLng) {
            if (marker) {
                marker.setPosition(latLng);
            } else {
                marker = new google.maps.Marker({
                    position: latLng,
                    map: gmap,
                    draggable: true,
                    animation: google.maps.Animation.DROP
                });
                marker.addListener('dragend', function() {
                    reverseGeocode(marker.getPosition());
                    lookupDistricts(marker.getPosition());
                });
            }
            reverseGeocode(latLng);
            lookupDistricts(latLng);
        }
        
        function reverseGeocode(latLng) {
            const lat = latLng.lat().toFixed(6);
            const lng = latLng.lng().toFixed(6);
            
            gmapLocationData = {
                latitude: lat,
                longitude: lng,
                state_code: gmapSelectedState,
                town_name: gmapSelectedTown
            };
            
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
                        if (!town && c.types.indexOf('sublocality') !== -1) town = c.long_name;
                    });
                    
                    document.getElementById('gmapPcAddress').textContent = address;
                    document.getElementById('gmapPcTown').textContent = town || gmapSelectedTown || '—';
                    document.getElementById('gmapPcZip').textContent = zip || '—';
                    document.getElementById('gmapPcCounty').textContent = county || '—';
                    document.getElementById('gmapPlaceCard').classList.add('visible');
                    
                    // Also populate the address input field
                    document.getElementById('gmapAddressLookup').value = address;
                    
                    gmapLocationData.address = address;
                    gmapLocationData.town_name = town || gmapSelectedTown;
                    gmapLocationData.state_code = state || gmapSelectedState;
                    gmapLocationData.zip_code = zip;
                    
                    // Show Go to Profile button and Street View link
                    document.getElementById('gmapGoProfileBtn').style.display = 'block';
                    const svLink = document.getElementById('gmapStreetViewLink');
                    svLink.href = 'https://www.google.com/maps?layer=c&cbll=' + gmapLocationData.latitude + ',' + gmapLocationData.longitude;
                    svLink.style.display = 'block';
                }
            });
        }
        
        // =====================================================
        // GOOGLE MAPS: FORWARD GEOCODE (Address → LatLng)
        // =====================================================
        function forwardGeocode(address) {
            const errorEl = document.getElementById('gmapAddressError');
            errorEl.classList.remove('visible');
            
            if (!geocoder) {
                errorEl.textContent = 'Map not ready. Please wait.';
                errorEl.classList.add('visible');
                return;
            }
            
            // Add town AND state to improve accuracy
            let searchAddress = address;
            if (gmapSelectedTown) {
                searchAddress += ', ' + gmapSelectedTown;
            }
            if (gmapSelectedState) {
                searchAddress += ', ' + gmapSelectedState;
            }
            
            geocoder.geocode({ address: searchAddress }, function(results, status) {
                if (status === 'OK' && results[0]) {
                    const location = results[0].geometry.location;
                    const components = results[0].address_components;
                    
                    // Check if result is in the selected town
                    let resultTown = '';
                    let resultState = '';
                    components.forEach(function(c) {
                        if (c.types.indexOf('locality') !== -1) resultTown = c.long_name;
                        if (c.types.indexOf('administrative_area_level_1') !== -1) resultState = c.short_name;
                    });
                    
                    // Validate town matches (if town was selected)
                    if (gmapSelectedTown && resultTown && 
                        resultTown.toLowerCase() !== gmapSelectedTown.toLowerCase()) {
                        errorEl.textContent = 'Address found in ' + resultTown + ', not ' + gmapSelectedTown + '. Check the address or select a different town.';
                        errorEl.classList.add('visible');
                        return;
                    }
                    
                    // Place or move marker
                    if (marker) {
                        marker.setPosition(location);
                    } else {
                        marker = new google.maps.Marker({
                            position: location,
                            map: gmap,
                            draggable: true,
                            title: 'Your location'
                        });
                        
                        // Allow dragging to adjust
                        marker.addListener('dragend', function() {
                            reverseGeocode(marker.getPosition());
                            lookupDistricts(marker.getPosition());
                        });
                    }
                    
                    // Zoom to street level
                    gmap.setCenter(location);
                    gmap.setZoom(17);
                    
                    // Get full details via reverse geocode
                    reverseGeocode(location);
                    lookupDistricts(location);
                    
                    // Update instruction
                    document.getElementById('gmapInstruction').textContent = 'Drag the pin to adjust if needed.';
                    
                } else {
                    errorEl.textContent = 'Address not found. Try a more specific address.';
                    errorEl.classList.add('visible');
                }
            });
        }
        
        // Address lookup button handler
        document.getElementById('gmapAddressBtn').addEventListener('click', function() {
            const address = document.getElementById('gmapAddressLookup').value.trim();
            if (address) {
                forwardGeocode(address);
            }
        });
        
        // Also allow Enter key in address field
        document.getElementById('gmapAddressLookup').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const address = this.value.trim();
                if (address) {
                    forwardGeocode(address);
                }
            }
        });
        
        // =====================================================
        // GOOGLE MAPS: DISTRICT LOOKUP
        // =====================================================
        function lookupDistricts(latLng) {
            const addr = latLng.lat().toFixed(6) + ',' + latLng.lng().toFixed(6);
            const url = 'https://www.googleapis.com/civicinfo/v2/representatives?address=' + encodeURIComponent(addr) + '&key=' + GOOGLE_API_KEY;
            
            fetch(url)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.divisions) return;
                    let congress = '—', senate = '—', house = '—';
                    
                    Object.keys(data.divisions).forEach(function(ocdId) {
                        if (ocdId.indexOf('/cd:') !== -1) { const m = ocdId.match(/\/cd:(\d+)/); if (m) congress = 'District ' + m[1]; }
                        if (ocdId.indexOf('/sldl:') !== -1) { const m = ocdId.match(/\/sldl:(\d+)/); if (m) house = 'District ' + m[1]; }
                        if (ocdId.indexOf('/sldu:') !== -1) { const m = ocdId.match(/\/sldu:(\d+)/); if (m) senate = 'District ' + m[1]; }
                    });
                    
                    document.getElementById('gmapDcCongress').textContent = congress;
                    document.getElementById('gmapDcSenate').textContent = senate;
                    document.getElementById('gmapDcHouse').textContent = house;
                    document.getElementById('gmapDistrictsCard').classList.add('visible');
                    
                    gmapLocationData.us_congress_district = congress;
                    gmapLocationData.state_senate_district = senate;
                    gmapLocationData.state_house_district = house;
                })
                .catch(function() {});
        }
        
        // =====================================================
        // GOOGLE MAPS: GO TO PROFILE
        // =====================================================
        document.getElementById('gmapGoProfileBtn').addEventListener('click', function() {
            if (!gmapLocationData) return;
            
            // Build join.php URL with location data from map
            const params = new URLSearchParams({
                state_code: gmapLocationData.state_code || '',
                town_name: gmapLocationData.town_name || '',
                zip_code: gmapLocationData.zip_code || '',
                lat: gmapLocationData.latitude || '',
                lng: gmapLocationData.longitude || '',
                county: gmapLocationData.county || '',
                us_congress_district: gmapLocationData.us_congress_district || '',
                state_senate_district: gmapLocationData.state_senate_district || '',
                state_house_district: gmapLocationData.state_house_district || ''
            });
            window.location.href = '/join.php?' + params.toString();
        });
        
        function resetGmapSidebar() {
            document.getElementById('gmapTownInput').value = '';
            document.getElementById('gmapPlaceCard').classList.remove('visible');
            document.getElementById('gmapDistrictsCard').classList.remove('visible');
            document.getElementById('gmapGoProfileBtn').style.display = 'none';
            document.getElementById('gmapStreetViewLink').style.display = 'none';
            gmapLocationData = null;
        }
        
        // =====================================================
        // STATE INFO MODAL (unchanged from index.php)
        // =====================================================
        const stateInfoModal = document.getElementById('stateInfoModal');
        
        function clearHoverTimer() {
            if (hoverTimer) { clearTimeout(hoverTimer); hoverTimer = null; }
            currentHoverState = null;
        }
        
        function hideStateInfoModal() {
            stateInfoModal.classList.remove('visible');
        }
        
        function showStateInfoModal(stateClass, mouseX, mouseY) {
            const stateCode = stateClass.toUpperCase();
            const stateName = stateNames[stateClass] || stateCode;
            
            // Position modal
            stateInfoModal.style.left = (mouseX + 20) + 'px';
            stateInfoModal.style.top = (mouseY - 50) + 'px';
            stateInfoModal.dataset.stateCode = stateCode;
            
            // Set state name for both user types
            document.getElementById('modalStateName').textContent = stateName;
            
            // Restore state-level labels (USA modal changes these)
            document.getElementById('modalGovernorRow').querySelector('.stat-label').textContent = 'Governor';
            document.getElementById('btnSetState').style.display = '';
            document.getElementById('btnViewState').style.display = '';
            
            // Fetch and show state data for ALL users
            if (stateDataCache[stateCode]) {
                populateModal(stateDataCache[stateCode]);
            } else {
                fetch('/api/get-state-info.php?state=' + stateCode)
                    .then(r => r.json())
                    .then(data => {
                        stateDataCache[stateCode] = data;
                        populateModal(data);
                    })
                    .catch(() => {});
            }
            
            // Check if returning user - show welcome section too
            if (USER.isReturning) {
                stateInfoModal.classList.add('returning-user');
                
                // Populate welcome back data
                const location = USER.townName 
                    ? USER.townName + ', ' + USER.stateAbbr
                    : USER.stateAbbr || 'Location not set';
                document.getElementById('wbLocation').textContent = location;
                document.getElementById('wbPoints').textContent = USER.points + ' civic points';
                document.getElementById('wbLevel').textContent = 'Level ' + USER.level + ': ' + 
                    USER.levelName.charAt(0).toUpperCase() + USER.levelName.slice(1);
            } else {
                stateInfoModal.classList.remove('returning-user');
            }
            
            stateInfoModal.classList.add('visible');
        }
        
        function populateModal(data) {
            const pop = data.population;
            document.getElementById('modalPopulation').textContent = pop ? pop.toLocaleString() : '—';
            
            const usPop = 331900000;
            if (pop) {
                document.getElementById('modalPopPct').textContent = '(' + ((pop / usPop) * 100).toFixed(1) + '% of US)';
            } else {
                document.getElementById('modalPopPct').textContent = '';
            }
            
            document.getElementById('modalCapital').textContent = data.capital_city || '—';
            
            const largest = data.largest_city || '—';
            const largestPop = data.largest_city_population;
            document.getElementById('modalLargestCity').textContent = largest + (largestPop ? ' (' + largestPop.toLocaleString() + ')' : '');
            
            // Governor
            if (data.governor_name) {
                document.getElementById('modalGovernor').textContent = data.governor_name;
                const party = data.governor_party || '';
                const partyEl = document.getElementById('modalGovParty');
                partyEl.textContent = party ? '(' + party + ')' : '';
                partyEl.className = 'gov-party' + (party === 'D' ? ' dem' : party === 'R' ? ' rep' : '');
                document.getElementById('modalGovernorRow').style.display = '';
            } else {
                document.getElementById('modalGovernorRow').style.display = 'none';
            }
            
            // Voter registration
            const dem = data.voters_democrat;
            const rep = data.voters_republican;
            const ind = data.voters_independent;
            
            if (dem || rep || ind) {
                const total = (dem || 0) + (rep || 0) + (ind || 0);
                if (total > 0) {
                    const demPct = ((dem || 0) / total * 100).toFixed(1);
                    const repPct = ((rep || 0) / total * 100).toFixed(1);
                    const indPct = ((ind || 0) / total * 100).toFixed(1);
                    
                    document.getElementById('voterBarDem').style.width = demPct + '%';
                    document.getElementById('voterBarRep').style.width = repPct + '%';
                    document.getElementById('voterBarInd').style.width = indPct + '%';
                    document.getElementById('modalDemPct').textContent = demPct + '% Dem';
                    document.getElementById('modalRepPct').textContent = repPct + '% Rep';
                    document.getElementById('modalIndPct').textContent = indPct + '% Ind';
                    
                    document.getElementById('modalVoterSection').style.display = '';
                    document.getElementById('modalNoPartyReg').style.display = 'none';
                } else {
                    document.getElementById('modalVoterSection').style.display = 'none';
                    document.getElementById('modalNoPartyReg').style.display = '';
                }
            } else {
                document.getElementById('modalVoterSection').style.display = 'none';
                document.getElementById('modalNoPartyReg').style.display = '';
            }
            
            // Links
            if (data.legislature_url) {
                document.getElementById('linkLegislature').href = data.legislature_url;
                document.getElementById('linkLegislature').style.display = '';
            } else {
                document.getElementById('linkLegislature').style.display = 'none';
            }
            if (data.governor_url) {
                document.getElementById('linkGovernor').href = data.governor_url;
                document.getElementById('linkGovernor').style.display = '';
            } else {
                document.getElementById('linkGovernor').style.display = 'none';
            }
        }
        
        // Modal close
        document.getElementById('modalCloseBtn').addEventListener('click', hideStateInfoModal);

        // Modal drag — let users reposition when it covers content
        (function() {
            var modal = document.getElementById('stateInfoModal');
            var dragging = false, startX, startY, origLeft, origTop;

            modal.addEventListener('mousedown', function(e) {
                // Don't drag from buttons/links/inputs
                if (e.target.closest('button, a, input')) return;
                dragging = true;
                startX = e.clientX;
                startY = e.clientY;
                origLeft = parseInt(modal.style.left) || 0;
                origTop = parseInt(modal.style.top) || 0;
                modal.classList.add('dragging');
                e.preventDefault();
            });

            document.addEventListener('mousemove', function(e) {
                if (!dragging) return;
                modal.style.left = (origLeft + e.clientX - startX) + 'px';
                modal.style.top = (origTop + e.clientY - startY) + 'px';
            });

            document.addEventListener('mouseup', function() {
                if (dragging) {
                    dragging = false;
                    modal.classList.remove('dragging');
                }
            });

            // Touch support for mobile
            modal.addEventListener('touchstart', function(e) {
                if (e.target.closest('button, a, input')) return;
                dragging = true;
                var t = e.touches[0];
                startX = t.clientX;
                startY = t.clientY;
                origLeft = parseInt(modal.style.left) || 0;
                origTop = parseInt(modal.style.top) || 0;
                modal.classList.add('dragging');
            }, {passive: true});

            document.addEventListener('touchmove', function(e) {
                if (!dragging) return;
                var t = e.touches[0];
                modal.style.left = (origLeft + t.clientX - startX) + 'px';
                modal.style.top = (origTop + t.clientY - startY) + 'px';
            }, {passive: true});

            document.addEventListener('touchend', function() {
                if (dragging) {
                    dragging = false;
                    modal.classList.remove('dragging');
                }
            });
        })();

        // Click outside map/modal → close modal
        document.addEventListener('click', function(e) {
            const modal = document.getElementById('stateInfoModal');
            const mapContainer = document.getElementById('mapContainer');
            const usaTitle = document.getElementById('usaTitle');
            if (!modal.contains(e.target) && !mapContainer.contains(e.target) && e.target !== usaTitle) {
                hideStateInfoModal();
            }
        });
        
        // USA title hover - shows national stats
        const usaTitleEl = document.getElementById('usaTitle');
        let usaTitleTimer = null;
        
        usaTitleEl.addEventListener('mouseenter', () => {
            usaTitleTimer = setTimeout(() => {
                const stateCode = 'USA';
                
                // Check cache first
                if (stateDataCache[stateCode]) {
                    showUSAModal(stateDataCache[stateCode]);
                } else {
                    fetch('/api/get-state-info.php?state=USA')
                        .then(r => r.json())
                        .then(data => {
                            stateDataCache[stateCode] = data;
                            showUSAModal(data);
                        })
                        .catch(() => {});
                }
            }, HOVER_DELAY);
        });
        
        usaTitleEl.addEventListener('mouseleave', () => {
            if (usaTitleTimer) {
                clearTimeout(usaTitleTimer);
                usaTitleTimer = null;
            }
        });
        
        function showUSAModal(data) {
            document.getElementById('modalStateName').textContent = 'United States of America';
            stateInfoModal.dataset.stateCode = 'USA';
            
            // Population
            const pop = data.population;
            document.getElementById('modalPopulation').textContent = pop ? pop.toLocaleString() : '—';
            document.getElementById('modalPopPct').textContent = '';
            
            // Capital & Largest City
            document.getElementById('modalCapital').textContent = data.capital_city || 'Washington, D.C.';
            const largest = data.largest_city || 'New York City';
            const largestPop = data.largest_city_population;
            document.getElementById('modalLargestCity').textContent = largest + (largestPop ? ' (' + largestPop.toLocaleString() + ')' : '');
            
            // President instead of Governor
            const govRow = document.getElementById('modalGovernorRow');
            if (data.governor_name) {
                document.getElementById('modalGovernor').textContent = data.governor_name;
                const partyEl = document.getElementById('modalGovParty');
                partyEl.textContent = data.governor_party === 'Republican' ? 'R' : data.governor_party;
                partyEl.className = 'gov-party rep';
                govRow.style.display = '';
                govRow.querySelector('.stat-label').textContent = 'President';
            } else {
                govRow.style.display = 'none';
            }
            
            // Voter registration bars
            if (data.voters_democrat) {
                const total = data.voters_democrat + data.voters_republican + data.voters_independent;
                if (total > 0) {
                    const demPct = Math.round((data.voters_democrat / total) * 100);
                    const repPct = Math.round((data.voters_republican / total) * 100);
                    const indPct = Math.round((data.voters_independent / total) * 100);
                    document.getElementById('voterBarDem').style.width = demPct + '%';
                    document.getElementById('voterBarRep').style.width = repPct + '%';
                    document.getElementById('voterBarInd').style.width = indPct + '%';
                    document.getElementById('modalDemPct').textContent = demPct + '% Dem';
                    document.getElementById('modalRepPct').textContent = repPct + '% Rep';
                    document.getElementById('modalIndPct').textContent = indPct + '% Ind';
                    document.getElementById('modalVoterSection').style.display = '';
                    document.getElementById('modalNoPartyReg').style.display = 'none';
                } else {
                    document.getElementById('modalVoterSection').style.display = 'none';
                    document.getElementById('modalNoPartyReg').style.display = 'none';
                }
            } else {
                document.getElementById('modalVoterSection').style.display = 'none';
                document.getElementById('modalNoPartyReg').style.display = 'none';
            }
            
            // Links: Congress + White House
            const linkLeg = document.getElementById('linkLegislature');
            linkLeg.href = 'https://www.congress.gov';
            linkLeg.innerHTML = '🏛️ Congress';
            linkLeg.style.display = '';
            
            const linkGov = document.getElementById('linkGovernor');
            if (data.governor_website) {
                linkGov.href = data.governor_website;
                linkGov.innerHTML = '🏠 White House';
                linkGov.style.display = '';
            } else {
                linkGov.style.display = 'none';
            }
            
            // Hide "This is My State" / "View State" buttons for USA
            document.getElementById('btnSetState').style.display = 'none';
            document.getElementById('btnViewState').style.display = 'none';
            
            // Position centered over the map
            const mapRect = document.getElementById('mapContainer').getBoundingClientRect();
            stateInfoModal.style.left = ((mapRect.width - 300) / 2) + 'px';
            stateInfoModal.style.top = '80px';
            
            // Welcome back section for returning users
            if (USER.isReturning) {
                stateInfoModal.classList.add('returning-user');
                const location = USER.townName 
                    ? USER.townName + ', ' + USER.stateAbbr
                    : USER.stateAbbr || 'Location not set';
                document.getElementById('wbLocation').textContent = location;
                document.getElementById('wbPoints').textContent = USER.points + ' civic points';
                document.getElementById('wbLevel').textContent = 'Level ' + USER.level + ': ' + 
                    USER.levelName.charAt(0).toUpperCase() + USER.levelName.slice(1);
            } else {
                stateInfoModal.classList.remove('returning-user');
            }
            
            stateInfoModal.classList.add('visible');
        }
        
        // "This is My State" button → transition to Google Maps (NEW USERS ONLY)
        document.getElementById('btnSetState').addEventListener('click', function() {
            // Block returning users from changing location via map
            if (USER.isReturning) {
                alert('To change your location, go to your Profile page.');
                return;
            }
            const stateCode = stateInfoModal.dataset.stateCode;
            if (stateCode) {
                hideStateInfoModal();
                transitionToGoogleMaps(stateCode.toLowerCase());
            }
        });
        
        // "View State" button
        document.getElementById('btnViewState').addEventListener('click', function() {
            const stateCode = stateInfoModal.dataset.stateCode;
            if (stateCode && stateCode !== 'USA') {
                window.location.href = '/z-states/' + stateCode.toLowerCase() + '/';
            }
        });
        
        // =====================================================
        // MAP COLORING FUNCTIONS
        // =====================================================
        function getStateCode(el) {
            var classes = el.className.baseVal ? el.className.baseVal.split(/\s+/) : [];
            for (var i = 0; i < classes.length; i++) {
                var c = classes[i].toUpperCase();
                if (c.length === 2 && stateData[c]) return c;
            }
            for (var i = 0; i < classes.length; i++) {
                if (classes[i].length === 2 && /^[a-z]{2}$/.test(classes[i])) return classes[i].toUpperCase();
            }
            return null;
        }

        function colorMap() {
            var svgEl = document.querySelector('#mapHolder svg');
            if (!svgEl) return;
            svgEl.querySelectorAll('.state path, .state circle').forEach(function(el) {
                var sc = getStateCode(el);
                if (!sc) return;
                var sd = stateData[sc];
                if (!sd || sd.total === 0) {
                    el.style.fill = '#1a2035';
                    return;
                }
                var total = sd.total;
                var dPct = sd.dem / total;
                var rPct = sd.rep / total;
                if (dPct === 1) el.style.fill = '#2563eb';
                else if (rPct === 1) el.style.fill = '#dc2626';
                else {
                    var r = Math.round(37 + (220 - 37) * rPct);
                    var g = Math.round(99 - 60 * Math.abs(dPct - rPct));
                    var b = Math.round(235 - (235 - 38) * rPct);
                    el.style.fill = 'rgb(' + r + ',' + g + ',' + b + ')';
                }
            });
        }

        function addStateLabels() {
            var svgEl = document.querySelector('#mapHolder svg');
            if (!svgEl) return;
            var ns = 'http://www.w3.org/2000/svg';
            var smallStates = {
                CT: { dx: 40, dy: -10 },
                DE: { dx: 38, dy: 5 },
                DC: { dx: 42, dy: 18 },
                MA: { dx: 40, dy: -12 },
                MD: { dx: 38, dy: 22 },
                NH: { dx: 36, dy: -18 },
                NJ: { dx: 36, dy: 8 },
                RI: { dx: 36, dy: 4 },
                VT: { dx: 32, dy: -12 }
            };
            var nudge = {
                FL: { dx: 20, dy: 12 },
                LA: { dx: -10, dy: 5 },
                MI: { dx: 18, dy: 20 },
                AK: { dx: 0, dy: 5 },
                HI: { dx: 0, dy: 5 },
                CA: { dx: -5, dy: 10 },
                ID: { dx: 0, dy: 8 },
                OK: { dx: 10, dy: 0 },
                TX: { dx: 0, dy: 8 },
                NY: { dx: 8, dy: 5 },
                VA: { dx: 5, dy: -3 }
            };
            var labelGroup = document.createElementNS(ns, 'g');
            labelGroup.setAttribute('class', 'state-labels');
            var statePaths = {};
            svgEl.querySelectorAll('.state path, .state circle').forEach(function(el) {
                var sc = getStateCode(el);
                if (!sc) return;
                if (!statePaths[sc]) statePaths[sc] = [];
                statePaths[sc].push(el);
            });
            Object.keys(statePaths).forEach(function(sc) {
                var paths = statePaths[sc];
                var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
                paths.forEach(function(p) {
                    try {
                        var bb = p.getBBox();
                        if (bb.x < minX) minX = bb.x;
                        if (bb.y < minY) minY = bb.y;
                        if (bb.x + bb.width > maxX) maxX = bb.x + bb.width;
                        if (bb.y + bb.height > maxY) maxY = bb.y + bb.height;
                    } catch(e) {}
                });
                if (minX === Infinity) return;
                var cx = (minX + maxX) / 2;
                var cy = (minY + maxY) / 2;
                if (nudge[sc]) { cx += nudge[sc].dx; cy += nudge[sc].dy; }
                var isSmall = !!smallStates[sc];
                if (isSmall) {
                    var off = smallStates[sc];
                    var lx = cx + off.dx;
                    var ly = cy + off.dy;
                    var line = document.createElementNS(ns, 'line');
                    line.setAttribute('x1', cx);
                    line.setAttribute('y1', cy);
                    line.setAttribute('x2', lx);
                    line.setAttribute('y2', ly);
                    line.setAttribute('class', 'label-line');
                    labelGroup.appendChild(line);
                    var txt = document.createElementNS(ns, 'text');
                    txt.setAttribute('x', lx);
                    txt.setAttribute('y', ly);
                    txt.setAttribute('class', 'state-label small-state');
                    txt.textContent = sc;
                    labelGroup.appendChild(txt);
                } else {
                    var txt = document.createElementNS(ns, 'text');
                    txt.setAttribute('x', cx);
                    txt.setAttribute('y', cy);
                    txt.setAttribute('class', 'state-label');
                    txt.textContent = sc;
                    labelGroup.appendChild(txt);
                }
            });
            svgEl.appendChild(labelGroup);
        }

        function getTooltipText(sc) {
            var sd = stateData[sc];
            if (!sd) return { name: sc, sub: 'No data' };
            var name = sd.name;
            var sub = sd.dem + 'D / ' + sd.rep + 'R' + (sd.ind > 0 ? ' / ' + sd.ind + 'I' : '') + ' \u00b7 ' + sd.total + ' members';
            return { name: name, sub: sub };
        }

        // =====================================================
        // SVG MAP INITIALIZATION
        // =====================================================
        fetch('usa-map.svg')
            .then(response => response.text())
            .then(svgContent => {
                document.getElementById('mapHolder').innerHTML = svgContent;

                // Move borders group to end of SVG so it renders ON TOP of all state fills
                const svg = document.querySelector('#mapHolder svg');
                const bordersGroup = svg ? svg.querySelector('.borders') : null;
                if (svg && bordersGroup) {
                    svg.appendChild(bordersGroup);
                }

                // Color states by party delegation and add 2-letter labels
                colorMap();
                addStateLabels();

                initSvgMap();
            })
            .catch(err => {
                document.getElementById('mapHolder').innerHTML = '<p style="text-align:center;color:#666;">Map loading...</p>';
            });
        
        function initSvgMap() {
            const tooltip = document.getElementById('mapTooltip');
            const statePaths = document.querySelectorAll('.state path, .state circle');
            
            statePaths.forEach(path => {
                const stateClass = Array.from(path.classList).find(c => stateNames[c]);
                if (!stateClass) return;
                
                const stateName = stateNames[stateClass] || stateClass.toUpperCase();
                const sc = stateClass.toUpperCase();
                const tt = getTooltipText(sc);
                let lastMousePos = { x: 0, y: 0 };

                // Hover → tooltip
                path.addEventListener('mouseenter', (e) => {
                    tooltip.querySelector('.state-name').textContent = tt.name;
                    tooltip.querySelector('.state-stats').textContent = tt.sub;
                    tooltip.classList.add('visible');
                    
                    // Timer for info modal
                    clearHoverTimer();
                    currentHoverState = stateClass;
                    const rect = document.getElementById('mapContainer').getBoundingClientRect();
                    lastMousePos = { x: e.clientX - rect.left, y: e.clientY - rect.top };
                    
                    hoverTimer = setTimeout(() => {
                        if (currentHoverState === stateClass) {
                            tooltip.classList.remove('visible');
                            showStateInfoModal(stateClass, lastMousePos.x, lastMousePos.y);
                        }
                    }, HOVER_DELAY);
                });
                
                path.addEventListener('mousemove', (e) => {
                    const rect = document.getElementById('mapContainer').getBoundingClientRect();
                    tooltip.style.left = (e.clientX - rect.left + 15) + 'px';
                    tooltip.style.top = (e.clientY - rect.top - 10) + 'px';
                    tooltip.style.transform = 'none';
                    lastMousePos = { x: e.clientX - rect.left, y: e.clientY - rect.top };
                });
                
                path.addEventListener('mouseleave', () => {
                    tooltip.classList.remove('visible');
                    tooltip.style.left = '50%';
                    tooltip.style.top = '8px';
                    tooltip.style.transform = 'translateX(-50%)';
                    clearHoverTimer();
                });
                
                // Click → show modal (user chooses from there)
                path.addEventListener('click', (e) => {
                    clearHoverTimer();
                    tooltip.classList.remove('visible');
                    const rect = document.getElementById('mapContainer').getBoundingClientRect();
                    showStateInfoModal(stateClass, e.clientX - rect.left, e.clientY - rect.top);
                });
            });
        }
        
        // =====================================================
        // PAGE VISIT + INIT
        // =====================================================
        logCivicAction('page_visit', 'index', { referrer: document.referrer || 'direct' });
        
        // Auto-open USA modal on page load
        fetch('/api/get-state-info.php?state=USA')
            .then(r => r.json())
            .then(data => {
                stateDataCache['USA'] = data;
                showUSAModal(data);
            })
            .catch(() => {});
        
    })();
    </script>
</body>
</html>
