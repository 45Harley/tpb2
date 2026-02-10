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

// Session - get or will be created by JS
$sessionId = isset($_COOKIE['tpb_civic_session']) ? $_COOKIE['tpb_civic_session'] : null;

// Load user data if logged in
$dbUser = null;
if ($sessionId && $pdo) {
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.email, u.civic_points,
               u.current_state_id, u.current_town_id,
               s.abbreviation as state_abbrev, s.state_name,
               t.town_name,
               COALESCE(uis.email_verified, 0) as email_verified,
               COALESCE(uis.phone_verified, 0) as phone_verified
        FROM user_devices ud
        INNER JOIN users u ON ud.user_id = u.user_id
        LEFT JOIN states s ON u.current_state_id = s.state_id
        LEFT JOIN towns t ON u.current_town_id = t.town_id
        LEFT JOIN user_identity_status uis ON u.user_id = uis.user_id
        WHERE ud.device_session = ? AND ud.is_active = 1
    ");
    $stmt->execute(array($sessionId));
    $dbUser = $stmt->fetch();
}

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

// Get civic_clicks points for this session (even if not logged in user)
$sessionPoints = 0;
if ($sessionId && $pdo) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(points_earned), 0) FROM civic_clicks WHERE session_id = ?");
    $stmt->execute(array($sessionId));
    $sessionPoints = (int)$stmt->fetchColumn();
}

// Get session history - what have they done?
$sessionHistory = [];
if ($sessionId && $pdo) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT action_type, element_id, extra_data 
        FROM civic_clicks 
        WHERE session_id = ?
        ORDER BY created_at DESC
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

// Nav variables
$trustLevel = 'Visitor';
$nextStep = '';
$userTrustLevel = 0;
if ($dbUser) {
    if ($dbUser['phone_verified']) {
        $trustLevel = 'Verified (2FA)';
        $userTrustLevel = 2;
    } elseif ($dbUser['email_verified']) {
        $trustLevel = 'Email Verified';
        $userTrustLevel = 1;
    }
}
$points = $dbUser ? (int)$dbUser['civic_points'] : $sessionPoints;
$currentPage = 'home';

// Nav variables for email and town
$userEmail = $dbUser ? ($dbUser['email'] ?? '') : '';
$userTownName = $dbUser ? ($dbUser['town_name'] ?? '') : '';
$userTownSlug = $userTownName ? strtolower(str_replace(' ', '-', $userTownName)) : '';
$userStateAbbr = $dbUser ? strtolower($dbUser['state_abbrev'] ?? '') : '';
$userStateDisplay = $dbUser ? ($dbUser['state_abbrev'] ?? '') : '';
$isLoggedIn = (bool)$dbUser;

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
            color: #f4cf57;
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
        
        /* Default state styling */
        /* ============================================================
           CRITICAL: CSS ORDER MATTERS FOR MAP HIGHLIGHTING!
           active-gold rules MUST come immediately after hover rules,
           BEFORE the borders rule. If reordered, gold highlighting 
           breaks on shared state borders (e.g., OK/TX sliver bug).
           See memory edit #21. Do not reorder without testing.
           ============================================================ */
        .map-container svg .state path,
        .map-container svg .state circle {
            fill: #2a2a2a !important;
            stroke: #ffffff !important;
            stroke-width: 1 !important;
            cursor: pointer;
            transition: fill 0.2s;
        }
        
        .map-container svg .state path:hover,
        .map-container svg .state circle:hover {
            fill: #3a3a3a !important;
        }
        
        /* Gold active state - applied via JS */
        .map-container svg .state path.active-gold,
        .map-container svg .state circle.active-gold {
            fill: #d4af37 !important;
            stroke: #d4af37 !important;
            stroke-width: 2px !important;
        }
        
        .map-container svg .state path.active-gold:hover,
        .map-container svg .state circle.active-gold:hover {
            fill: #f4cf57 !important;
            stroke: #f4cf57 !important;
        }
        
        /* Hide border pointer events */
        .map-container svg .borders path {
            pointer-events: none !important;
        }
        
        /* Tooltip */
        .map-tooltip {
            position: absolute;
            background: #1a1a1a;
            border: 1px solid #d4af37;
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
            color: #d4af37;
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
        
        /* State Info Modal - floats beside state on hover */
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
            background: #d4af37;
            color: #000;
            border: none;
            padding: 10px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.2s;
        }
        
        .state-info-modal .btn-set-state:hover {
            background: #f4cf57;
        }
        
        .state-info-modal .btn-view-state {
            flex: 1;
            background: transparent;
            color: #d4af37;
            border: 1px solid #d4af37;
            padding: 10px 15px;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .state-info-modal .btn-view-state:hover {
            background: rgba(212, 175, 55, 0.1);
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
        
        .legend-color.active {
            background: #d4af37;
        }
        
        .legend-color.coming {
            background: #2a2a2a;
            border: 1px solid #444;
        }
        
        /* STATE DIALOG - Town Picker Flow */
        .state-dialog {
            display: none;
            background: #0f0f1a;
            border-top: 2px solid #d4af37;
            padding: 40px 20px;
            animation: slideDown 0.3s ease;
        }
        
        .state-dialog.visible {
            display: block;
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .dialog-inner {
            max-width: 500px;
            margin: 0 auto;
            text-align: center;
        }
        
        .dialog-header {
            margin-bottom: 25px;
        }
        
        .dialog-header h2 {
            color: #d4af37;
            font-size: 2em;
            margin-bottom: 5px;
        }
        
        .dialog-header .points-earned {
            color: #4caf50;
            font-size: 0.9em;
        }
        
        /* Step sections */
        .dialog-step {
            display: none;
            animation: fadeIn 0.3s ease;
        }
        
        .dialog-step.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .step-section {
            background: #1a1a2a;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
        }
        
        .step-section p {
            color: #ccc;
            margin-bottom: 15px;
        }
        
        .step-section .thanks-msg {
            color: #4caf50;
            font-size: 1.2em;
            margin-bottom: 15px;
        }
        
        .step-section .location-display {
            color: #d4af37;
            font-size: 1.4em;
            font-weight: bold;
            margin: 15px 0;
        }
        
        /* Town autocomplete */
        .town-input-wrap {
            position: relative;
        }
        
        .town-input {
            width: 100%;
            padding: 14px 18px;
            font-size: 1.1em;
            background: #0a0a0f;
            border: 2px solid #444;
            border-radius: 8px;
            color: #e0e0e0;
        }
        
        .town-input:focus {
            outline: none;
            border-color: #d4af37;
        }
        
        .town-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #1a1a2e;
            border: 1px solid #d4af37;
            border-top: none;
            border-radius: 0 0 8px 8px;
            max-height: 250px;
            overflow-y: auto;
            z-index: 100;
            display: none;
        }
        
        .town-dropdown.visible {
            display: block;
        }
        
        .town-option {
            padding: 12px 18px;
            cursor: pointer;
            color: #e0e0e0;
            text-align: left;
            border-bottom: 1px solid #333;
        }
        
        .town-option:last-child {
            border-bottom: none;
        }
        
        .town-option:hover,
        .town-option.highlighted {
            background: rgba(212, 175, 55, 0.2);
        }
        
        /* Zip dropdown */
        .zip-select {
            width: 100%;
            padding: 14px 18px;
            font-size: 1.1em;
            background: #0a0a0f;
            border: 2px solid #444;
            border-radius: 8px;
            color: #e0e0e0;
            cursor: pointer;
        }
        
        .zip-select:focus {
            outline: none;
            border-color: #d4af37;
        }
        
        /* Email input */
        .email-input {
            width: 100%;
            padding: 14px 18px;
            font-size: 1.1em;
            background: #0a0a0f;
            border: 2px solid #444;
            border-radius: 8px;
            color: #e0e0e0;
            margin-bottom: 15px;
        }
        
        .email-input:focus {
            outline: none;
            border-color: #d4af37;
        }
        
        /* Buttons */
        .btn-primary {
            padding: 14px 32px;
            font-size: 1.1em;
            background: #d4af37;
            color: #000;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.2s;
        }
        
        .btn-primary:hover {
            background: #e4bf47;
            transform: translateY(-1px);
        }
        
        .btn-primary:disabled {
            background: #666;
            cursor: not-allowed;
            transform: none;
        }
        
        .btn-secondary {
            padding: 12px 24px;
            font-size: 1em;
            background: transparent;
            color: #888;
            border: 1px solid #444;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            margin-left: 10px;
        }
        
        .btn-secondary:hover {
            border-color: #888;
            color: #ccc;
        }
        
        /* Celebration */
        .celebration {
            text-align: center;
            padding: 40px 20px;
        }
        
        .celebration .confetti {
            font-size: 3em;
            margin-bottom: 20px;
            animation: bounce 0.5s ease infinite alternate;
        }
        
        @keyframes bounce {
            from { transform: translateY(0); }
            to { transform: translateY(-10px); }
        }
        
        .celebration h2 {
            color: #d4af37;
            font-size: 2em;
            margin-bottom: 10px;
        }
        
        .celebration .welcome-msg {
            color: #4caf50;
            font-size: 1.3em;
            margin-bottom: 20px;
        }
        
        .celebration .points-earned-big {
            color: #d4af37;
            font-size: 1.5em;
            margin-bottom: 30px;
        }
        
        /* Skip link */
        .skip-link {
            display: inline-block;
            margin-top: 20px;
            color: #666;
            font-size: 0.9em;
            cursor: pointer;
        }
        
        .skip-link:hover {
            color: #888;
            text-decoration: underline;
        }
        
        /* Close button */
        .dialog-close {
            position: absolute;
            top: 15px;
            right: 20px;
            color: #666;
            font-size: 1.5em;
            cursor: pointer;
            background: none;
            border: none;
        }
        
        .dialog-close:hover {
            color: #d4af37;
        }
        
        /* FOOTER */
        footer {
            background: #0a0a0a;
            border-top: 1px solid #222;
            padding: 40px 20px;
            text-align: center;
            color: #888;
        }
        
        .footer-links {
            margin-bottom: 20px;
        }
        
        .footer-links a {
            color: #aaa;
            margin: 0 15px;
            font-size: 0.95em;
        }
        
        .footer-links a:hover {
            color: #d4af37;
        }
        
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
        <h2 class="usa-title" id="usaTitle">United States of America</h2>
        <p class="section-intro">Hover over a state for info. Click to select.</p>
        
        <div class="map-container">
            <div class="map-tooltip" id="mapTooltip">
                <div class="state-name"></div>
                <div class="state-stats"></div>
            </div>
            
            <!-- SVG Map will be inserted here -->
            <div id="mapHolder"></div>
            
            <!-- State Info Modal - floats beside state on hover -->
            <div class="state-info-modal" id="stateInfoModal">
                <button class="modal-close-btn" id="modalCloseBtn">&times;</button>
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
        
        <div class="map-legend">
            <div class="legend-item">
                <div class="legend-color active"></div>
                <span>Active</span>
            </div>
            <div class="legend-item">
                <div class="legend-color coming"></div>
                <span>Click to Join</span>
            </div>
        </div>
    </section>
    
    <!-- STATE DIALOG - Town Picker Flow -->
    <section class="state-dialog" id="stateDialog">
        <div class="dialog-inner" style="position: relative;">
            <button class="dialog-close" id="dialogClose">&times;</button>
            
            <div class="dialog-header">
                <h2 id="dialogStateName">Texas</h2>
                <div class="points-earned" id="dialogPointsEarned">+2 pts</div>
            </div>
            
            <!-- Step 1: Pick your town -->
            <div class="dialog-step active" id="stepTown">
                <div class="step-section">
                    <p>What town in <strong id="townStateName">Texas</strong> do you live in?</p>
                    <div class="town-input-wrap">
                        <input type="text" class="town-input" id="townInput" placeholder="Start typing your town..." autocomplete="off">
                        <div class="town-dropdown" id="townDropdown"></div>
                    </div>
                </div>
                <span class="skip-link" id="skipTown">Just browsing — skip</span>
            </div>
            
            <!-- Step 2: Pick zip (if multiple) -->
            <div class="dialog-step" id="stepZip">
                <div class="step-section">
                    <div class="thanks-msg">Thanks!</div>
                    <div class="location-display" id="selectedTownDisplay">Putnam, CT</div>
                    <p>Which zip code?</p>
                    <select class="zip-select" id="zipSelect"></select>
                </div>
                <button class="btn-primary" id="confirmZipBtn">That's Me!</button>
            </div>
            
            <!-- Step 3: Single zip confirmed -->
            <div class="dialog-step" id="stepConfirmed">
                <div class="step-section">
                    <div class="thanks-msg">Got it!</div>
                    <div class="location-display" id="confirmedLocation">Putnam, CT 06260</div>
                    <p>Want to make it official? Verify your email to save your location and start participating.</p>
                    <input type="email" class="email-input" id="emailInput" placeholder="Your email address">
                    <div>
                        <button class="btn-primary" id="verifyEmailBtn">Verify Email</button>
                    </div>
                </div>
                <span class="skip-link" id="skipEmail">Skip for now — just exploring</span>
            </div>
            
            <!-- Step 4: Celebration! -->
            <div class="dialog-step" id="stepCelebration">
                <div class="celebration">
                    <div class="confetti">🎉</div>
                    <h2>Welcome to The People's Branch!</h2>
                    <div class="welcome-msg" id="welcomeMsg">You're now a citizen of Putnam, CT</div>
                    <div class="points-earned-big" id="totalPointsEarned">+35 civic points earned!</div>
                    <p style="color: #888; margin-bottom: 25px;">Check your email to complete verification.</p>
                    <button class="btn-primary" id="goToProfileBtn">Go to My Profile →</button>
                </div>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer>
        <p>The People's Branch — Your voice in democracy</p>
        <p class="footer-links">
            <a href="/">Home</a>
            <a href="/story.php">Our Story</a>
            <a href="/contact.php">Contact</a>
            <a href="/privacy.php">Privacy</a>
            <a href="/terms.php">Terms</a>
        </p>
        <p>© 2025 The People's Branch</p>
    </footer>
    
    <script>
    (function() {
        // =====================================================
        // CONFIG
        // =====================================================
        const CONFIG = {
            apiBase: 'api',
            page: 'index',
            debug: false
        };
        
        // Active states (from database)
        const activeStates = <?= json_encode(array_map('strtoupper', $activeStates)) ?>;
        
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
        
        // State data cache - will be fetched on hover
        let stateDataCache = {};
        let hoverTimer = null;
        let currentHoverState = null;
        const HOVER_DELAY = 3000; // 3 seconds
        
        // =====================================================
        // SESSION MANAGEMENT
        // =====================================================
        let sessionId = localStorage.getItem('tpb_civic_session');
        if (!sessionId) {
            sessionId = 'civic_' + Math.random().toString(36).substr(2, 9) + '_' + Date.now();
            localStorage.setItem('tpb_civic_session', sessionId);
        }
        // Sync to cookie so PHP can read it
        document.cookie = 'tpb_civic_session=' + sessionId + '; path=/; max-age=31536000';
        
        // Track current state
        let currentState = null;
        let currentTown = null;
        let currentZip = null;
        let civicPoints = <?= $points ?>;
        
        // PHP-provided history
        const lastState = <?= json_encode($lastState) ?>;
        const lastTown = <?= json_encode($lastTown) ?>;
        const lastZip = <?= json_encode($lastZip) ?>;
        const hasViewedReps = <?= json_encode($hasViewedReps) ?>;
        const hasViewedThoughts = <?= json_encode($hasViewedThoughts) ?>;
        const hasVisitedStory = <?= json_encode($hasVisitedStory) ?>;
        const hasEnteredZip = <?= json_encode($hasEnteredZip) ?>;
        const hasProfileLocation = <?= json_encode($hasProfileLocation) ?>;
        
        // =====================================================
        // CIVIC POINTS LOGGING
        // =====================================================
        function updatePointsDisplay(newTotal, earned) {
            if (newTotal > civicPoints) {
                civicPoints = newTotal;
                const el = document.getElementById('navPoints');
                if (el) {
                    el.textContent = civicPoints;
                    el.classList.add('pulse');
                    setTimeout(() => el.classList.remove('pulse'), 500);
                }
            }
            // Show earned points in dialog if visible
            if (earned) {
                const earnedEl = document.getElementById('dialogPointsEarned');
                if (earnedEl) {
                    earnedEl.textContent = '+' + earned + ' pts';
                    earnedEl.style.display = 'block';
                }
            }
        }
        
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
                
                if (CONFIG.debug) console.log('[TPB] Logging:', payload);
                
                const response = await fetch(`${CONFIG.apiBase}/log-civic-click.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                
                if (data.session && data.session.total_points) {
                    updatePointsDisplay(data.session.total_points, data.points_earned);
                }
                
                return data;
            } catch (error) {
                if (CONFIG.debug) console.warn('[TPB] Log failed:', error);
                return null;
            }
        }
        
        // =====================================================
        // PAGE VISIT LOGGING
        // =====================================================
        let pageVisitLogged = false;
        function logPageVisit() {
            if (pageVisitLogged) return;
            pageVisitLogged = true;
            logCivicAction('page_visit', 'index', {
                referrer: document.referrer || 'direct',
                viewport: window.innerWidth + 'x' + window.innerHeight
            });
        }
        
        // =====================================================
        // STATE DIALOG
        // =====================================================
        const dialog = document.getElementById('stateDialog');
        const dialogStateName = document.getElementById('dialogStateName');
        
        // State Info Modal elements
        const stateInfoModal = document.getElementById('stateInfoModal');
        const modalStateName = document.getElementById('modalStateName');
        const modalPopulation = document.getElementById('modalPopulation');
        const modalPopPct = document.getElementById('modalPopPct');
        const modalCapital = document.getElementById('modalCapital');
        const modalLargestCity = document.getElementById('modalLargestCity');
        const modalGovernor = document.getElementById('modalGovernor');
        const modalGovParty = document.getElementById('modalGovParty');
        const modalGovernorRow = document.getElementById('modalGovernorRow');
        const modalVoterSection = document.getElementById('modalVoterSection');
        const modalNoPartyReg = document.getElementById('modalNoPartyReg');
        const voterBarDem = document.getElementById('voterBarDem');
        const voterBarRep = document.getElementById('voterBarRep');
        const voterBarInd = document.getElementById('voterBarInd');
        const modalDemPct = document.getElementById('modalDemPct');
        const modalRepPct = document.getElementById('modalRepPct');
        const modalIndPct = document.getElementById('modalIndPct');
        const linkLegislature = document.getElementById('linkLegislature');
        const linkGovernor = document.getElementById('linkGovernor');
        const modalLinks = document.getElementById('modalLinks');
        
        // Fetch state data from API
        async function fetchStateData(stateCode) {
            if (stateDataCache[stateCode]) {
                return stateDataCache[stateCode];
            }
            
            try {
                const response = await fetch('/api/get-state-info.php?state=' + stateCode.toUpperCase());
                if (response.ok) {
                    const data = await response.json();
                    stateDataCache[stateCode] = data;
                    return data;
                }
            } catch (e) {
                console.error('Error fetching state data:', e);
            }
            return null;
        }
        
        // Format number with commas
        function formatNumber(num) {
            if (!num) return '-';
            return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }
        
        // Show state info modal
        async function showStateInfoModal(stateCode, posX, posY) {
            const stateName = stateNames[stateCode] || stateCode.toUpperCase();
            const data = await fetchStateData(stateCode);
            
            modalStateName.textContent = stateName;
            
            if (data) {
                modalPopulation.textContent = formatNumber(data.population);
                
                // Population percentage
                if (data.population_pct !== null && data.population_pct !== undefined) {
                    modalPopPct.textContent = '(' + data.population_pct + '% of US)';
                } else {
                    modalPopPct.textContent = '';
                }
                
                modalCapital.textContent = data.capital_city || '-';
                modalLargestCity.textContent = data.largest_city ? 
                    data.largest_city + ' (' + formatNumber(data.largest_city_population) + ')' : '-';
                
                // Governor
                if (data.governor_name) {
                    modalGovernor.textContent = data.governor_name;
                    modalGovParty.textContent = data.governor_party || '';
                    modalGovParty.className = 'gov-party';
                    if (data.governor_party === 'D') modalGovParty.classList.add('dem');
                    else if (data.governor_party === 'R') modalGovParty.classList.add('rep');
                    else if (data.governor_party === 'I') modalGovParty.classList.add('ind');
                    modalGovernorRow.style.display = 'flex';
                    // Reset label to Governor (in case it was changed to President)
                    modalGovernorRow.querySelector('.stat-label').textContent = 'Governor';
                } else {
                    modalGovernorRow.style.display = 'none';
                }
                
                // Voter registration
                if (data.voters_democrat !== null) {
                    modalVoterSection.style.display = 'block';
                    modalNoPartyReg.style.display = 'none';
                    
                    const total = data.voters_democrat + data.voters_republican + data.voters_independent;
                    const demPct = Math.round((data.voters_democrat / total) * 100);
                    const repPct = Math.round((data.voters_republican / total) * 100);
                    const indPct = Math.round((data.voters_independent / total) * 100);
                    
                    voterBarDem.style.width = demPct + '%';
                    voterBarRep.style.width = repPct + '%';
                    voterBarInd.style.width = indPct + '%';
                    
                    modalDemPct.textContent = demPct + '% D';
                    modalRepPct.textContent = repPct + '% R';
                    modalIndPct.textContent = indPct + '% I';
                } else {
                    modalVoterSection.style.display = 'none';
                    modalNoPartyReg.style.display = 'block';
                }
                
                // Links
                let hasLinks = false;
                if (data.legislature_url) {
                    linkLegislature.href = data.legislature_url;
                    linkLegislature.innerHTML = '🏛️ Legislature';
                    linkLegislature.classList.remove('hidden');
                    hasLinks = true;
                } else {
                    linkLegislature.classList.add('hidden');
                }
                
                if (data.governor_website) {
                    linkGovernor.href = data.governor_website;
                    linkGovernor.innerHTML = '👤 Governor';
                    linkGovernor.classList.remove('hidden');
                    hasLinks = true;
                } else {
                    linkGovernor.classList.add('hidden');
                }
                
                modalLinks.style.display = hasLinks ? 'flex' : 'none';
                
            } else {
                modalPopulation.textContent = '-';
                modalPopPct.textContent = '';
                modalCapital.textContent = '-';
                modalLargestCity.textContent = '-';
                modalGovernorRow.style.display = 'none';
                modalVoterSection.style.display = 'none';
                modalNoPartyReg.style.display = 'none';
                modalLinks.style.display = 'none';
            }
            
            // Position modal beside the state (not covering it)
            const mapContainer = document.querySelector('.map-container');
            const mapRect = mapContainer.getBoundingClientRect();
            const modalWidth = 300;
            
            // Decide left or right of cursor
            let left = posX + 20;
            if (posX + modalWidth + 40 > mapRect.width) {
                left = posX - modalWidth - 20;
            }
            
            stateInfoModal.style.left = left + 'px';
            stateInfoModal.style.top = Math.max(10, posY - 50) + 'px';
            stateInfoModal.classList.add('visible');
            stateInfoModal.dataset.stateCode = stateCode;
        }
        
        // Hide state info modal
        function hideStateInfoModal() {
            stateInfoModal.classList.remove('visible');
            currentHoverState = null;
        }
        
        // Clear hover timer
        function clearHoverTimer() {
            if (hoverTimer) {
                clearTimeout(hoverTimer);
                hoverTimer = null;
            }
        }
        
        // USA title hover - shows national stats
        const usaTitle = document.getElementById('usaTitle');
        let usaTitleTimer = null;
        
        usaTitle.addEventListener('mouseenter', () => {
            usaTitleTimer = setTimeout(async () => {
                // Show USA totals
                const data = await fetchStateData('USA');
                if (data) {
                    modalStateName.textContent = 'United States of America';
                    modalPopulation.textContent = formatNumber(data.population);
                    modalPopPct.textContent = '';
                    modalCapital.textContent = data.capital_city || 'Washington, D.C.';
                    modalLargestCity.textContent = data.largest_city || 'New York City';
                    
                    // President instead of Governor for USA
                    if (data.governor_name) {
                        modalGovernor.textContent = data.governor_name;
                        modalGovParty.textContent = data.governor_party === 'Republican' ? 'R' : data.governor_party;
                        modalGovParty.className = 'gov-party rep';
                        modalGovernorRow.style.display = 'flex';
                        // Change label to "President" for USA
                        modalGovernorRow.querySelector('.stat-label').textContent = 'President';
                    } else {
                        modalGovernorRow.style.display = 'none';
                    }
                    
                    if (data.voters_democrat !== null) {
                        modalVoterSection.style.display = 'block';
                        modalNoPartyReg.style.display = 'none';
                        
                        const total = data.voters_democrat + data.voters_republican + data.voters_independent;
                        const demPct = Math.round((data.voters_democrat / total) * 100);
                        const repPct = Math.round((data.voters_republican / total) * 100);
                        const indPct = Math.round((data.voters_independent / total) * 100);
                        
                        voterBarDem.style.width = demPct + '%';
                        voterBarRep.style.width = repPct + '%';
                        voterBarInd.style.width = indPct + '%';
                        
                        modalDemPct.textContent = demPct + '% D';
                        modalRepPct.textContent = repPct + '% R';
                        modalIndPct.textContent = indPct + '% I';
                    } else {
                        modalVoterSection.style.display = 'none';
                        modalNoPartyReg.style.display = 'none';
                    }
                    
                    // Links for USA
                    linkLegislature.href = 'https://www.congress.gov';
                    linkLegislature.classList.remove('hidden');
                    linkLegislature.innerHTML = '🏛️ Congress';
                    
                    if (data.governor_website) {
                        linkGovernor.href = data.governor_website;
                        linkGovernor.classList.remove('hidden');
                        linkGovernor.innerHTML = '🏠 White House';
                    } else {
                        linkGovernor.classList.add('hidden');
                    }
                    modalLinks.style.display = 'flex';
                    
                    // Position below title
                    const titleRect = usaTitle.getBoundingClientRect();
                    const mapRect = document.querySelector('.map-container').getBoundingClientRect();
                    stateInfoModal.style.left = ((mapRect.width - 300) / 2) + 'px';
                    stateInfoModal.style.top = '10px';
                    stateInfoModal.classList.add('visible');
                    stateInfoModal.dataset.stateCode = 'USA';
                }
            }, HOVER_DELAY);
        });
        
        usaTitle.addEventListener('mouseleave', () => {
            if (usaTitleTimer) {
                clearTimeout(usaTitleTimer);
                usaTitleTimer = null;
            }
            // Modal stays open - closed by X button only
        });
        
        // Close modal with X button
        document.getElementById('modalCloseBtn').addEventListener('click', function() {
            hideStateInfoModal();
        });
        
        // Set State button click
        document.getElementById('btnSetState').addEventListener('click', function() {
            const stateCode = stateInfoModal.dataset.stateCode;
            if (stateCode && stateCode !== 'USA') {
                // For now, redirect to state dialog / set state flow
                showStateDialog(stateCode);
                hideStateInfoModal();
            }
        });
        
        // View State button click
        document.getElementById('btnViewState').addEventListener('click', function() {
            const stateCode = stateInfoModal.dataset.stateCode;
            if (stateCode && stateCode !== 'USA') {
                window.location.href = '/z-states/' + stateCode.toLowerCase() + '/';
            }
        });

        function showStateDialog(stateCode) {
            const stateName = stateNames[stateCode] || stateCode.toUpperCase();
            const isActive = activeStates.includes(stateCode.toUpperCase());
            
            // Always show town picker first - capture their location
            showStateDialogContent(stateCode, stateName, isActive);
        }
        
        function showStateDialogContent(stateCode, stateName, isActive) {
            currentState = stateCode.toUpperCase();
            currentStateName = stateName;
            
            // Update dialog header
            document.getElementById('dialogStateName').textContent = stateName;
            document.getElementById('townStateName').textContent = stateName;
            
            // Log state click
            logCivicAction('state_click', stateCode, { 
                state_code: stateCode.toUpperCase(),
                state_name: stateName,
                is_active: isActive
            });
            
            // Reset to step 1
            showStep('stepTown');
            document.getElementById('townInput').value = '';
            document.getElementById('townDropdown').classList.remove('visible');
            
            // Load towns for this state
            loadStateTowns(stateCode.toUpperCase());
            
            // Show dialog
            dialog.classList.add('visible');
            
            // Scroll to dialog and focus input
            setTimeout(() => {
                dialog.scrollIntoView({ behavior: 'smooth', block: 'start' });
                document.getElementById('townInput').focus();
            }, 100);
        }
        
        // =====================================================
        // TOWN PICKER FLOW
        // =====================================================
        let stateTowns = [];
        let currentStateName = '';
        let selectedTown = null;
        let selectedZip = null;
        let townZips = [];
        let totalPointsEarned = 0;
        
        function showStep(stepId) {
            document.querySelectorAll('.dialog-step').forEach(el => el.classList.remove('active'));
            document.getElementById(stepId).classList.add('active');
        }
        
        async function loadStateTowns(stateCode) {
            try {
                const response = await fetch('api/zip-lookup.php?action=get_state_towns&state_code=' + stateCode);
                const result = await response.json();
                if (result.status === 'success') {
                    stateTowns = result.data;
                }
            } catch (err) {
                console.error('Error loading towns:', err);
            }
        }
        
        // Town input autocomplete
        const townInput = document.getElementById('townInput');
        const townDropdown = document.getElementById('townDropdown');
        let highlightedIndex = -1;
        
        townInput.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            if (query.length < 1) {
                townDropdown.classList.remove('visible');
                return;
            }
            
            // Filter towns
            const matches = stateTowns.filter(t => t.toLowerCase().startsWith(query)).slice(0, 20);
            
            if (matches.length === 0) {
                townDropdown.classList.remove('visible');
                return;
            }
            
            // Build dropdown
            townDropdown.innerHTML = matches.map((town, i) => 
                `<div class="town-option" data-town="${town}" data-index="${i}">${town}</div>`
            ).join('');
            townDropdown.classList.add('visible');
            highlightedIndex = -1;
            
            // Click handlers
            townDropdown.querySelectorAll('.town-option').forEach(opt => {
                opt.addEventListener('click', function() {
                    selectTown(this.dataset.town);
                });
            });
        });
        
        // Keyboard navigation
        townInput.addEventListener('keydown', function(e) {
            const options = townDropdown.querySelectorAll('.town-option');
            if (!townDropdown.classList.contains('visible') || options.length === 0) return;
            
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                highlightedIndex = Math.min(highlightedIndex + 1, options.length - 1);
                updateHighlight(options);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                highlightedIndex = Math.max(highlightedIndex - 1, 0);
                updateHighlight(options);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (highlightedIndex >= 0) {
                    selectTown(options[highlightedIndex].dataset.town);
                }
            } else if (e.key === 'Escape') {
                townDropdown.classList.remove('visible');
            }
        });
        
        function updateHighlight(options) {
            options.forEach((opt, i) => {
                opt.classList.toggle('highlighted', i === highlightedIndex);
            });
        }
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.town-input-wrap')) {
                townDropdown.classList.remove('visible');
            }
        });
        
        async function selectTown(townName) {
            selectedTown = townName;
            townInput.value = townName;
            townDropdown.classList.remove('visible');
            
            // Create user and save state + town to DB
            try {
                const saveResponse = await fetch('api/create-user-location.php?action=save_town&session_id=' + encodeURIComponent(sessionId) + '&state_code=' + currentState + '&town_name=' + encodeURIComponent(townName));
                const saveResult = await saveResponse.json();
                
                if (saveResult.status !== 'success') {
                    console.error('Error saving town:', saveResult.message);
                }
            } catch (err) {
                console.error('Error saving town:', err);
            }
            
            // Log town selection
            logCivicAction('town_select', townName, { 
                state_code: currentState,
                town: townName 
            });
            totalPointsEarned = 5;
            
            // Get zips for this town
            try {
                const response = await fetch('api/zip-lookup.php?action=get_town_zips&town_name=' + encodeURIComponent(townName) + '&state_code=' + currentState);
                const result = await response.json();
                
                if (result.status === 'success' && result.data.length > 0) {
                    townZips = result.data;
                    
                    if (townZips.length === 1) {
                        // Single zip - auto-select and save to DB
                        selectedZip = townZips[0].zip_code;
                        await saveZipToDb(selectedZip);
                        totalPointsEarned += 5;
                        document.getElementById('confirmedLocation').textContent = 
                            `${selectedTown}, ${currentState} ${selectedZip}`;
                        showStep('stepConfirmed');
                    } else {
                        // Multiple zips - show picker
                        document.getElementById('selectedTownDisplay').textContent = 
                            `${selectedTown}, ${currentState}`;
                        
                        const zipSelect = document.getElementById('zipSelect');
                        zipSelect.innerHTML = townZips.map(z => 
                            `<option value="${z.zip_code}">${z.zip_code}${z.county ? ' (' + z.county + ' County)' : ''}</option>`
                        ).join('');
                        
                        showStep('stepZip');
                    }
                } else {
                    alert('Could not find zip codes for this town.');
                }
            } catch (err) {
                console.error('Error getting zips:', err);
                alert('Error looking up town. Please try again.');
            }
        }
        
        // Save zip to database
        async function saveZipToDb(zip) {
            try {
                const response = await fetch('api/create-user-location.php?action=save_zip&session_id=' + encodeURIComponent(sessionId) + '&zip_code=' + zip);
                const result = await response.json();
                if (result.status !== 'success') {
                    console.error('Error saving zip:', result.message);
                }
            } catch (err) {
                console.error('Error saving zip:', err);
            }
        }
        
        // Zip selection confirm
        document.getElementById('confirmZipBtn').addEventListener('click', async function() {
            selectedZip = document.getElementById('zipSelect').value;
            await saveZipToDb(selectedZip);
            totalPointsEarned += 5;
            
            logCivicAction('zip_select', selectedZip, {
                state_code: currentState,
                town: selectedTown,
                zip: selectedZip
            });
            
            document.getElementById('confirmedLocation').textContent = 
                `${selectedTown}, ${currentState} ${selectedZip}`;
            showStep('stepConfirmed');
        });
        
        // Email verification
        document.getElementById('verifyEmailBtn').addEventListener('click', async function() {
            const email = document.getElementById('emailInput').value.trim();
            if (!email || !email.includes('@')) {
                alert('Please enter a valid email address.');
                return;
            }
            
            this.disabled = true;
            this.textContent = 'Sending...';
            
            try {
                // Save location and send magic link
                const response = await fetch('api/send-magic-link.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        email: email,
                        session_id: sessionId,
                        state_code: currentState,
                        town_name: selectedTown,
                        zip_code: selectedZip
                    })
                });
                const result = await response.json();
                
                if (result.status === 'success' || result.status === 'warning') {
                    totalPointsEarned += 25;
                    
                    logCivicAction('email_submit', email, {
                        state_code: currentState,
                        town: selectedTown,
                        zip: selectedZip
                    });
                    
                    // Show celebration
                    document.getElementById('welcomeMsg').textContent = 
                        `You're now a citizen of ${selectedTown}, ${currentState}!`;
                    document.getElementById('totalPointsEarned').textContent = 
                        `+${totalPointsEarned} civic points earned!`;
                    showStep('stepCelebration');
                } else {
                    alert(result.message || 'Error sending verification email.');
                    this.disabled = false;
                    this.textContent = 'Verify Email';
                }
            } catch (err) {
                console.error('Error:', err);
                alert('Error sending verification email. Please try again.');
                this.disabled = false;
                this.textContent = 'Verify Email';
            }
        });
        
        // Go to profile
        document.getElementById('goToProfileBtn').addEventListener('click', function() {
            window.location.href = '/profile.php';
        });
        
        // Skip handlers
        document.getElementById('skipTown').addEventListener('click', function() {
            dialog.classList.remove('visible');
            logCivicAction('skip_town', 'town_skipped', { state_code: currentState });
        });
        
        document.getElementById('skipEmail').addEventListener('click', function() {
            logCivicAction('skip_email', 'email_skipped', {
                state_code: currentState,
                town: selectedTown,
                zip: selectedZip
            });
            
            // Go to celebration without email
            document.getElementById('welcomeMsg').textContent = 
                `You're now a citizen of ${selectedTown}, ${currentState}!`;
            document.getElementById('totalPointsEarned').textContent = 
                `+${totalPointsEarned} civic points earned!`;
            // Hide email reminder since they skipped
            const emailReminder = document.querySelector('#stepCelebration p[style]');
            if (emailReminder) emailReminder.style.display = 'none';
            showStep('stepCelebration');
        });
        
        // Close dialog
        document.getElementById('dialogClose').addEventListener('click', () => {
            dialog.classList.remove('visible');
        });
        
        // =====================================================
        // MAP INITIALIZATION
        // =====================================================
        fetch('usa-map.svg')
            .then(response => response.text())
            .then(svgContent => {
                document.getElementById('mapHolder').innerHTML = svgContent;
                
                // Add active-gold class to active states AND move to end for rendering order
                const stateGroup = document.querySelector('.state');
                if (stateGroup) {
                    activeStates.forEach(abbr => {
                        const statePath = stateGroup.querySelector('.' + abbr.toLowerCase());
                        if (statePath) {
                            statePath.classList.add('active-gold');
                            stateGroup.appendChild(statePath);
                        }
                    });
                }
                
                initMap();
            })
            .catch(err => {
                console.error('Failed to load map:', err);
                document.getElementById('mapHolder').innerHTML = '<p style="text-align:center;color:#666;">Map loading...</p>';
            });
        
        function initMap() {
            const tooltip = document.getElementById('mapTooltip');
            const statePaths = document.querySelectorAll('.state path, .state circle');
            
            statePaths.forEach(path => {
                const stateClass = Array.from(path.classList).find(c => stateNames[c]);
                if (!stateClass) return;
                
                const stateName = stateNames[stateClass] || stateClass.toUpperCase();
                const isActive = activeStates.includes(stateClass.toUpperCase());
                let lastMousePos = { x: 0, y: 0 };
                
                // Mouse events for tooltip and info modal
                path.addEventListener('mouseenter', (e) => {
                    const nameEl = tooltip.querySelector('.state-name');
                    const statsEl = tooltip.querySelector('.state-stats');
                    
                    nameEl.textContent = stateName;
                    statsEl.textContent = isActive ? 'Active — Click to explore' : 'Click to explore';
                    statsEl.classList.toggle('coming-soon', !isActive);
                    
                    tooltip.classList.add('visible');
                    
                    // Start 5-second timer for info modal
                    clearHoverTimer();
                    currentHoverState = stateClass;
                    const rect = document.querySelector('.map-container').getBoundingClientRect();
                    lastMousePos = { 
                        x: e.clientX - rect.left, 
                        y: e.clientY - rect.top 
                    };
                    
                    hoverTimer = setTimeout(() => {
                        if (currentHoverState === stateClass) {
                            tooltip.classList.remove('visible');
                            showStateInfoModal(stateClass, lastMousePos.x, lastMousePos.y);
                        }
                    }, HOVER_DELAY);
                });
                
                path.addEventListener('mousemove', (e) => {
                    const rect = document.querySelector('.map-container').getBoundingClientRect();
                    tooltip.style.left = (e.clientX - rect.left + 15) + 'px';
                    tooltip.style.top = (e.clientY - rect.top - 10) + 'px';
                    lastMousePos = { 
                        x: e.clientX - rect.left, 
                        y: e.clientY - rect.top 
                    };
                });
                
                path.addEventListener('mouseleave', () => {
                    tooltip.classList.remove('visible');
                    clearHoverTimer();
                    // Modal stays open - closed by X button only
                });
                
                // Click - opens dialog
                path.addEventListener('click', () => {
                    clearHoverTimer();
                    hideStateInfoModal();
                    showStateDialog(stateClass);
                });
            });
        }
        
        // =====================================================
        // SCROLL TRACKING
        // =====================================================
        const scrollMilestones = [25, 50, 75, 100];
        const scrollReached = new Set();
        
        function checkScroll() {
            const scrollHeight = document.documentElement.scrollHeight - window.innerHeight;
            const scrollPercent = Math.round((window.scrollY / scrollHeight) * 100);
            
            scrollMilestones.forEach(m => {
                if (scrollPercent >= m && !scrollReached.has(m)) {
                    scrollReached.add(m);
                    logCivicAction('scroll_depth', 'scroll_' + m, { depth_percent: m });
                }
            });
        }
        
        let scrollThrottle = false;
        window.addEventListener('scroll', function() {
            if (!scrollThrottle) {
                checkScroll();
                scrollThrottle = true;
                setTimeout(() => scrollThrottle = false, 500);
            }
        });
        
        // =====================================================
        // INIT
        // =====================================================
        function init() {
            logPageVisit();
            
            // If returning user had a state, could auto-show dialog
            // But let them click again - fresh start each visit
        }
        
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
        } else {
            init();
        }
        
        // Expose for debugging
        window.TPBCivic = {
            logAction: logCivicAction,
            getSessionId: () => sessionId,
            getPoints: () => civicPoints,
            enableDebug: () => { CONFIG.debug = true; console.log('[TPB] Debug enabled'); }
        };
    })();
    </script>
</body>
</html>
