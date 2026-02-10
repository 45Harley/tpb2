<?php
/**
 * Test Level Manager
 * Run this to verify LevelManager is working
 * 
 * Usage: https://tpb2.sandgems.net/test-level-manager.php?user_id=1
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database connection
$config = [
    'host' => 'localhost',
    'database' => 'sandge5_tpb2',
    'username' => 'sandge5_tpb2',
    'password' => '.YeO6kSJAHh5',
    'charset' => 'utf8mb4'
];

echo "<pre style='background:#1a1a1a; color:#0f0; padding:20px; font-family:monospace;'>";
echo "=== LEVEL MANAGER TEST ===\n\n";

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "✓ Database connected\n\n";
} catch (PDOException $e) {
    die("✗ Database connection failed: " . $e->getMessage());
}

// Check if level-manager.php exists
$levelManagerPath = __DIR__ . '/includes/level-manager.php';
if (!file_exists($levelManagerPath)) {
    die("✗ level-manager.php not found at: $levelManagerPath");
}
echo "✓ level-manager.php found\n";

// Load it
require_once $levelManagerPath;
echo "✓ LevelManager class loaded\n\n";

// Check point_actions table has our new actions
echo "--- Point Actions Check ---\n";
$stmt = $pdo->query("SELECT action_id, action_name, points_value FROM point_actions WHERE action_name IN ('email_verified', 'phone_verified')");
$actions = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (count($actions) == 2) {
    echo "✓ Both point actions exist:\n";
    foreach ($actions as $a) {
        echo "  - {$a['action_name']}: {$a['points_value']} pts (id={$a['action_id']})\n";
    }
} else {
    echo "✗ Missing point actions! Found: " . count($actions) . "\n";
}
echo "\n";

// Get user to test
$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 1;
echo "--- Testing User ID: $userId ---\n";

// Get current user state
$stmt = $pdo->prepare("
    SELECT u.user_id, u.username, u.email, u.identity_level_id, u.civic_points,
           COALESCE(uis.email_verified, 0) as email_verified,
           COALESCE(uis.phone_verified, 0) as phone_verified,
           COALESCE(uis.background_checked, 0) as background_checked
    FROM users u
    LEFT JOIN user_identity_status uis ON u.user_id = uis.user_id
    WHERE u.user_id = ?
");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("✗ User $userId not found");
}

echo "Before checkAndAdvance():\n";
echo "  Username: {$user['username']}\n";
echo "  Email: {$user['email']}\n";
echo "  Current Level: {$user['identity_level_id']}\n";
echo "  Civic Points: {$user['civic_points']}\n";
echo "  Email Verified: " . ($user['email_verified'] ? 'YES' : 'NO') . "\n";
echo "  Phone Verified: " . ($user['phone_verified'] ? 'YES' : 'NO') . "\n";
echo "  Background Checked: " . ($user['background_checked'] ? 'YES' : 'NO') . "\n\n";

// Calculate expected level
$expectedLevel = 1;
if ($user['background_checked']) $expectedLevel = 4;
elseif ($user['phone_verified']) $expectedLevel = 3;
elseif ($user['email_verified']) $expectedLevel = 2;

echo "Expected level based on flags: $expectedLevel\n";
if ($expectedLevel > $user['identity_level_id']) {
    echo "⚠ User should advance from {$user['identity_level_id']} to $expectedLevel\n\n";
} else {
    echo "✓ User is at correct level (no advancement needed)\n\n";
}

// Run checkAndAdvance
echo "--- Running LevelManager::checkAndAdvance() ---\n";
$result = LevelManager::checkAndAdvance($pdo, $userId);
echo "Result:\n";
print_r($result);
echo "\n";

// Check final state
$stmt->execute([$userId]);
$userAfter = $stmt->fetch(PDO::FETCH_ASSOC);

echo "--- After checkAndAdvance() ---\n";
echo "  Current Level: {$userAfter['identity_level_id']}\n";
echo "  Civic Points: {$userAfter['civic_points']}\n";

if ($result['advanced']) {
    echo "\n✓ USER ADVANCED from level {$result['from_level']} ({$result['from_level_name']}) ";
    echo "to level {$result['to_level']} ({$result['to_level_name']})\n";
    echo "  Points awarded: {$result['points_awarded']}\n";
} else {
    echo "\n✓ No advancement needed - user already at correct level\n";
}

// Test getLevel
echo "\n--- Testing LevelManager::getLevel() ---\n";
$levelInfo = LevelManager::getLevel($pdo, $userId);
print_r($levelInfo);

// Test canDo
echo "\n--- Testing LevelManager::canDo() ---\n";
echo "  can_view: " . (LevelManager::canDo($pdo, $userId, 'view') ? 'YES' : 'NO') . "\n";
echo "  can_vote: " . (LevelManager::canDo($pdo, $userId, 'vote') ? 'YES' : 'NO') . "\n";
echo "  can_post: " . (LevelManager::canDo($pdo, $userId, 'post') ? 'YES' : 'NO') . "\n";
echo "  can_respond: " . (LevelManager::canDo($pdo, $userId, 'respond') ? 'YES' : 'NO') . "\n";

echo "\n=== TEST COMPLETE ===\n";
echo "</pre>";
