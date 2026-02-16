<?php
/**
 * The People's Branch - Task Claim & Approval API
 * ================================================
 * Two-tier approval system:
 * 1. Claim approval: volunteer requests to claim → owner approves
 * 2. Completion approval: volunteer marks complete → owner approves
 * 
 * Actions:
 * - request: Request to claim a task (sets claim_status = pending)
 * - approve_claim: Owner approves claim request
 * - deny_claim: Owner denies claim request
 * - start: Start working on approved task (status = in_progress)
 * - complete: Mark task as complete (status = review)
 * - approve_complete: Owner approves completion
 * - reject_complete: Owner rejects completion (needs more work)
 */

// Database connection
$config = require __DIR__ . '/../config.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    header('Location: /volunteer/?error=db');
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Location: /volunteer/?error=method');
    exit;
}

// Get user via centralized auth
require_once __DIR__ . '/../includes/get-user.php';
$dbUser = getUser($pdo);

if (!$dbUser) {
    header('Location: /profile.php?error=login');
    exit;
}

$user = $dbUser;

// Check if user is an approved volunteer
$stmt = $pdo->prepare("SELECT status FROM volunteer_applications WHERE user_id = ? AND status = 'accepted'");
$stmt->execute([$user['user_id']]);
$volunteer = $stmt->fetch();

if (!$volunteer) {
    header('Location: /volunteer/apply.php?error=not_volunteer');
    exit;
}

// Check if user is a PM (for approval actions)
$stmt = $pdo->prepare("
    SELECT usp.skill_set_id 
    FROM user_skill_progression usp 
    WHERE usp.user_id = ? AND usp.skill_set_id = 8 AND usp.is_primary = 1
");
$stmt->execute([$user['user_id']]);
$isPM = (bool)$stmt->fetch();

// Get parameters
$taskId = $_POST['task_id'] ?? null;
$action = $_POST['action'] ?? 'request'; // default to request for backward compatibility

if (!$taskId) {
    header('Location: /volunteer/?error=missing_task');
    exit;
}

// Get task with ownership info
$stmt = $pdo->prepare("
    SELECT t.*, 
           parent.claimed_by_user_id as parent_owner_id
    FROM tasks t
    LEFT JOIN tasks parent ON t.parent_task_id = parent.task_id
    WHERE t.task_id = ?
");
$stmt->execute([$taskId]);
$task = $stmt->fetch();

if (!$task) {
    header('Location: /volunteer/?error=not_found');
    exit;
}

// Determine who can approve this task
// - For top-level tasks: the creator (or any PM)
// - For sub-tasks: the parent task owner
$canApprove = false;
if ($task['parent_task_id']) {
    // Sub-task: parent owner can approve
    $canApprove = ($task['parent_owner_id'] == $user['user_id']);
} else {
    // Top-level task: creator or PM can approve
    $canApprove = ($task['created_by_user_id'] == $user['user_id']) || $isPM;
}

$isOwner = ($task['claimed_by_user_id'] == $user['user_id']);

// Process action
$error = null;
$success = null;
$redirect = '/volunteer/';

switch ($action) {
    case 'request':
        // Request to claim an open task
        if ($task['status'] !== 'open') {
            $error = 'not_available';
            break;
        }
        
        // Set claim as pending (task stays 'open' until approved)
        $stmt = $pdo->prepare("
            UPDATE tasks 
            SET claimed_by_user_id = ?,
                claimed_at = NOW(),
                claim_status = 'pending'
            WHERE task_id = ? AND status = 'open'
        ");
        $stmt->execute([$user['user_id'], $taskId]);
        
        if ($stmt->rowCount() > 0) {
            $success = 'claim_requested';
            $redirect = '/volunteer/?tab=available&claimed=pending';
        } else {
            $error = 'claim_failed';
        }
        break;
        
    case 'approve_claim':
        // Owner approves claim request
        if (!$canApprove) {
            $error = 'not_authorized';
            break;
        }
        
        if (($task['claim_status'] ?? '') !== 'pending') {
            $error = 'no_pending_claim';
            break;
        }
        
        $stmt = $pdo->prepare("
            UPDATE tasks 
            SET status = 'claimed',
                claim_status = 'approved',
                claim_approved_by = ?,
                claim_approved_at = NOW()
            WHERE task_id = ?
        ");
        $stmt->execute([$user['user_id'], $taskId]);
        
        $success = 'claim_approved';
        $redirect = '/volunteer/?tab=pm';
        break;
        
    case 'deny_claim':
        // Owner denies claim request
        if (!$canApprove) {
            $error = 'not_authorized';
            break;
        }
        
        if (($task['claim_status'] ?? '') !== 'pending') {
            $error = 'no_pending_claim';
            break;
        }
        
        $reason = $_POST['reason'] ?? null;
        
        $stmt = $pdo->prepare("
            UPDATE tasks 
            SET claimed_by_user_id = NULL,
                claimed_at = NULL,
                claim_status = 'none',
                claim_denied_reason = ?
            WHERE task_id = ?
        ");
        $stmt->execute([$reason, $taskId]);
        
        $success = 'claim_denied';
        $redirect = '/volunteer/?tab=pm';
        break;
        
    case 'start':
        // Start working on approved claim (or legacy claim with no claim_status)
        if (!$isOwner) {
            $error = 'not_owner';
            break;
        }
        
        $claimStatus = $task['claim_status'] ?? 'none';
        if ($task['status'] !== 'claimed' || ($claimStatus !== 'approved' && $claimStatus !== 'none')) {
            $error = 'cannot_start';
            break;
        }
        
        $stmt = $pdo->prepare("UPDATE tasks SET status = 'in_progress' WHERE task_id = ?");
        $stmt->execute([$taskId]);
        
        $success = 'started';
        $redirect = '/volunteer/?tab=mywork';
        break;
        
    case 'complete':
        // Mark task as complete (sends to review)
        if (!$isOwner) {
            $error = 'not_owner';
            break;
        }
        
        if ($task['status'] !== 'in_progress') {
            $error = 'not_in_progress';
            break;
        }
        
        $stmt = $pdo->prepare("UPDATE tasks SET status = 'review' WHERE task_id = ?");
        $stmt->execute([$taskId]);
        
        $success = 'submitted_for_review';
        $redirect = '/volunteer/?tab=mywork';
        break;
        
    case 'approve_complete':
        // Owner approves completion
        if (!$canApprove) {
            $error = 'not_authorized';
            break;
        }
        
        if ($task['status'] !== 'review') {
            $error = 'not_in_review';
            break;
        }
        
        // Mark as completed and award points
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                UPDATE tasks 
                SET status = 'completed',
                    completed_at = NOW(),
                    completed_by_user_id = ?
                WHERE task_id = ?
            ");
            $stmt->execute([$task['claimed_by_user_id'], $taskId]);
            
            // Award civic points to the volunteer via PointLogger
            if ($task['claimed_by_user_id'] && $task['points'] > 0) {
                require_once __DIR__ . '/../includes/point-logger.php';
                PointLogger::init($pdo);
                PointLogger::awardMilestone(
                    $task['claimed_by_user_id'], 
                    null, 
                    $task['points'], 
                    'task_completed'
                );
            }
            
            $pdo->commit();
            $success = 'completion_approved';
            $redirect = '/volunteer/?tab=pm&completed=1';
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'approval_failed';
        }
        break;
        
    case 'reject_complete':
        // Owner rejects completion (needs more work)
        if (!$canApprove) {
            $error = 'not_authorized';
            break;
        }
        
        if ($task['status'] !== 'review') {
            $error = 'not_in_review';
            break;
        }
        
        // Send back to in_progress
        $stmt = $pdo->prepare("UPDATE tasks SET status = 'in_progress' WHERE task_id = ?");
        $stmt->execute([$taskId]);
        
        $success = 'sent_back';
        $redirect = '/volunteer/?tab=pm';
        break;
        
    default:
        $error = 'unknown_action';
}

// Redirect with result
$separator = (strpos($redirect, '?') !== false) ? '&' : '?';
if ($error) {
    header('Location: ' . $redirect . $separator . 'error=' . $error);
} else {
    header('Location: ' . $redirect . $separator . 'success=' . $success);
}
exit;
