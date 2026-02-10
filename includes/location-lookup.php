<?php
/**
 * Location Lookup Module for TPB
 * 
 * Provides functions for looking up locations from the local database
 * instead of relying on external APIs like Nominatim.
 * 
 * Uses:
 * - zip_codes table (41,481 US zip codes with lat/lon)
 * - towns table (~29,500 unique place+state combos)
 * - states table (for state_id lookup)
 * 
 * @package TPB
 * @since 2025-12-22
 */

/**
 * Look up location data by zip code
 * 
 * @param PDO $pdo Database connection
 * @param string $zipCode 5-digit zip code
 * @return array|null Location data or null if not found
 */
function lookupByZip($pdo, $zipCode) {
    // Validate zip code format
    $zipCode = preg_replace('/[^0-9]/', '', $zipCode);
    if (strlen($zipCode) !== 5) {
        return null;
    }
    
    $sql = "
        SELECT 
            z.zip_code,
            z.place,
            z.state_code,
            z.state_name,
            z.county,
            z.latitude,
            z.longitude,
            s.state_id
        FROM zip_codes z
        LEFT JOIN states s ON s.abbreviation = z.state_code
        WHERE z.zip_code = ?
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array($zipCode));
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        return null;
    }
    
    // Cast numeric fields
    $result['latitude'] = $result['latitude'] !== null ? (float)$result['latitude'] : null;
    $result['longitude'] = $result['longitude'] !== null ? (float)$result['longitude'] : null;
    $result['state_id'] = $result['state_id'] !== null ? (int)$result['state_id'] : null;
    
    return $result;
}

/**
 * Find town_id for a place + state combination
 * 
 * @param PDO $pdo Database connection
 * @param string $placeName Town/city name from zip_codes
 * @param string $stateCode 2-letter state abbreviation
 * @return int|null town_id or null if not found
 */
function findTownId($pdo, $placeName, $stateCode) {
    // Get state_id first
    $stmt = $pdo->prepare("SELECT state_id FROM states WHERE abbreviation = ?");
    $stmt->execute(array(strtoupper($stateCode)));
    $stateId = $stmt->fetchColumn();
    
    if (!$stateId) {
        return null;
    }
    
    // Find town - case-insensitive match
    $stmt = $pdo->prepare("
        SELECT town_id FROM towns 
        WHERE LOWER(town_name) = LOWER(?) AND state_id = ?
    ");
    $stmt->execute(array($placeName, $stateId));
    $townId = $stmt->fetchColumn();
    
    return $townId ? (int)$townId : null;
}

/**
 * Search towns by partial name (for autocomplete)
 * 
 * @param PDO $pdo Database connection
 * @param string $query Search query (2+ characters)
 * @param string|null $stateCode Optional state filter
 * @param int $limit Maximum results to return
 * @return array List of matching towns
 */
function searchTowns($pdo, $query, $stateCode = null, $limit = 10) {
    $query = trim($query);
    if (strlen($query) < 2) {
        return array();
    }
    
    $params = array();
    $sql = "
        SELECT 
            t.town_id,
            t.town_name,
            s.abbreviation as state_code,
            s.state_name,
            (SELECT z.latitude FROM zip_codes z 
             WHERE z.place = t.town_name AND z.state_code = s.abbreviation 
             LIMIT 1) as latitude,
            (SELECT z.longitude FROM zip_codes z 
             WHERE z.place = t.town_name AND z.state_code = s.abbreviation 
             LIMIT 1) as longitude
        FROM towns t
        JOIN states s ON s.state_id = t.state_id
        WHERE t.town_name LIKE ?
    ";
    $params[] = $query . '%';
    
    if ($stateCode) {
        $sql .= " AND s.abbreviation = ?";
        $params[] = strtoupper($stateCode);
    }
    
    $sql .= " ORDER BY t.town_name, s.abbreviation LIMIT " . (int)$limit;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Cast numeric fields
    foreach ($results as &$row) {
        $row['town_id'] = (int)$row['town_id'];
        $row['latitude'] = $row['latitude'] !== null ? (float)$row['latitude'] : null;
        $row['longitude'] = $row['longitude'] !== null ? (float)$row['longitude'] : null;
    }
    
    return $results;
}

/**
 * Get coordinates for a town from zip_codes table
 * 
 * @param PDO $pdo Database connection
 * @param string $townName Town/city name
 * @param string $stateCode 2-letter state abbreviation
 * @return array|null array with latitude/longitude or null if not found
 */
function getTownCoordinates($pdo, $townName, $stateCode) {
    $stmt = $pdo->prepare("
        SELECT latitude, longitude 
        FROM zip_codes 
        WHERE place = ? AND state_code = ?
        AND latitude IS NOT NULL
        ORDER BY zip_code
        LIMIT 1
    ");
    $stmt->execute(array($townName, strtoupper($stateCode)));
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result || $result['latitude'] === null) {
        return null;
    }
    
    return array(
        'latitude' => (float)$result['latitude'],
        'longitude' => (float)$result['longitude']
    );
}

/**
 * Complete location lookup from zip code
 * Returns all data needed to save user's location
 * 
 * @param PDO $pdo Database connection
 * @param string $zipCode 5-digit zip code
 * @return array|null Complete location data or null if zip not found
 */
function completeZipLookup($pdo, $zipCode) {
    $location = lookupByZip($pdo, $zipCode);
    
    if (!$location) {
        return null;
    }
    
    // Find the town_id
    $townId = findTownId($pdo, $location['place'], $location['state_code']);
    $location['town_id'] = $townId;
    
    return $location;
}

/**
 * Get all zip codes for a town (for debugging/admin)
 * 
 * @param PDO $pdo Database connection
 * @param string $townName Town/city name
 * @param string $stateCode 2-letter state abbreviation
 * @return array List of zip codes
 */
function getZipsForTown($pdo, $townName, $stateCode) {
    $stmt = $pdo->prepare("
        SELECT zip_code 
        FROM zip_codes 
        WHERE place = ? AND state_code = ?
        ORDER BY zip_code
    ");
    $stmt->execute(array($townName, strtoupper($stateCode)));
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}
