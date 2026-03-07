<?php
/**
 * Feed API — JSON endpoint for the Civic Engine feed.
 *
 * Actions via ?action= parameter:
 *   sync   (POST, admin required) — Run all feed syncs
 *   feed   (GET, public)          — Get auto-generated feed items
 *   stats  (GET, public)          — Get poll counts by source type
 */

header('Content-Type: application/json');

$config = require __DIR__ . '/../config.php';
$pdo = new PDO(
    'mysql:host=' . $config['host'] . ';dbname=' . $config['database'] . ';charset=' . ($config['charset'] ?? 'utf8mb4'),
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

require_once __DIR__ . '/../includes/get-user.php';
require_once __DIR__ . '/../includes/feed-engine.php';

$dbUser = getUser($pdo);
$action = $_GET['action'] ?? '';

switch ($action) {

    /* ------------------------------------------------------------------ */
    /*  SYNC (admin only)                                                 */
    /* ------------------------------------------------------------------ */
    case 'sync':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST required.']);
            exit;
        }

        if (!$dbUser) {
            http_response_code(401);
            echo json_encode(['error' => 'Login required.']);
            exit;
        }

        // Check admin role (role_id = 1)
        $adminCheck = $pdo->prepare(
            "SELECT 1 FROM user_role_membership WHERE user_id = :uid AND role_id = 1 LIMIT 1"
        );
        $adminCheck->execute([':uid' => $dbUser['user_id']]);
        if (!$adminCheck->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin role required.']);
            exit;
        }

        $results = FeedEngine::syncAll($pdo);
        echo json_encode(['success' => true, 'results' => $results]);
        break;

    /* ------------------------------------------------------------------ */
    /*  FEED (public)                                                     */
    /* ------------------------------------------------------------------ */
    case 'feed':
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            echo json_encode(['error' => 'GET required.']);
            exit;
        }

        $scopeType = $_GET['scope_type'] ?? null;
        $scopeId   = $_GET['scope_id']   ?? null;
        $limit     = min((int) ($_GET['limit'] ?? 20), 100);
        $offset    = max((int) ($_GET['offset'] ?? 0), 0);

        if ($limit < 1) $limit = 20;

        // Validate scope_type if provided
        $validScopes = ['federal', 'state', 'town', 'group'];
        if ($scopeType !== null && !in_array($scopeType, $validScopes, true)) {
            http_response_code(400);
            echo json_encode(['error' => "Invalid scope_type: $scopeType"]);
            exit;
        }

        $items = FeedEngine::getFeedItems($pdo, $scopeType, $scopeId, $limit, $offset);
        echo json_encode(['success' => true, 'items' => $items, 'count' => count($items)]);
        break;

    /* ------------------------------------------------------------------ */
    /*  STATS (public)                                                    */
    /* ------------------------------------------------------------------ */
    case 'stats':
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            echo json_encode(['error' => 'GET required.']);
            exit;
        }

        $stats = FeedEngine::getStats($pdo);
        echo json_encode(['success' => true, 'stats' => $stats]);
        break;

    /* ------------------------------------------------------------------ */
    /*  DEFAULT                                                           */
    /* ------------------------------------------------------------------ */
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action. Valid: sync, feed, stats']);
        break;
}
