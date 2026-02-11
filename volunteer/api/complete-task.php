<?php
/**
 * TPB Task Completion Handler
 * ===========================
 * Handles task completion and auto-creates child tasks for state/town builds
 *
 * Usage: POST to this endpoint with task_id, user_id, notes, attachment_url
 *
 * Auto-creates:
 * - TEST task when BUILD task completes
 * - DEPLOY task when TEST task approves
 */

header('Content-Type: application/json');

// Database connection
$config = require __DIR__ . '/../../config.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Get POST data
$taskId = $_POST['task_id'] ?? null;
$userId = $_POST['user_id'] ?? null;
$completionNotes = $_POST['notes'] ?? '';
$attachmentUrl = $_POST['attachment_url'] ?? null;
$approved = $_POST['approved'] ?? null; // For TEST tasks

// Validate required fields
if (!$taskId || !$userId) {
    http_response_code(400);
    echo json_encode(['error' => 'task_id and user_id are required']);
    exit;
}

// Get task details
$stmt = $pdo->prepare("
    SELECT t.*,
           u.username as claimed_by_username
    FROM tasks t
    LEFT JOIN users u ON t.claimed_by_user_id = u.user_id
    WHERE t.task_id = ?
");
$stmt->execute([$taskId]);
$task = $stmt->fetch();

if (!$task) {
    http_response_code(404);
    echo json_encode(['error' => 'Task not found']);
    exit;
}

// Verify user is authorized (either task owner or admin)
if ($task['claimed_by_user_id'] != $userId) {
    // TODO: Add admin check
    http_response_code(403);
    echo json_encode(['error' => 'Not authorized to complete this task']);
    exit;
}

// Mark task complete
$stmt = $pdo->prepare("
    UPDATE tasks SET
        status = 'completed',
        completed_by_user_id = ?,
        completed_at = NOW(),
        completion_notes = ?,
        attachment_url = ?
    WHERE task_id = ?
");
$stmt->execute([$userId, $completionNotes, $attachmentUrl, $taskId]);

$newTaskId = null;
$newTaskKey = null;

// ============================================
// AUTO-CREATE CHILD TASKS BASED ON TASK TYPE
// ============================================

// BUILD → TEST (for state builds)
if ($task['task_type'] === 'build' && preg_match('/build-state-([a-z]{2})/i', $task['task_key'], $matches)) {
    $stateAbbr = strtoupper($matches[1]);

    // Get state info
    $stmt = $pdo->prepare("SELECT state_name FROM states WHERE abbreviation = ?");
    $stmt->execute([$stateAbbr]);
    $state = $stmt->fetch();
    $stateName = $state['state_name'] ?? $stateAbbr;

    // Create TEST task
    $testTaskKey = 'test-state-' . strtolower($stateAbbr);
    $testTitle = "TEST: {$stateName} State Page";
    $testContent = generateTestTaskContent($stateAbbr, $stateName, $task['claimed_by_username']);

    $stmt = $pdo->prepare("
        INSERT INTO tasks (
            task_key, parent_task_id, task_type, title, full_content, short_description,
            skill_set_id, status, priority, estimated_hours, points,
            created_by_user_id, created_at
        ) VALUES (?, ?, 'test', ?, ?, ?, 1, 'open', 'high', 2, 150, ?, NOW())
    ");

    $shortDesc = "Review and verify the {$stateName} state page build by {$task['claimed_by_username']}";

    $stmt->execute([
        $testTaskKey,
        $taskId,
        $testTitle,
        $testContent,
        $shortDesc,
        $userId
    ]);

    $newTaskId = $pdo->lastInsertId();
    $newTaskKey = $testTaskKey;

    // Log activity
    logTaskActivity($pdo, $newTaskId, $userId, 'created', "Auto-created from completed BUILD task #{$taskId}");
}

// BUILD → TEST (for town builds)
if ($task['task_type'] === 'build' && preg_match('/build-town-([a-z-]+)-([a-z]{2})/i', $task['task_key'], $matches)) {
    $townSlug = $matches[1];
    $stateAbbr = strtoupper($matches[2]);

    // Create TEST task (similar pattern)
    $testTaskKey = 'test-town-' . $townSlug . '-' . strtolower($stateAbbr);
    $testTitle = "TEST: {$townSlug} Town Page";
    $testContent = generateTestTaskContentTown($townSlug, $stateAbbr, $task['claimed_by_username']);

    $stmt = $pdo->prepare("
        INSERT INTO tasks (
            task_key, parent_task_id, task_type, title, full_content, short_description,
            skill_set_id, status, priority, estimated_hours, points,
            created_by_user_id, created_at
        ) VALUES (?, ?, 'test', ?, ?, ?, 1, 'open', 'high', 2, 150, ?, NOW())
    ");

    $shortDesc = "Review and verify the {$townSlug} town page build";

    $stmt->execute([
        $testTaskKey,
        $taskId,
        $testTitle,
        $testContent,
        $shortDesc,
        $userId
    ]);

    $newTaskId = $pdo->lastInsertId();
    $newTaskKey = $testTaskKey;

    logTaskActivity($pdo, $newTaskId, $userId, 'created', "Auto-created from completed BUILD task #{$taskId}");
}

// TEST → DEPLOY (when TEST approves)
if ($task['task_type'] === 'test' && $approved === 'true') {
    // Extract state/town from TEST task key
    if (preg_match('/test-state-([a-z]{2})/i', $task['task_key'], $matches)) {
        $stateAbbr = strtoupper($matches[1]);

        // Get state info
        $stmt = $pdo->prepare("SELECT state_name FROM states WHERE abbreviation = ?");
        $stmt->execute([$stateAbbr]);
        $state = $stmt->fetch();
        $stateName = $state['state_name'] ?? $stateAbbr;

        // Create DEPLOY task
        $deployTaskKey = 'deploy-state-' . strtolower($stateAbbr);
        $deployTitle = "DEPLOY: {$stateName} State Page";
        $deployContent = generateDeployTaskContent($stateAbbr, $stateName);

        $stmt = $pdo->prepare("
            INSERT INTO tasks (
                task_key, parent_task_id, task_type, title, full_content, short_description,
                skill_set_id, status, priority, estimated_hours, points,
                requires_deploy_role, created_by_user_id, created_at
            ) VALUES (?, ?, 'deploy', ?, ?, ?, 1, 'open', 'high', 1, 100, 1, ?, NOW())
        ");

        $shortDesc = "Deploy approved {$stateName} state page to production";

        $stmt->execute([
            $deployTaskKey,
            $taskId,
            $deployTitle,
            $deployContent,
            $shortDesc,
            $userId
        ]);

        $newTaskId = $pdo->lastInsertId();
        $newTaskKey = $deployTaskKey;

        logTaskActivity($pdo, $newTaskId, $userId, 'created', "Auto-created from approved TEST task #{$taskId}");
    }
}

// Return success
echo json_encode([
    'success' => true,
    'message' => 'Task completed successfully',
    'new_task_created' => $newTaskId !== null,
    'new_task_id' => $newTaskId,
    'new_task_key' => $newTaskKey
]);

// ============================================
// HELPER FUNCTIONS
// ============================================

function generateTestTaskContent($stateAbbr, $stateName, $builderName) {
    return <<<EOT
## TEST: {$stateName} State Page

Review the completed state page build by **{$builderName}**.

### Your Job:

Download the ZIP file attached to the parent BUILD task and verify quality.

### Test Checklist:

**Data Quality:**
- [ ] All benefit program links work (no 404s)
- [ ] Phone numbers are formatted correctly
- [ ] Government officials are current (governor, lt. gov, etc.)
- [ ] Budget data is from current/recent fiscal year
- [ ] Legislature composition is accurate
- [ ] Voter registration stats are recent
- [ ] All sources are documented

**Technical:**
- [ ] PHP file runs without errors
- [ ] All includes are present (header, nav, footer, thought-form)
- [ ] Secondary nav has all 11 sections
- [ ] All section anchors work (clicking nav scrolls to section)
- [ ] Mobile responsive design works
- [ ] External links open in new tab

**Content:**
- [ ] No spelling or grammar errors
- [ ] Numbers formatted with commas
- [ ] Benefits eligibility is clear
- [ ] Writing is citizen-friendly (not bureaucratic)

### If Issues Found:

1. Mark task status as "Needs Revision"
2. Add detailed notes about what needs fixing
3. Notify builder through task system

### If Approved:

1. Mark this TEST task as complete with `approved=true`
2. System will auto-create DEPLOY task
3. Add congratulations message for builder!

**Estimated Time:** 1-2 hours
**Civic Points:** 150 points
EOT;
}

function generateTestTaskContentTown($townSlug, $stateAbbr, $builderName) {
    return <<<EOT
## TEST: {$townSlug} Town Page

Review the completed town page build by **{$builderName}**.

### Test Checklist:

- [ ] All links work
- [ ] Town officials are current
- [ ] Content is accurate
- [ ] Mobile responsive
- [ ] No errors

### Next Steps:

Approve to auto-create DEPLOY task.
EOT;
}

function generateDeployTaskContent($stateAbbr, $stateName) {
    $stateLower = strtolower($stateAbbr);

    return <<<EOT
## DEPLOY: {$stateName} State Page

Deploy the approved state page to production.

### Prerequisites:

- [ ] BUILD task completed
- [ ] TEST task approved
- [ ] You have server SSH access
- [ ] You have deploy role

### Deploy Steps:

1. **Download ZIP** from BUILD task
2. **Extract locally** and verify files:
   - {$stateLower}-state-page.php
   - {$stateLower}-state-updates.sql
   - {$stateLower}-state-data.json
   - BUILD-LOG-{$stateAbbr}.md

3. **Backup current page** (if exists):
   ```bash
   ssh sandge5@ecngx308.inmotionhosting.com -p 2222
   cd /home/sandge5/4tpb.org/z-states/{$stateLower}
   cp index.php index-old-\$(date +%Y%m%d).php 2>/dev/null || echo "No existing page to backup"
   ```

4. **Upload new page:**
   ```bash
   scp -P 2222 {$stateLower}-state-page.php sandge5@ecngx308.inmotionhosting.com:/home/sandge5/4tpb.org/z-states/{$stateLower}/index.php
   ```

5. **Run SQL updates:**
   ```bash
   # Copy SQL to server temp location
   scp -P 2222 {$stateLower}-state-updates.sql sandge5@ecngx308.inmotionhosting.com:/home/sandge5/temp-sql-update.sql

   # SSH and run SQL
   ssh sandge5@ecngx308.inmotionhosting.com -p 2222
   mysql -u sandge5_tpb2 -p sandge5_tpb2 < /home/sandge5/temp-sql-update.sql
   rm /home/sandge5/temp-sql-update.sql
   ```

6. **Verify live page:**
   - Visit: https://4tpb.org/{$stateLower}/
   - Check: All sections load correctly
   - Check: Links work
   - Check: Mobile view works

7. **Mark task complete** with live URL in notes

### Rollback (if needed):

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222
cd /home/sandge5/4tpb.org/z-states/{$stateLower}
cp index-old-YYYYMMDD.php index.php
```

**Estimated Time:** 30-60 minutes
**Civic Points:** 100 points
EOT;
}

function logTaskActivity($pdo, $taskId, $userId, $action, $notes) {
    // TODO: Implement task activity logging if table exists
    // For now, just return true
    return true;
}
?>
