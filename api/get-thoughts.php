<?php
/**
 * TPB2 Get Thoughts API
 * =====================
 * Fetch published thoughts with flexible filtering
 * 
 * GET /api/get-thoughts.php
 * 
 * Parameters:
 *   town_id    - Filter by specific town (e.g., 119 for Putnam)
 *   state_id   - Filter by state (e.g., 7 for CT)
 *   is_federal - Set to 1 for federal-level thoughts only
 *   limit      - Number of results (default 20, max 100)
 *   sort       - 'recent' (default) or 'votes'
 * 
 * Examples:
 *   /api/get-thoughts.php?town_id=119              → Putnam thoughts
 *   /api/get-thoughts.php?state_id=7               → All CT thoughts
 *   /api/get-thoughts.php?is_federal=1             → Federal thoughts
 *   /api/get-thoughts.php?town_id=119&sort=votes   → Putnam by popularity
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$config = require __DIR__ . '/../config.php';

// Parse parameters
$townId = isset($_GET['town_id']) ? (int)$_GET['town_id'] : null;
$stateId = isset($_GET['state_id']) ? (int)$_GET['state_id'] : null;
$isFederal = isset($_GET['is_federal']) ? (int)$_GET['is_federal'] : null;
$limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 20;
$sort = isset($_GET['sort']) && $_GET['sort'] === 'votes' ? 'votes' : 'recent';

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Build query - includes user display preferences
    $query = "
        SELECT 
            t.thought_id,
            t.user_id,
            t.content,
            t.jurisdiction_level,
            t.is_local,
            t.is_state,
            t.is_federal,
            t.other_topic,
            t.created_at,
            t.upvotes,
            t.downvotes,
            c.category_name,
            c.icon,
            u.username,
            u.first_name,
            u.last_name,
            u.age_bracket,
            u.show_first_name,
            u.show_last_name,
            u.show_age_bracket,
            s.abbreviation as state_abbrev,
            s.state_name,
            tw.town_name
        FROM user_thoughts t
        LEFT JOIN thought_categories c ON t.category_id = c.category_id
        LEFT JOIN users u ON t.user_id = u.user_id
        LEFT JOIN states s ON t.state_id = s.state_id
        LEFT JOIN towns tw ON t.town_id = tw.town_id
        WHERE t.status = 'published'
    ";
    
    $params = [];
    
    // Apply filters
    if ($townId) {
        $query .= " AND t.town_id = ?";
        $params[] = $townId;
    }
    
    if ($stateId && !$townId) {
        // State filter only if no town specified (town implies state)
        $query .= " AND t.state_id = ?";
        $params[] = $stateId;
    }
    
    if ($isFederal === 1) {
        $query .= " AND t.is_federal = 1";
    }
    
    // Sort order
    if ($sort === 'votes') {
        $query .= " ORDER BY t.upvotes DESC, t.created_at DESC";
    } else {
        $query .= " ORDER BY t.created_at DESC";
    }
    
    // Limit
    $query .= " LIMIT " . (int)$limit;
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $thoughts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format response
    $response = [
        'status' => 'success',
        'count' => count($thoughts),
        'filters' => [
            'town_id' => $townId,
            'state_id' => $stateId,
            'is_federal' => $isFederal,
            'sort' => $sort,
            'limit' => $limit
        ],
        'thoughts' => array_map(function($t) {
            // Build display name based on user preferences
            $displayName = buildDisplayName(
                $t['first_name'],
                $t['last_name'],
                $t['show_first_name'],
                $t['show_last_name'],
                $t['username']
            );
            
            // Build age display if enabled
            $ageDisplay = ($t['show_age_bracket'] && $t['age_bracket']) ? $t['age_bracket'] : null;
            
            return [
                'thought_id' => (int)$t['thought_id'],
                'content' => $t['content'],
                'display_name' => $displayName,
                'age_bracket' => $ageDisplay,
                'username' => $t['username'] ?: 'Anonymous', // Keep for backwards compat
                'category' => $t['category_name'],
                'icon' => $t['icon'],
                'town' => $t['town_name'],
                'state' => $t['state_abbrev'],
                'jurisdiction' => $t['jurisdiction_level'],
                'is_local' => (bool)$t['is_local'],
                'is_state' => (bool)$t['is_state'],
                'is_federal' => (bool)$t['is_federal'],
                'upvotes' => (int)$t['upvotes'],
                'downvotes' => (int)$t['downvotes'],
                'created_at' => $t['created_at'],
                'time_ago' => timeAgo($t['created_at'])
            ];
        }, $thoughts)
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error'
    ]);
}

/**
 * Build display name based on user preferences
 * Priority: First+Last > First only > Last only > Anonymous
 */
function buildDisplayName($firstName, $lastName, $showFirst, $showLast, $username) {
    $parts = [];
    
    // Check preferences (default to showing first name if prefs not set)
    $showFirst = $showFirst ?? 1;
    $showLast = $showLast ?? 0;
    
    if ($showFirst && $firstName) {
        $parts[] = $firstName;
    }
    
    if ($showLast && $lastName) {
        $parts[] = $lastName;
    }
    
    if (!empty($parts)) {
        return implode(' ', $parts);
    }
    
    // Fallback: Anonymous (don't show auto-generated usernames)
    return 'Anonymous';
}

/**
 * Convert timestamp to human-readable "time ago"
 */
function timeAgo($datetime) {
    $now = new DateTime();
    $then = new DateTime($datetime);
    $diff = $now->diff($then);
    
    if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    if ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    if ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    if ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    if ($diff->i > 0) return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
    return 'just now';
}
