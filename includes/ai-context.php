<?php
/**
 * TPB AI Context Builder
 * ======================
 * Builds dynamic context about the current user for AI assistants
 * 
 * tpb-tags: ai, context, dynamic, user-data
 * tpb-roles: developer, clerk:guide, clerk:town-builder
 * tpb-toc: [
 *   {"id": "build-context", "title": "Build AI Context"},
 *   {"id": "get-reps", "title": "Get Representatives"},
 *   {"id": "helpers", "title": "Helper Functions"}
 * ]
 * 
 * Usage:
 *   require_once 'includes/ai-context.php';
 *   $aiContext = buildAIContext($pdo, $dbUser);
 */

// === BUILD CONTEXT ===
// #build-context
/**
 * Build complete context for AI about this user
 * Returns both structured data and formatted text for AI prompt
 * 
 * @param PDO $pdo Database connection
 * @param array|null $dbUser User record from database
 * @return array ['text' => string, 'data' => array, 'hasReps' => bool]
 */
function buildAIContext($pdo, $dbUser) {
    $context = [
        'text' => '',
        'data' => [],
        'hasReps' => false
    ];
    
    if (!$dbUser) {
        $context['text'] = "## User Context\nUser is not logged in - Anonymous visitor.";
        return $context;
    }
    
    $lines = ["## User Context"];
    $lines[] = "- Name: " . ($dbUser['first_name'] ?? 'Friend');
    $lines[] = "- User ID: " . $dbUser['user_id'];
    $lines[] = "- Email Verified: " . ($dbUser['email_verified'] ? 'Yes' : 'No');
    
    $context['data']['user'] = [
        'name' => $dbUser['first_name'] ?? 'Friend',
        'user_id' => $dbUser['user_id'],
        'email_verified' => (bool)$dbUser['email_verified']
    ];
    
    // Get location and representatives
    if ($dbUser['current_town_id']) {
        $location = getTownInfo($pdo, $dbUser['current_town_id']);
        
        if ($location) {
            $lines[] = "- Location: {$location['town_name']}, {$location['abbreviation']}";
            $lines[] = "- US Congress District: {$location['us_congress_district']}";
            $lines[] = "- State Senate District: {$location['state_senate_district']}";
            $lines[] = "- State House District: {$location['state_house_district']}";
            
            $context['data']['location'] = [
                'town' => $location['town_name'],
                'town_id' => $dbUser['current_town_id'],
                'state' => $location['abbreviation'],
                'congress' => $location['us_congress_district'],
                'senate' => $location['state_senate_district'],
                'house' => $location['state_house_district']
            ];
            
            // Get representatives
            $reps = getRepresentatives($pdo, $location);
            if (!empty($reps)) {
                $lines[] = "";
                $lines[] = "## Your Elected Representatives";
                $context['data']['representatives'] = [];
                
                foreach ($reps as $rep) {
                    $repLine = "- {$rep['full_name']} ({$rep['title']}, {$rep['party']})";
                    if ($rep['email']) $repLine .= " | Email: {$rep['email']}";
                    if ($rep['phone']) $repLine .= " | Phone: {$rep['phone']}";
                    $lines[] = $repLine;
                    
                    $context['data']['representatives'][] = $rep;
                }
                $context['hasReps'] = true;
            }
        }
    }
    
    $context['text'] = implode("\n", $lines);
    return $context;
}

// === GET REPRESENTATIVES ===
// #get-reps
/**
 * Get town info from database
 * @param PDO $pdo
 * @param int $townId
 * @return array|null
 */
function getTownInfo($pdo, $townId) {
    $stmt = $pdo->prepare("
        SELECT t.town_name, t.us_congress_district, t.state_senate_district, t.state_house_district,
               s.state_name, s.abbreviation
        FROM towns t
        JOIN states s ON t.state_id = s.state_id
        WHERE t.town_id = ?
    ");
    $stmt->execute([$townId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get all representatives for a location
 * @param PDO $pdo
 * @param array $location Town info with districts
 * @return array Representatives
 */
function getRepresentatives($pdo, $location) {
    $reps = [];
    $stateCode = strtolower($location['abbreviation']);
    
    // Build OCD IDs for district reps
    $ocdIds = [];
    if ($location['us_congress_district']) {
        $ocdIds[] = "ocd-division/country:us/state:{$stateCode}/cd:{$location['us_congress_district']}";
    }
    if ($location['state_senate_district']) {
        $ocdIds[] = "ocd-division/country:us/state:{$stateCode}/sldu:{$location['state_senate_district']}";
    }
    if ($location['state_house_district']) {
        $ocdIds[] = "ocd-division/country:us/state:{$stateCode}/sldl:{$location['state_house_district']}";
    }
    
    // Get district representatives
    if (!empty($ocdIds)) {
        $placeholders = implode(',', array_fill(0, count($ocdIds), '?'));
        $stmt = $pdo->prepare("
            SELECT DISTINCT full_name, title, party, email, phone, website
            FROM elected_officials
            WHERE ocd_id IN ($placeholders)
            AND is_current = 1
        ");
        $stmt->execute($ocdIds);
        $reps = array_merge($reps, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    
    // Get statewide officials
    $stmt = $pdo->prepare("
        SELECT DISTINCT full_name, title, party, email, phone, website
        FROM elected_officials
        WHERE state_code = ?
        AND is_current = 1
        AND title IN ('Governor', 'U.S. Senator', 'Lieutenant Governor', 'Attorney General')
        ORDER BY 
            CASE title 
                WHEN 'Governor' THEN 1 
                WHEN 'U.S. Senator' THEN 2 
                WHEN 'Lieutenant Governor' THEN 3
                ELSE 4 
            END
    ");
    $stmt->execute([strtoupper($location['abbreviation'])]);
    $reps = array_merge($reps, $stmt->fetchAll(PDO::FETCH_ASSOC));
    
    return $reps;
}

// === HELPER FUNCTIONS ===
// #helpers
/**
 * Get just the formatted text for AI (convenience function)
 * @param PDO $pdo
 * @param array|null $dbUser
 * @return string
 */
function getAIContextText($pdo, $dbUser) {
    $context = buildAIContext($pdo, $dbUser);
    return $context['text'];
}

/**
 * Get representatives as simple array for display
 * @param PDO $pdo
 * @param array|null $dbUser
 * @return array
 */
function getUserRepresentatives($pdo, $dbUser) {
    $context = buildAIContext($pdo, $dbUser);
    return $context['data']['representatives'] ?? [];
}
