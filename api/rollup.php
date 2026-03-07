<?php
/**
 * Roll-Up API — Phase 5 of the Civic Engine.
 *
 * JSON endpoint for civic data aggregation.
 *
 * Endpoints (via GET ?action=...):
 *   town         — params: town_id         → getTownRollup
 *   state        — params: state_abbr      → getStateRollup
 *   federal      — (no params)             → getFederalRollup
 *   convergence  — params: scope_type, scope_id → findConvergence
 *   beam         — params: scope_type, scope_id → beamToDesk (requires login)
 */

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/../config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

require_once __DIR__ . '/../includes/get-user.php';
require_once __DIR__ . '/../includes/rollup.php';

$dbUser = getUser($pdo);

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'town':
            $townId = isset($_GET['town_id']) ? (int) $_GET['town_id'] : 0;
            if ($townId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'town_id is required.']);
                exit;
            }
            $data = Rollup::getTownRollup($pdo, $townId);
            break;

        case 'state':
            $stateAbbr = $_GET['state_abbr'] ?? '';
            if (empty($stateAbbr) || !preg_match('/^[a-zA-Z]{2}$/', $stateAbbr)) {
                http_response_code(400);
                echo json_encode(['error' => 'state_abbr is required (2-letter abbreviation).']);
                exit;
            }
            $data = Rollup::getStateRollup($pdo, $stateAbbr);
            break;

        case 'federal':
            $data = Rollup::getFederalRollup($pdo);
            break;

        case 'convergence':
            $scopeType = $_GET['scope_type'] ?? 'federal';
            $scopeId   = $_GET['scope_id'] ?? null;
            if (!in_array($scopeType, ['federal', 'state', 'town'], true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid scope_type.']);
                exit;
            }
            $data = Rollup::findConvergence($pdo, $scopeType, $scopeId);
            break;

        case 'beam':
            if (!$dbUser) {
                http_response_code(401);
                echo json_encode(['error' => 'Login required for Beam to Desk.']);
                exit;
            }
            $scopeType = $_GET['scope_type'] ?? '';
            $scopeId   = $_GET['scope_id'] ?? '';
            if (empty($scopeType) || empty($scopeId)) {
                http_response_code(400);
                echo json_encode(['error' => 'scope_type and scope_id are required.']);
                exit;
            }
            if (!in_array($scopeType, ['federal', 'state', 'town'], true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid scope_type.']);
                exit;
            }
            $data = Rollup::beamToDesk($pdo, $scopeType, $scopeId);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action. Use: town, state, federal, convergence, beam.']);
            exit;
    }

    if (isset($data['error'])) {
        http_response_code(404);
    }

    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error.']);
}
