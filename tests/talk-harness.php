<?php
/**
 * Talk Test Harness — Multi-User Automated Integration Tests
 *
 * Visit this page in a browser to run tests against the Talk API.
 * Uses curl with Cookie headers to simulate 4 different users.
 * See docs/talk-test-harness.md for full specification.
 *
 * Modes:
 *   GET  (no params)      → Render dashboard UI
 *   GET  ?run=lifecycle    → Run scenario, return JSON
 *   GET  ?run=access       → Run access control scenario
 *   GET  ?run=links        → Run idea links scenario
 *   GET  ?run=edge         → Run edge cases scenario
 *   GET  ?run=all          → Run all scenarios
 *   GET  ?clean=1          → Cleanup only
 *   GET  ?auto=1           → Auto-run all (for Playwright)
 */

// ── Config ──────────────────────────────────────────────────────────

$BASE_URL = getenv('TALK_TEST_URL') ?: ('http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
$PREFIX   = '__TEST_HARNESS__';

$USERS = [
    'facilitator' => ['user_id' => 1,  'label' => 'Harley H',  'color' => '#4fc3f7'],
    'member1'     => ['user_id' => 10, 'label' => 'har',       'color' => '#81c784'],
    'member2'     => ['user_id' => 32, 'label' => 'hh',        'color' => '#ffb74d'],
    'observer'    => ['user_id' => 33, 'label' => 'Houston',   'color' => '#ce93d8'],
];

// ── API Helper ──────────────────────────────────────────────────────

function talkApi($baseUrl, $userId, $method, $action, $data = []) {
    $url = $baseUrl . '/talk/api.php?action=' . urlencode($action);

    // GET params go on URL
    if ($method === 'GET' && $data) {
        $url .= '&' . http_build_query($data);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 TPB-TestHarness/1.0',
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Cookie: tpb_user_id=' . $userId,
        ],
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $t0   = microtime(true);
    $raw  = curl_exec($ch);
    $ms   = round((microtime(true) - $t0) * 1000);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    return [
        'http_code'   => $code,
        'data'        => json_decode($raw, true),
        'raw'         => $raw,
        'duration_ms' => $ms,
        'curl_error'  => $err,
    ];
}

// ── Step Builder ────────────────────────────────────────────────────

function step($num, $userKey, $action, $desc, $expect, $result) {
    $success = $result['data']['success'] ?? false;
    $error   = $result['data']['error'] ?? '';

    if ($expect === 'success') {
        $passed = $success === true;
    } else {
        // expect failure
        $passed = $success === false;
    }

    $snippet = $success
        ? json_encode(array_diff_key($result['data'], ['success' => 1]), JSON_UNESCAPED_SLASHES)
        : ($error ?: substr($result['raw'] ?? '', 0, 200));

    if (strlen($snippet) > 150) $snippet = substr($snippet, 0, 147) . '...';

    return [
        'step'     => $num,
        'user'     => $userKey,
        'action'   => $action,
        'desc'     => $desc,
        'expect'   => $expect,
        'passed'   => $passed,
        'success'  => $success,
        'snippet'  => $snippet,
        'duration' => $result['duration_ms'],
        'http'     => $result['http_code'],
    ];
}

// ── Cleanup ─────────────────────────────────────────────────────────

function cleanup($baseUrl) {
    // Direct DB cleanup — we're on the same server
    $configPath = __DIR__ . '/../config.php';
    if (!file_exists($configPath)) return ['cleaned' => false, 'error' => 'config.php not found'];

    $config = require $configPath;
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4",
        $config['username'], $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $prefix = '__TEST_HARNESS__%';

    // Get test group IDs
    $stmt = $pdo->prepare("SELECT id FROM idea_groups WHERE name LIKE ?");
    $stmt->execute([$prefix]);
    $groupIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $deleted = ['links' => 0, 'members' => 0, 'ideas' => 0, 'groups' => 0];

    if ($groupIds) {
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));

        // 1. Idea links
        $stmt = $pdo->prepare("DELETE FROM idea_links WHERE idea_id_a IN (SELECT id FROM idea_log WHERE group_id IN ($placeholders)) OR idea_id_b IN (SELECT id FROM idea_log WHERE group_id IN ($placeholders))");
        $stmt->execute(array_merge($groupIds, $groupIds));
        $deleted['links'] = $stmt->rowCount();

        // 2. Group members
        $stmt = $pdo->prepare("DELETE FROM idea_group_members WHERE group_id IN ($placeholders)");
        $stmt->execute($groupIds);
        $deleted['members'] = $stmt->rowCount();

        // 3. Ideas
        $stmt = $pdo->prepare("DELETE FROM idea_log WHERE group_id IN ($placeholders)");
        $stmt->execute($groupIds);
        $deleted['ideas'] = $stmt->rowCount();

        // 4. Groups
        $stmt = $pdo->prepare("DELETE FROM idea_groups WHERE id IN ($placeholders)");
        $stmt->execute($groupIds);
        $deleted['groups'] = $stmt->rowCount();
    }

    return ['cleaned' => true, 'deleted' => $deleted];
}

// ── Scenario 1: Group Lifecycle ─────────────────────────────────────

function scenarioLifecycle($baseUrl, $users, $prefix) {
    $steps = [];
    $s = 0;
    $F = $users['facilitator']['user_id'];
    $M1 = $users['member1']['user_id'];
    $M2 = $users['member2']['user_id'];
    $O = $users['observer']['user_id'];

    // Step 1: Facilitator creates group
    $r = talkApi($baseUrl, $F, 'POST', 'create_group', [
        'name' => $prefix . ' Deliberation ' . date('His'),
        'description' => 'Test group for automated harness',
        'tags' => 'test, harness, automated',
        'access_level' => 'open',
    ]);
    $steps[] = step(++$s, 'facilitator', 'create_group', 'Create test group', 'success', $r);
    $groupId = $r['data']['group_id'] ?? 0;

    // Step 2: Member1 joins
    $r = talkApi($baseUrl, $M1, 'POST', 'join_group', ['group_id' => $groupId]);
    $steps[] = step(++$s, 'member1', 'join_group', 'Join test group', 'success', $r);

    // Step 3: Member2 joins
    $r = talkApi($baseUrl, $M2, 'POST', 'join_group', ['group_id' => $groupId]);
    $steps[] = step(++$s, 'member2', 'join_group', 'Join test group', 'success', $r);

    // Step 4: Observer joins (gets member role — open group)
    $r = talkApi($baseUrl, $O, 'POST', 'join_group', ['group_id' => $groupId]);
    $steps[] = step(++$s, 'observer', 'join_group', 'Join test group', 'success', $r);

    // Step 5: Facilitator sets observer role
    $r = talkApi($baseUrl, $F, 'POST', 'update_member', [
        'group_id' => $groupId,
        'user_id'  => $O,
        'role'     => 'observer',
    ]);
    $steps[] = step(++$s, 'facilitator', 'update_member', "Set Houston to observer", 'success', $r);

    // Step 6: Facilitator saves idea
    $r = talkApi($baseUrl, $F, 'POST', 'save', [
        'content'  => 'We should increase youth voter registration',
        'group_id' => $groupId,
    ]);
    $steps[] = step(++$s, 'facilitator', 'save', 'Save idea to group', 'success', $r);
    $ideaF = $r['data']['idea']['id'] ?? $r['data']['id'] ?? 0;

    // Step 7: Member1 saves idea
    $r = talkApi($baseUrl, $M1, 'POST', 'save', [
        'content'  => 'Local community centers need WiFi upgrades',
        'group_id' => $groupId,
    ]);
    $steps[] = step(++$s, 'member1', 'save', 'Save idea to group', 'success', $r);
    $ideaM1 = $r['data']['idea']['id'] ?? $r['data']['id'] ?? 0;

    // Step 8: Member2 saves idea
    $r = talkApi($baseUrl, $M2, 'POST', 'save', [
        'content'  => 'Senior citizens need transportation to polling places',
        'group_id' => $groupId,
    ]);
    $steps[] = step(++$s, 'member2', 'save', 'Save idea to group', 'success', $r);
    $ideaM2 = $r['data']['idea']['id'] ?? $r['data']['id'] ?? 0;

    // Step 9: Observer tries to save — BLOCKED
    $r = talkApi($baseUrl, $O, 'POST', 'save', [
        'content'  => 'This should be blocked',
        'group_id' => $groupId,
    ]);
    $steps[] = step(++$s, 'observer', 'save', 'Save idea (observer blocked)', 'fail', $r);

    // Step 10: Facilitator loads history
    $r = talkApi($baseUrl, $F, 'GET', 'history', ['group_id' => $groupId, 'limit' => 50]);
    $ideaCount = count($r['data']['ideas'] ?? []);
    $steps[] = step(++$s, 'facilitator', 'history', "Load history (got $ideaCount ideas)", 'success', $r);

    // Step 11: Member1 edits own idea
    $r = talkApi($baseUrl, $M1, 'POST', 'edit', [
        'idea_id' => $ideaM1,
        'content' => 'Local community centers need WiFi upgrades — updated by member1',
    ]);
    $steps[] = step(++$s, 'member1', 'edit', 'Edit own idea', 'success', $r);

    // Step 12: Member2 tries to edit facilitator's idea — BLOCKED
    $r = talkApi($baseUrl, $M2, 'POST', 'edit', [
        'idea_id' => $ideaF,
        'content' => 'Attempted hijack',
    ]);
    $steps[] = step(++$s, 'member2', 'edit', "Edit facilitator's idea (blocked)", 'fail', $r);

    // Step 13: Facilitator promotes own idea
    $r = talkApi($baseUrl, $F, 'POST', 'promote', [
        'idea_id' => $ideaF,
        'status'  => 'refining',
    ]);
    $steps[] = step(++$s, 'facilitator', 'promote', 'Promote idea to refining', 'success', $r);

    // Step 14: Member1 promotes own idea
    $r = talkApi($baseUrl, $M1, 'POST', 'promote', [
        'idea_id' => $ideaM1,
        'status'  => 'refining',
    ]);
    $steps[] = step(++$s, 'member1', 'promote', 'Promote idea to refining', 'success', $r);

    // Step 15: Facilitator toggles shareable
    $r = talkApi($baseUrl, $F, 'POST', 'toggle_shareable', [
        'idea_id'   => $ideaF,
        'shareable' => 1,
    ]);
    $steps[] = step(++$s, 'facilitator', 'toggle_shareable', 'Toggle shareable on', 'success', $r);

    // Step 16: Member1 tries to delete member2's idea — BLOCKED
    $r = talkApi($baseUrl, $M1, 'POST', 'delete', ['idea_id' => $ideaM2]);
    $steps[] = step(++$s, 'member1', 'delete', "Delete member2's idea (blocked)", 'fail', $r);

    // Step 17: Member2 deletes own idea
    $r = talkApi($baseUrl, $M2, 'POST', 'delete', ['idea_id' => $ideaM2]);
    $steps[] = step(++$s, 'member2', 'delete', 'Delete own idea', 'success', $r);

    // Step 18: Facilitator updates group description
    $r = talkApi($baseUrl, $F, 'POST', 'update_group', [
        'group_id'    => $groupId,
        'description' => 'Updated by test harness',
    ]);
    $steps[] = step(++$s, 'facilitator', 'update_group', 'Update group description', 'success', $r);

    // Step 19: Member1 tries to update group — BLOCKED
    $r = talkApi($baseUrl, $M1, 'POST', 'update_group', [
        'group_id'    => $groupId,
        'description' => 'Attempted by member',
    ]);
    $steps[] = step(++$s, 'member1', 'update_group', 'Update group (member blocked)', 'fail', $r);

    // Step 20: Facilitator gets group detail
    $r = talkApi($baseUrl, $F, 'GET', 'get_group', ['group_id' => $groupId]);
    $memberCount = count($r['data']['members'] ?? []);
    $steps[] = step(++$s, 'facilitator', 'get_group', "Get group detail ($memberCount members)", 'success', $r);

    // Step 21: Member1 leaves group
    $r = talkApi($baseUrl, $M1, 'POST', 'leave_group', ['group_id' => $groupId]);
    $steps[] = step(++$s, 'member1', 'leave_group', 'Leave group', 'success', $r);

    // Step 22: Member1 tries to save after leaving — BLOCKED
    $r = talkApi($baseUrl, $M1, 'POST', 'save', [
        'content'  => 'Should fail — not a member',
        'group_id' => $groupId,
    ]);
    $steps[] = step(++$s, 'member1', 'save', 'Save after leaving (blocked)', 'fail', $r);

    // Step 23: Facilitator lists groups
    $r = talkApi($baseUrl, $F, 'GET', 'list_groups', ['mine' => 1]);
    $steps[] = step(++$s, 'facilitator', 'list_groups', 'List my groups', 'success', $r);

    return $steps;
}

// ── Scenario 2: Access Control ──────────────────────────────────────

function scenarioAccess($baseUrl, $users, $prefix) {
    $steps = [];
    $s = 0;
    $F = $users['facilitator']['user_id'];
    $M1 = $users['member1']['user_id'];
    $M2 = $users['member2']['user_id'];
    $O = $users['observer']['user_id'];

    // Step 1: Observer creates own group (anyone can)
    $r = talkApi($baseUrl, $O, 'POST', 'create_group', [
        'name' => $prefix . ' Observer Group',
        'access_level' => 'closed',
    ]);
    $steps[] = step(++$s, 'observer', 'create_group', 'Observer creates own group', 'success', $r);
    $obsGroup = $r['data']['group_id'] ?? 0;

    // Step 2: Member1 saves to observer's closed group without joining — BLOCKED
    $r = talkApi($baseUrl, $M1, 'POST', 'save', [
        'content'  => 'Should fail — not a member',
        'group_id' => $obsGroup,
    ]);
    $steps[] = step(++$s, 'member1', 'save', 'Save to unjoined group (blocked)', 'fail', $r);

    // Step 3: Member1 tries update_member — BLOCKED (not facilitator)
    // First need a group where member1 IS a member. Use lifecycle pattern.
    $r = talkApi($baseUrl, $F, 'POST', 'create_group', [
        'name' => $prefix . ' Access Test Group',
        'access_level' => 'open',
    ]);
    $aGroup = $r['data']['group_id'] ?? 0;
    talkApi($baseUrl, $M1, 'POST', 'join_group', ['group_id' => $aGroup]);
    talkApi($baseUrl, $M2, 'POST', 'join_group', ['group_id' => $aGroup]);

    $r = talkApi($baseUrl, $M1, 'POST', 'update_member', [
        'group_id' => $aGroup,
        'user_id'  => $M2,
        'role'     => 'observer',
    ]);
    $steps[] = step(++$s, 'member1', 'update_member', 'Member tries to manage roles (blocked)', 'fail', $r);

    // Step 4: Facilitator promotes member2 to facilitator
    $r = talkApi($baseUrl, $F, 'POST', 'update_member', [
        'group_id' => $aGroup,
        'user_id'  => $M2,
        'role'     => 'facilitator',
    ]);
    $steps[] = step(++$s, 'facilitator', 'update_member', 'Promote member2 to facilitator', 'success', $r);

    // Step 5: New facilitator (member2) can now update group
    $r = talkApi($baseUrl, $M2, 'POST', 'update_group', [
        'group_id'    => $aGroup,
        'description' => 'Updated by new facilitator',
    ]);
    $steps[] = step(++$s, 'member2', 'update_group', 'New facilitator updates group', 'success', $r);

    // Step 6: Non-member tries to join closed group — BLOCKED
    $r = talkApi($baseUrl, $O, 'POST', 'join_group', ['group_id' => $obsGroup]);
    // Observer is already facilitator of this group, so this might succeed or say already member
    // Let's test with member1 instead
    $r = talkApi($baseUrl, $M1, 'POST', 'join_group', ['group_id' => $obsGroup]);
    $steps[] = step(++$s, 'member1', 'join_group', 'Join closed group (blocked)', 'fail', $r);

    return $steps;
}

// ── Scenario 3: Idea Links ──────────────────────────────────────────

function scenarioLinks($baseUrl, $users, $prefix) {
    $steps = [];
    $s = 0;
    $F = $users['facilitator']['user_id'];

    // Setup: create group + 2 ideas
    $r = talkApi($baseUrl, $F, 'POST', 'create_group', [
        'name' => $prefix . ' Links Test',
        'access_level' => 'open',
    ]);
    $groupId = $r['data']['group_id'] ?? 0;

    $r1 = talkApi($baseUrl, $F, 'POST', 'save', [
        'content' => 'Link test idea A', 'group_id' => $groupId,
    ]);
    $ideaA = $r1['data']['idea']['id'] ?? $r1['data']['id'] ?? 0;

    $r2 = talkApi($baseUrl, $F, 'POST', 'save', [
        'content' => 'Link test idea B', 'group_id' => $groupId,
    ]);
    $ideaB = $r2['data']['idea']['id'] ?? $r2['data']['id'] ?? 0;

    // Step 1: Create link between ideas
    $r = talkApi($baseUrl, $F, 'POST', 'create_link', [
        'idea_id_a' => $ideaA,
        'idea_id_b' => $ideaB,
        'link_type' => 'related',
    ]);
    $steps[] = step(++$s, 'facilitator', 'create_link', 'Link two ideas', 'success', $r);

    // Step 2: Get links for idea A
    $r = talkApi($baseUrl, $F, 'GET', 'get_links', ['idea_id' => $ideaA]);
    $linkCount = count($r['data']['links'] ?? []);
    $steps[] = step(++$s, 'facilitator', 'get_links', "Get links ($linkCount found)", 'success', $r);

    // Step 3: Self-link — should fail
    $r = talkApi($baseUrl, $F, 'POST', 'create_link', [
        'idea_id_a' => $ideaA,
        'idea_id_b' => $ideaA,
        'link_type' => 'related',
    ]);
    $steps[] = step(++$s, 'facilitator', 'create_link', 'Self-link (should fail)', 'fail', $r);

    // Step 4: Duplicate link — should fail
    $r = talkApi($baseUrl, $F, 'POST', 'create_link', [
        'idea_id_a' => $ideaA,
        'idea_id_b' => $ideaB,
        'link_type' => 'related',
    ]);
    $steps[] = step(++$s, 'facilitator', 'create_link', 'Duplicate link (should fail)', 'fail', $r);

    return $steps;
}

// ── Scenario 4: Edge Cases ──────────────────────────────────────────

function scenarioEdge($baseUrl, $users, $prefix) {
    $steps = [];
    $s = 0;
    $F = $users['facilitator']['user_id'];
    $M1 = $users['member1']['user_id'];

    // Setup: group
    $r = talkApi($baseUrl, $F, 'POST', 'create_group', [
        'name' => $prefix . ' Edge Cases',
        'access_level' => 'open',
    ]);
    $groupId = $r['data']['group_id'] ?? 0;
    talkApi($baseUrl, $M1, 'POST', 'join_group', ['group_id' => $groupId]);

    // Step 1: Save with empty content
    $r = talkApi($baseUrl, $F, 'POST', 'save', [
        'content' => '', 'group_id' => $groupId,
    ]);
    $steps[] = step(++$s, 'facilitator', 'save', 'Empty content (blocked)', 'fail', $r);

    // Step 2: Double join
    $r = talkApi($baseUrl, $M1, 'POST', 'join_group', ['group_id' => $groupId]);
    $steps[] = step(++$s, 'member1', 'join_group', 'Double join (blocked)', 'fail', $r);

    // Step 3: Save idea then delete it, then try to delete again
    $r = talkApi($baseUrl, $F, 'POST', 'save', [
        'content' => 'Delete me twice', 'group_id' => $groupId,
    ]);
    $tmpId = $r['data']['idea']['id'] ?? $r['data']['id'] ?? 0;
    talkApi($baseUrl, $F, 'POST', 'delete', ['idea_id' => $tmpId]);

    $r = talkApi($baseUrl, $F, 'POST', 'delete', ['idea_id' => $tmpId]);
    $steps[] = step(++$s, 'facilitator', 'delete', 'Delete already-deleted', 'fail', $r);

    // Step 4: Edit a deleted idea
    $r = talkApi($baseUrl, $F, 'POST', 'edit', [
        'idea_id' => $tmpId,
        'content' => 'Edit after delete',
    ]);
    $steps[] = step(++$s, 'facilitator', 'edit', 'Edit deleted idea (blocked)', 'fail', $r);

    // Step 5: Save with no auth (user_id=0)
    $r = talkApi($baseUrl, 0, 'POST', 'save', [
        'content' => 'Anonymous save to group', 'group_id' => $groupId,
    ]);
    $steps[] = step(++$s, 'facilitator', 'save', 'No-auth save to group (blocked)', 'fail', $r);

    return $steps;
}

// ── Request Handler ─────────────────────────────────────────────────

if (isset($_GET['clean'])) {
    header('Content-Type: application/json');
    echo json_encode(cleanup($BASE_URL));
    exit;
}

if (isset($_GET['run'])) {
    header('Content-Type: application/json');
    $scenario = $_GET['run'];
    $allSteps = [];
    $t0 = microtime(true);

    // Always clean first
    cleanup($BASE_URL);

    if ($scenario === 'lifecycle' || $scenario === 'all') {
        $allSteps = array_merge($allSteps, [['scenario' => 'Group Lifecycle', 'steps' => scenarioLifecycle($BASE_URL, $USERS, $PREFIX)]]);
    }
    if ($scenario === 'access' || $scenario === 'all') {
        $allSteps = array_merge($allSteps, [['scenario' => 'Access Control', 'steps' => scenarioAccess($BASE_URL, $USERS, $PREFIX)]]);
    }
    if ($scenario === 'links' || $scenario === 'all') {
        $allSteps = array_merge($allSteps, [['scenario' => 'Idea Links', 'steps' => scenarioLinks($BASE_URL, $USERS, $PREFIX)]]);
    }
    if ($scenario === 'edge' || $scenario === 'all') {
        $allSteps = array_merge($allSteps, [['scenario' => 'Edge Cases', 'steps' => scenarioEdge($BASE_URL, $USERS, $PREFIX)]]);
    }

    // Cleanup after
    $cleanResult = cleanup($BASE_URL);

    // Tally
    $passed = 0; $failed = 0; $expectedFail = 0;
    foreach ($allSteps as &$group) {
        foreach ($group['steps'] as $st) {
            if ($st['passed'] && $st['expect'] === 'success') $passed++;
            elseif ($st['passed'] && $st['expect'] === 'fail') $expectedFail++;
            elseif (!$st['passed']) $failed++;
        }
    }

    echo json_encode([
        'scenarios'     => $allSteps,
        'summary'       => [
            'passed'        => $passed,
            'failed'        => $failed,
            'expected_fail' => $expectedFail,
            'total'         => $passed + $failed + $expectedFail,
            'duration_ms'   => round((microtime(true) - $t0) * 1000),
        ],
        'cleanup'       => $cleanResult,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Dashboard HTML ──────────────────────────────────────────────────
$autoRun = isset($_GET['auto']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Talk Test Harness</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, monospace;
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    background-attachment: fixed;
    color: #e0e0e0;
    min-height: 100vh;
    padding: 1rem;
}
h1 { color: #d4af37; font-size: 1.4rem; margin-bottom: 0.25rem; }
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem; }
.header-left .target { color: #888; font-size: 0.8rem; }
.header-right { display: flex; gap: 0.5rem; }
.btn {
    padding: 0.5rem 1rem; border: none; border-radius: 6px; cursor: pointer;
    font-size: 0.85rem; font-weight: 600; transition: all 0.2s;
}
.btn-run { background: linear-gradient(145deg, #4caf50, #388e3c); color: white; }
.btn-run:hover { box-shadow: 0 2px 8px rgba(76,175,80,0.4); }
.btn-clean { background: rgba(255,255,255,0.1); color: #ff9800; border: 1px solid #ff9800; }
.btn-clean:hover { background: rgba(255,152,0,0.15); }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }

.lanes { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.75rem; margin-bottom: 1rem; }
@media (max-width: 800px) { .lanes { grid-template-columns: repeat(2, 1fr); } }

.lane {
    background: rgba(255,255,255,0.03);
    border-radius: 8px;
    padding: 0.5rem;
    min-height: 200px;
}
.lane-header {
    text-align: center;
    padding: 0.5rem;
    border-radius: 6px;
    margin-bottom: 0.5rem;
    font-weight: 600;
    font-size: 0.85rem;
}
.lane-header small { display: block; font-weight: 400; font-size: 0.7rem; opacity: 0.7; }

.card {
    background: rgba(255,255,255,0.06);
    border-radius: 6px;
    padding: 0.5rem;
    margin-bottom: 0.4rem;
    border-left: 3px solid #555;
    font-size: 0.75rem;
    cursor: pointer;
    transition: background 0.2s;
}
.card:hover { background: rgba(255,255,255,0.1); }
.card.pass { border-left-color: #4caf50; }
.card.fail { border-left-color: #f44336; }
.card.expected-fail { border-left-color: #ff9800; }
.card .action { font-weight: 600; color: #fff; }
.card .desc { color: #aaa; margin-top: 2px; }
.card .meta { color: #666; margin-top: 2px; font-size: 0.65rem; }
.card .snippet { color: #888; margin-top: 3px; font-size: 0.65rem; display: none; word-break: break-all; }
.card.expanded .snippet { display: block; }
.card .indicator { float: right; font-size: 0.85rem; }

.scenario-header {
    background: rgba(212,175,55,0.1);
    border: 1px solid rgba(212,175,55,0.2);
    border-radius: 6px;
    padding: 0.5rem 0.75rem;
    margin-bottom: 0.75rem;
    font-size: 0.85rem;
    color: #d4af37;
    grid-column: 1 / -1;
}

.summary {
    background: rgba(255,255,255,0.05);
    border-radius: 8px;
    padding: 0.75rem 1rem;
    display: flex;
    gap: 1.5rem;
    align-items: center;
    flex-wrap: wrap;
}
.summary .stat { font-size: 0.9rem; }
.summary .stat.pass { color: #4caf50; }
.summary .stat.fail { color: #f44336; }
.summary .stat.expected { color: #ff9800; }
.summary .stat.time { color: #888; }

.spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid #555; border-top-color: #d4af37; border-radius: 50%; animation: spin 0.6s linear infinite; margin-right: 0.5rem; vertical-align: middle; }
@keyframes spin { to { transform: rotate(360deg); } }

#status { color: #888; font-size: 0.8rem; margin-bottom: 0.5rem; min-height: 1.2em; }
</style>
</head>
<body>

<div class="header">
    <div class="header-left">
        <h1>Talk Test Harness</h1>
        <div class="target">Target: <?= htmlspecialchars($BASE_URL) ?></div>
    </div>
    <div class="header-right">
        <button class="btn btn-run" id="btnRun" onclick="runAll()">Run All</button>
        <button class="btn btn-run" id="btnLifecycle" onclick="runScenario('lifecycle')">Lifecycle</button>
        <button class="btn btn-clean" id="btnClean" onclick="runClean()">Clean</button>
    </div>
</div>

<div id="status"></div>

<div class="lanes" id="lanes">
    <?php foreach ($USERS as $key => $u): ?>
    <div class="lane" id="lane-<?= $key ?>">
        <div class="lane-header" style="background: <?= $u['color'] ?>22; color: <?= $u['color'] ?>;">
            <?= htmlspecialchars($u['label']) ?>
            <small><?= $key ?> (id <?= $u['user_id'] ?>)</small>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="summary" id="summary" style="display:none;"></div>
<div id="harness-summary" style="display:none;"></div>

<script>
var users = <?= json_encode($USERS) ?>;
var userKeys = Object.keys(users);

function setStatus(html) { document.getElementById('status').innerHTML = html; }
function setButtons(disabled) {
    document.getElementById('btnRun').disabled = disabled;
    document.getElementById('btnLifecycle').disabled = disabled;
    document.getElementById('btnClean').disabled = disabled;
}

function clearLanes() {
    userKeys.forEach(function(k) {
        var lane = document.getElementById('lane-' + k);
        var header = lane.querySelector('.lane-header');
        lane.innerHTML = '';
        lane.appendChild(header);
    });
    document.getElementById('summary').style.display = 'none';
}

function addCard(userKey, step) {
    var lane = document.getElementById('lane-' + userKey);
    if (!lane) return;

    var cls = 'card';
    var indicator = '';
    if (step.passed && step.expect === 'success') { cls += ' pass'; indicator = '&#x2713;'; }
    else if (step.passed && step.expect === 'fail') { cls += ' expected-fail'; indicator = '&#x2298;'; }
    else { cls += ' fail'; indicator = '&#x2717;'; }

    var card = document.createElement('div');
    card.className = cls;
    card.innerHTML =
        '<span class="indicator">' + indicator + '</span>' +
        '<div class="action">' + step.action + '</div>' +
        '<div class="desc">' + escHtml(step.desc) + '</div>' +
        '<div class="meta">' + step.duration + 'ms | HTTP ' + step.http + '</div>' +
        '<div class="snippet">' + escHtml(step.snippet || '') + '</div>';
    card.addEventListener('click', function() { card.classList.toggle('expanded'); });
    lane.appendChild(card);
}

function addScenarioHeader(name) {
    var lanes = document.getElementById('lanes');
    var div = document.createElement('div');
    div.className = 'scenario-header';
    div.textContent = name;
    lanes.appendChild(div);

    // Re-add lane divs after header
    userKeys.forEach(function(k) {
        lanes.appendChild(document.getElementById('lane-' + k));
    });
}

function showSummary(summary) {
    var el = document.getElementById('summary');
    el.style.display = 'flex';
    el.innerHTML =
        '<span class="stat pass">&#x2713; ' + summary.passed + ' passed</span>' +
        '<span class="stat fail">&#x2717; ' + summary.failed + ' failed</span>' +
        '<span class="stat expected">&#x2298; ' + summary.expected_fail + ' expected-fail</span>' +
        '<span class="stat time">' + summary.duration_ms + 'ms total</span>';

    // For Playwright
    var harnessEl = document.getElementById('harness-summary');
    harnessEl.dataset.results = JSON.stringify(summary);
    if (summary.failed === 0) {
        var complete = document.createElement('div');
        complete.id = 'harness-complete';
        document.body.appendChild(complete);
    }
}

function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

async function runScenario(name) {
    clearLanes();
    setButtons(true);
    setStatus('<span class="spinner"></span> Running ' + name + '...');

    try {
        var r = await fetch('?run=' + name);
        var data = await r.json();

        (data.scenarios || []).forEach(function(scenario) {
            (scenario.steps || []).forEach(function(step) {
                addCard(step.user, step);
            });
        });

        showSummary(data.summary);
        setStatus(data.summary.failed > 0 ? 'FAILURES DETECTED' : 'All tests passed');
    } catch (e) {
        setStatus('Error: ' + e.message);
    }
    setButtons(false);
}

async function runAll() {
    clearLanes();
    setButtons(true);
    setStatus('<span class="spinner"></span> Running all scenarios...');

    try {
        var r = await fetch('?run=all');
        var data = await r.json();

        (data.scenarios || []).forEach(function(scenario, i) {
            if (i > 0) addScenarioHeader(scenario.scenario);
            else {
                var h = document.createElement('div');
                h.className = 'scenario-header';
                h.textContent = scenario.scenario;
                document.getElementById('lanes').prepend(h);
            }
            (scenario.steps || []).forEach(function(step) {
                addCard(step.user, step);
            });
        });

        showSummary(data.summary);
        setStatus(data.summary.failed > 0 ? 'FAILURES DETECTED' : 'All tests passed');
    } catch (e) {
        setStatus('Error: ' + e.message);
    }
    setButtons(false);
}

async function runClean() {
    setButtons(true);
    setStatus('<span class="spinner"></span> Cleaning...');
    try {
        var r = await fetch('?clean=1');
        var data = await r.json();
        var d = data.deleted || {};
        setStatus('Cleaned: ' + d.groups + ' groups, ' + d.ideas + ' ideas, ' + d.members + ' members, ' + d.links + ' links');
    } catch (e) {
        setStatus('Error: ' + e.message);
    }
    setButtons(false);
}

<?php if ($autoRun): ?>
window.addEventListener('load', function() { runAll(); });
<?php endif; ?>
</script>
</body>
</html>
