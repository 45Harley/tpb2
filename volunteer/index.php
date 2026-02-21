<?php
/**
 * The People's Branch - Volunteer Workspace v2
 * =============================================
 * Task board for TPB builders
 * 
 * Features:
 * - Sub-nav: My Work | Available | Completed | PM (role-based)
 * - Two-tier approval: Claim approval + Completion approval
 * - PM-only top-level task creation
 * - Task owners can expand their tasks into sub-tasks
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
    die("Database connection failed");
}

// Load user — standard method (tpb_user_id cookie first, then session)
require_once __DIR__ . '/../includes/get-user.php';
$dbUser = getUser($pdo);
$sessionId = $_COOKIE['tpb_civic_session'] ?? null;

// Nav variables
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'volunteer';
$pageTitle = 'Volunteer Workspace | The People\'s Branch';

// Volunteer-specific data
$isVolunteer = false;
$volunteerStatus = null;
$isPM = false;
$userPrimarySkill = null;

if ($dbUser) {
    // Check if user is a volunteer and get their primary skill
    {
        $stmt = $pdo->prepare("
            SELECT status FROM volunteer_applications 
            WHERE user_id = ? 
            ORDER BY applied_at DESC LIMIT 1
        ");
        $stmt->execute([$dbUser['user_id']]);
        $volApp = $stmt->fetch();
        if ($volApp) {
            $volunteerStatus = $volApp['status'];
            $isVolunteer = ($volunteerStatus === 'accepted');
        }
        
        // Check if user is a PM (Project Management skill as primary)
        $stmt = $pdo->prepare("
            SELECT usp.skill_set_id, ss.set_name
            FROM user_skill_progression usp
            JOIN skill_sets ss ON usp.skill_set_id = ss.skill_set_id
            WHERE usp.user_id = ? AND usp.is_primary = 1
        ");
        $stmt->execute([$dbUser['user_id']]);
        $primarySkill = $stmt->fetch();
        if ($primarySkill) {
            $userPrimarySkill = $primarySkill;
            // skill_set_id 8 = Project Management
            $isPM = ($primarySkill['skill_set_id'] == 8);
        }
    }
}

// Current tab from URL
$currentTab = $_GET['tab'] ?? 'available';
$validTabs = ['mywork', 'available', 'completed', 'pm'];
if (!in_array($currentTab, $validTabs)) {
    $currentTab = 'available';
}
// PM tab only accessible to PMs
if ($currentTab === 'pm' && !$isPM) {
    $currentTab = 'available';
}

// Function to build hierarchy path (e.g., "1.5.12")
function getTaskHierarchyPath($pdo, $taskId, $parentTaskId) {
    if (!$parentTaskId) {
        return (string)$taskId;
    }
    
    $path = [];
    $currentId = $parentTaskId;
    $maxDepth = 10;
    $depth = 0;
    
    while ($currentId && $depth < $maxDepth) {
        $stmt = $pdo->prepare("SELECT task_id, parent_task_id FROM tasks WHERE task_id = ?");
        $stmt->execute([$currentId]);
        $parent = $stmt->fetch();
        if ($parent) {
            array_unshift($path, $parent['task_id']);
            $currentId = $parent['parent_task_id'];
        } else {
            break;
        }
        $depth++;
    }
    
    $path[] = $taskId;
    return implode('.', $path);
}

// Get skill sets for filter
$skillSets = $pdo->query("SELECT skill_set_id, set_name FROM skill_sets ORDER BY set_name")->fetchAll();

// Build query based on current tab
$tasks = [];
$tabCounts = [
    'mywork' => 0,
    'available' => 0,
    'completed' => 0,
    'pending_claims' => 0,
    'pending_reviews' => 0
];

if ($dbUser && $isVolunteer) {
    // Count for My Work (tasks I've claimed that are approved or in progress)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM tasks 
        WHERE claimed_by_user_id = ? 
        AND status IN ('claimed', 'in_progress')
        AND (claim_status = 'approved' OR claim_status IS NULL OR claim_status = 'none')
    ");
    $stmt->execute([$dbUser['user_id']]);
    $tabCounts['mywork'] = $stmt->fetchColumn();
    
    // Count for Available (open tasks)
    $tabCounts['available'] = $pdo->query("SELECT COUNT(*) FROM tasks WHERE status = 'open'")->fetchColumn();
    
    // Count for Completed (my completed tasks)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE completed_by_user_id = ? AND status = 'completed'");
    $stmt->execute([$dbUser['user_id']]);
    $tabCounts['completed'] = $stmt->fetchColumn();
    
    // PM counts: pending claims and pending reviews for tasks they own
    if ($isPM) {
        // Pending claim approvals (tasks I created or own that have pending claims)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM tasks 
            WHERE (created_by_user_id = ? OR claimed_by_user_id = ?)
            AND claim_status = 'pending'
        ");
        $stmt->execute([$dbUser['user_id'], $dbUser['user_id']]);
        $tabCounts['pending_claims'] = $stmt->fetchColumn();
        
        // Pending completion reviews
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM tasks 
            WHERE (created_by_user_id = ? OR 
                   parent_task_id IN (SELECT task_id FROM tasks WHERE claimed_by_user_id = ?))
            AND status = 'review'
        ");
        $stmt->execute([$dbUser['user_id'], $dbUser['user_id']]);
        $tabCounts['pending_reviews'] = $stmt->fetchColumn();
    }
    
    // Fetch tasks based on tab
    $skillFilter = $_GET['skill'] ?? 'all';
    
    switch ($currentTab) {
        case 'mywork':
            $query = "
                SELECT t.*, ss.set_name as skill_name, 
                       u.username as claimed_by_username,
                       cu.username as created_by_username,
                       cab.username as claim_approved_by_username
                FROM tasks t
                LEFT JOIN skill_sets ss ON t.skill_set_id = ss.skill_set_id
                LEFT JOIN users u ON t.claimed_by_user_id = u.user_id
                LEFT JOIN users cu ON t.created_by_user_id = cu.user_id
                LEFT JOIN users cab ON t.claim_approved_by = cab.user_id
                WHERE t.claimed_by_user_id = ?
                AND t.status IN ('claimed', 'in_progress')
                AND (t.claim_status = 'approved' OR t.claim_status IS NULL OR t.claim_status = 'none')
            ";
            $params = [$dbUser['user_id']];
            break;
            
        case 'completed':
            $query = "
                SELECT t.*, ss.set_name as skill_name,
                       u.username as claimed_by_username,
                       cu.username as created_by_username
                FROM tasks t
                LEFT JOIN skill_sets ss ON t.skill_set_id = ss.skill_set_id
                LEFT JOIN users u ON t.claimed_by_user_id = u.user_id
                LEFT JOIN users cu ON t.created_by_user_id = cu.user_id
                WHERE t.completed_by_user_id = ?
                AND t.status = 'completed'
            ";
            $params = [$dbUser['user_id']];
            break;
            
        case 'pm':
            // Show tasks with pending claims or reviews, plus ability to create
            $query = "
                SELECT t.*, ss.set_name as skill_name,
                       u.username as claimed_by_username,
                       cu.username as created_by_username,
                       cab.username as claim_approved_by_username
                FROM tasks t
                LEFT JOIN skill_sets ss ON t.skill_set_id = ss.skill_set_id
                LEFT JOIN users u ON t.claimed_by_user_id = u.user_id
                LEFT JOIN users cu ON t.created_by_user_id = cu.user_id
                LEFT JOIN users cab ON t.claim_approved_by = cab.user_id
                WHERE (t.claim_status = 'pending' OR t.status = 'review')
                AND (t.created_by_user_id = ? OR t.parent_task_id IN (
                    SELECT task_id FROM tasks WHERE claimed_by_user_id = ?
                ))
            ";
            $params = [$dbUser['user_id'], $dbUser['user_id']];
            break;
            
        default: // available
            $query = "
                SELECT t.*, ss.set_name as skill_name,
                       u.username as claimed_by_username,
                       cu.username as created_by_username
                FROM tasks t
                LEFT JOIN skill_sets ss ON t.skill_set_id = ss.skill_set_id
                LEFT JOIN users u ON t.claimed_by_user_id = u.user_id
                LEFT JOIN users cu ON t.created_by_user_id = cu.user_id
                WHERE t.status = 'open'
            ";
            $params = [];
    }
    
    // Add skill filter
    if ($skillFilter !== 'all' && $currentTab !== 'pm') {
        $query .= " AND t.skill_set_id = ?";
        $params[] = $skillFilter;
    }
    
    $query .= " ORDER BY 
        CASE t.priority 
            WHEN 'critical' THEN 1 
            WHEN 'high' THEN 2 
            WHEN 'medium' THEN 3 
            WHEN 'low' THEN 4 
        END,
        t.created_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $tasks = $stmt->fetchAll();
}

// Get seed thoughts (Open Task category that haven't been expanded to task cards)
$seedThoughts = [];
$seedCount = 0;
if ($dbUser && $isVolunteer) {
    $seedThoughts = $pdo->query("
        SELECT 
            t.thought_id, t.content, t.created_at, t.upvotes, t.downvotes,
            u.username, u.first_name, u.last_name
        FROM user_thoughts t
        LEFT JOIN users u ON t.user_id = u.user_id
        WHERE t.category_id = 12 
        AND t.task_id IS NULL
        AND t.status = 'published'
        ORDER BY t.upvotes DESC, t.created_at DESC
        LIMIT 10
    ")->fetchAll();
    
    $seedCount = $pdo->query("
        SELECT COUNT(*) FROM user_thoughts 
        WHERE category_id = 12 AND task_id IS NULL AND status = 'published'
    ")->fetchColumn();
}

// Priority colors
$priorityColors = [
    'critical' => '#e74c3c',
    'high' => '#f39c12',
    'medium' => '#3498db',
    'low' => '#2ecc71'
];

$statusColors = [
    'open' => '#2ecc71',
    'claimed' => '#f39c12',
    'in_progress' => '#f39c12',
    'review' => '#9b59b6',
    'completed' => '#d4af37'
];

$claimStatusColors = [
    'none' => '#888',
    'pending' => '#f39c12',
    'approved' => '#2ecc71',
    'denied' => '#e74c3c'
];


$pageStyles = <<<'CSS'
        :root {
            --gold: #d4af37;
            --gold-light: #ffdb58;
            --gold-dark: #b8960c;
            --bg-dark: #0d0d0d;
            --bg-card: #1a1a1a;
            --bg-hover: #252525;
            --border: #2a2a2a;
            --text: #e0e0e0;
            --text-dim: #888;
            --success: #2ecc71;
            --warning: #f39c12;
            --critical: #e74c3c;
            --info: #3498db;
            --purple: #9b59b6;
        }
        body { line-height: 1.6; }
CSS;

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav.php';
?>
    <style>
        
        /* Sub Navigation */
        .sub-nav {
            background: var(--bg-card);
            border-bottom: 1px solid var(--border);
            padding: 0 30px;
            display: flex;
            gap: 0;
        }
        
        .sub-nav a {
            color: var(--text-dim);
            text-decoration: none;
            padding: 15px 20px;
            font-weight: 600;
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .sub-nav a:hover {
            color: var(--text);
            background: var(--bg-hover);
        }
        
        .sub-nav a.active {
            color: var(--gold);
            border-bottom-color: var(--gold);
        }
        
        .sub-nav .badge {
            background: var(--gold);
            color: var(--bg-dark);
            font-size: 0.75rem;
            padding: 2px 8px;
            border-radius: 10px;
            font-weight: 700;
        }
        
        .sub-nav .badge.alert {
            background: var(--critical);
            color: white;
        }
        
        /* Main Container */
        .main-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        
        /* Notice Box */
        .notice-box {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 40px;
            text-align: center;
            max-width: 500px;
            margin: 50px auto;
        }
        
        .notice-box h2 {
            color: var(--gold);
            margin-bottom: 15px;
            font-family: 'Cinzel', serif;
        }
        
        .notice-box p {
            color: var(--text-dim);
            margin-bottom: 20px;
        }
        
        /* Page Header */
        .page-header {
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .page-title {
            font-family: 'Cinzel', serif;
            font-size: 1.8rem;
            color: var(--gold);
        }
        
        .page-subtitle { color: var(--text-dim); }
        
        /* Stats Bar */
        .stats-bar {
            display: flex;
            gap: 20px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }
        
        .stat-box {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 15px 25px;
            text-align: center;
            min-width: 120px;
        }
        
        .stat-number {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--gold);
        }
        
        .stat-label {
            font-size: 0.75rem;
            color: var(--text-dim);
            text-transform: uppercase;
        }
        
        /* Filter Bar */
        .filter-bar {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .filter-select {
            padding: 8px 16px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 8px;
            font-size: 0.9rem;
        }
        
        .filter-select:focus {
            outline: none;
            border-color: var(--gold);
        }
        
        /* Buttons */
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            font-size: 0.95rem;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
            border: none;
        }
        
        .btn-primary {
            background: var(--gold);
            color: #000 !important;
        }
        
        .btn-primary:hover {
            background: var(--gold-light);
        }
        
        .btn-secondary {
            background: transparent;
            color: var(--text);
            border: 1px solid var(--border);
        }
        
        .btn-secondary:hover {
            border-color: var(--gold);
            color: var(--gold);
        }
        
        .btn-success {
            background: var(--success);
            color: white !important;
        }
        
        .btn-warning {
            background: var(--warning);
            color: #1a1a1a !important;
            font-weight: 700;
        }
        
        .btn-danger {
            background: var(--critical);
            color: white !important;
        }
        
        .btn-small {
            padding: 6px 12px;
            font-size: 0.85rem;
        }
        
        /* Task Cards */
        .task-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .task-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s;
        }
        
        .task-card:hover {
            border-color: var(--gold);
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(212, 175, 55, 0.1);
        }
        
        .task-card.featured { border-color: var(--gold); }
        .task-card.pending-action { border-color: var(--warning); }
        
        .task-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .task-meta {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .task-badge {
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .task-points {
            display: flex;
            align-items: center;
            gap: 5px;
            color: var(--gold);
            font-weight: 700;
            font-size: 1.1rem;
        }
        
        .task-points span:last-child {
            font-size: 0.8rem;
            font-weight: 400;
        }
        
        .task-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text);
        }
        
        .task-description {
            color: var(--text-dim);
            font-size: 0.95rem;
            line-height: 1.5;
            margin-bottom: 15px;
            white-space: pre-line;
        }
        
        .task-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 15px;
            border-top: 1px solid var(--border);
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .task-tags {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .task-tag {
            background: rgba(212, 175, 55, 0.1);
            color: var(--gold);
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 0.8rem;
        }
        
        .task-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .task-id {
            font-family: 'Source Code Pro', monospace;
            font-size: 0.75rem;
            color: var(--text-dim);
            background: rgba(255,255,255,0.05);
            padding: 2px 8px;
            border-radius: 4px;
        }
        
        .task-hierarchy {
            font-family: 'Source Code Pro', monospace;
            font-size: 0.7rem;
            color: var(--gold);
            opacity: 0.7;
        }
        
        /* Claim Status Indicator */
        .claim-status {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: var(--bg-dark);
            border-radius: 8px;
            margin-top: 10px;
        }
        
        .claim-status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }
        
        /* Pending Action Card */
        .pending-action-card {
            background: rgba(243, 156, 18, 0.1);
            border: 1px solid var(--warning);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .pending-action-card h4 {
            color: var(--warning);
            margin-bottom: 10px;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-dim);
        }
        
        .empty-state h3 {
            color: var(--gold);
            margin-bottom: 10px;
            font-family: 'Cinzel', serif;
        }
        
        /* Seeds Section */
        .seeds-section {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .seeds-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .seeds-header h2 {
            color: var(--success);
            font-size: 1.2rem;
        }
        
        .seed-count {
            color: var(--text-dim);
            font-size: 0.9rem;
        }
        
        .seed-card {
            background: var(--bg-dark);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
        }
        
        .seed-content {
            color: var(--text);
            margin: 10px 0;
            line-height: 1.5;
        }
        
        .seed-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .seed-votes {
            display: flex;
            gap: 15px;
            color: var(--text-dim);
            font-size: 0.85rem;
        }
        
        .expand-btn {
            background: var(--success);
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
        }
        
        .expand-btn:hover {
            opacity: 0.9;
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            overflow-y: auto;
            padding: 20px;
        }
        
        .modal.show { display: flex; }
        
        .modal-content {
            background: var(--bg-card);
            border: 2px solid var(--gold);
            border-radius: 12px;
            padding: 30px;
            max-width: 600px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal h3 {
            color: var(--gold);
            margin-bottom: 20px;
            font-family: 'Cinzel', serif;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            color: var(--text-dim);
            margin-bottom: 8px;
            font-size: 0.9rem;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            background: var(--bg-dark);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text);
            font-size: 1rem;
            font-family: inherit;
        }
        
        .form-group textarea {
            min-height: 120px;
            resize: vertical;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--gold);
        }
        
        .modal-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .sub-nav { padding: 0 15px; overflow-x: auto; }
            .sub-nav a { padding: 12px 15px; white-space: nowrap; }
            .stats-bar { justify-content: center; }
            .task-footer { flex-direction: column; align-items: flex-start; }
            .page-header { flex-direction: column; align-items: flex-start; }
        }
    </style>

    <?php if ($dbUser && $isVolunteer): ?>
    <!-- Sub Navigation -->
    <nav class="sub-nav">
        <a href="?tab=mywork" class="<?= $currentTab === 'mywork' ? 'active' : '' ?>">
            📋 My Work
            <?php if ($tabCounts['mywork'] > 0): ?>
            <span class="badge"><?= $tabCounts['mywork'] ?></span>
            <?php endif; ?>
        </a>
        <a href="?tab=available" class="<?= $currentTab === 'available' ? 'active' : '' ?>">
            🟢 Available
            <span class="badge"><?= $tabCounts['available'] ?></span>
        </a>
        <a href="?tab=completed" class="<?= $currentTab === 'completed' ? 'active' : '' ?>">
            ✅ Completed
            <?php if ($tabCounts['completed'] > 0): ?>
            <span class="badge"><?= $tabCounts['completed'] ?></span>
            <?php endif; ?>
        </a>
        <?php if ($isPM): ?>
        <a href="?tab=pm" class="<?= $currentTab === 'pm' ? 'active' : '' ?>">
            📊 PM
            <?php if ($tabCounts['pending_claims'] + $tabCounts['pending_reviews'] > 0): ?>
            <span class="badge alert"><?= $tabCounts['pending_claims'] + $tabCounts['pending_reviews'] ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>
    </nav>
    <?php endif; ?>
    
    <div class="main-container">
        <?php if (!$dbUser): ?>
        <!-- Not Logged In -->
        <div class="notice-box">
            <h2>Welcome to the Volunteer Workspace</h2>
            <p>You need to be signed in to view and claim tasks.</p>
            <a href="/profile.php" class="btn btn-primary">Sign In First</a>
        </div>
        
        <?php elseif (!$isVolunteer && $volunteerStatus !== 'pending'): ?>
        <!-- Not a Volunteer Yet -->
        <div class="notice-box">
            <h2>Want to Help Build TPB?</h2>
            <p>Apply to become a volunteer and help build the Fourth Branch.</p>
            <a href="apply.php" class="btn btn-primary">Apply to Volunteer</a>
        </div>
        
        <?php elseif ($volunteerStatus === 'pending'): ?>
        <!-- Application Pending -->
        <div class="notice-box">
            <h2>Application Pending</h2>
            <p>Your volunteer application is being reviewed. We'll notify you when it's approved.</p>
            <a href="/profile.php" class="btn btn-secondary">Back to Profile</a>
        </div>
        
        <?php else: ?>
        <!-- Volunteer Workspace -->
        
        <div class="page-header">
            <div>
                <h1 class="page-title">
                    <?php
                    switch ($currentTab) {
                        case 'mywork': echo '📋 My Work'; break;
                        case 'completed': echo '✅ Completed Tasks'; break;
                        case 'pm': echo '📊 Project Management'; break;
                        default: echo '🟢 Available Tasks';
                    }
                    ?>
                </h1>
                <p class="page-subtitle">
                    <?php
                    switch ($currentTab) {
                        case 'mywork': echo 'Tasks you\'ve claimed and are working on'; break;
                        case 'completed': echo 'Tasks you\'ve completed • Points earned'; break;
                        case 'pm': echo 'Approve claims • Review completions • Create tasks'; break;
                        default: echo 'Claim a task and help build the Fourth Branch';
                    }
                    ?>
                </p>
            </div>
            
            <?php if ($currentTab === 'pm' || ($currentTab === 'available' && $isPM)): ?>
            <button class="btn btn-primary" style="color:#000!important" onclick="openCreateModal()">+ Create Task</button>
            <?php endif; ?>
        </div>
        
        <!-- Stats Bar (only on available tab) -->
        <?php if ($currentTab === 'available'): ?>
        <div class="stats-bar">
            <div class="stat-box">
                <div class="stat-number"><?= $tabCounts['available'] ?></div>
                <div class="stat-label">Open Tasks</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?= $tabCounts['mywork'] ?></div>
                <div class="stat-label">My Active</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?= $tabCounts['completed'] ?></div>
                <div class="stat-label">Completed</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?= number_format($dbUser['civic_points']) ?></div>
                <div class="stat-label">My Points</div>
            </div>
        </div>
        
        <!-- Filter Bar -->
        <div class="filter-bar">
            <label style="color: var(--text-dim);">Filter by skill:</label>
            <select class="filter-select" onchange="window.location='?tab=available&skill='+this.value">
                <option value="all">All Skills</option>
                <?php foreach ($skillSets as $skill): ?>
                <option value="<?= $skill['skill_set_id'] ?>" <?= ($_GET['skill'] ?? '') == $skill['skill_set_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($skill['set_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        
        <!-- PM Tab: Pending Actions -->
        <?php if ($currentTab === 'pm'): ?>
            <?php if ($tabCounts['pending_claims'] > 0 || $tabCounts['pending_reviews'] > 0): ?>
            <div class="pending-action-card">
                <h4>⚠️ Actions Needed</h4>
                <p>
                    <?php if ($tabCounts['pending_claims'] > 0): ?>
                    <strong><?= $tabCounts['pending_claims'] ?></strong> claim<?= $tabCounts['pending_claims'] != 1 ? 's' : '' ?> waiting for approval
                    <?php endif; ?>
                    <?php if ($tabCounts['pending_claims'] > 0 && $tabCounts['pending_reviews'] > 0): ?> • <?php endif; ?>
                    <?php if ($tabCounts['pending_reviews'] > 0): ?>
                    <strong><?= $tabCounts['pending_reviews'] ?></strong> task<?= $tabCounts['pending_reviews'] != 1 ? 's' : '' ?> ready for review
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <!-- Seeds Section (only on available tab) -->
        <?php if ($currentTab === 'available' && $seedCount > 0): ?>
        <section class="seeds-section">
            <div class="seeds-header">
                <h2>🌱 Seeds (Ideas Needing Development)</h2>
                <span class="seed-count"><?= $seedCount ?> seed<?= $seedCount != 1 ? 's' : '' ?></span>
            </div>
            
            <?php foreach ($seedThoughts as $seed): ?>
            <div class="seed-card" data-thought-id="<?= $seed['thought_id'] ?>">
                <div>
                    <span style="color: var(--success);">🌱</span>
                    <strong>Seed from @<?= htmlspecialchars($seed['username'] ?? 'anonymous') ?></strong>
                </div>
                <div class="seed-content"><?= htmlspecialchars($seed['content']) ?></div>
                <div class="seed-meta">
                    <div class="seed-votes">
                        <span>👍 <?= $seed['upvotes'] ?></span>
                        <span>👎 <?= $seed['downvotes'] ?></span>
                        <span>📅 <?= date('M j', strtotime($seed['created_at'])) ?></span>
                    </div>
                    <?php if ($isPM): ?>
                    <button class="expand-btn" onclick="openExpandModal(<?= $seed['thought_id'] ?>, '<?= htmlspecialchars(addslashes(substr($seed['content'], 0, 100))) ?>')">
                        🌿 Expand to Task
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>
        
        <!-- Task List -->
        <div class="task-list">
            <?php if (empty($tasks)): ?>
            <div class="empty-state">
                <h3>
                    <?php
                    switch ($currentTab) {
                        case 'mywork': echo 'No Active Tasks'; break;
                        case 'completed': echo 'No Completed Tasks Yet'; break;
                        case 'pm': echo 'No Pending Actions'; break;
                        default: echo 'No Tasks Available';
                    }
                    ?>
                </h3>
                <p>
                    <?php
                    switch ($currentTab) {
                        case 'mywork': echo 'Claim a task from the Available tab to get started!'; break;
                        case 'completed': echo 'Complete some tasks to see them here.'; break;
                        case 'pm': echo 'All caught up! No claims or reviews pending.'; break;
                        default: echo 'Check back later for new tasks.';
                    }
                    ?>
                </p>
            </div>
            <?php else: ?>
                <?php foreach ($tasks as $task): 
                    $hierarchyPath = getTaskHierarchyPath($pdo, $task['task_id'], $task['parent_task_id'] ?? null);
                    $isFromSeed = !empty($task['source_thought_id']);
                    $hasPendingClaim = ($task['claim_status'] ?? 'none') === 'pending';
                    $isInReview = $task['status'] === 'review';
                    $isMyTask = ($task['claimed_by_user_id'] ?? null) == $dbUser['user_id'];
                ?>
                <div class="task-card <?= $task['priority'] === 'high' || $task['priority'] === 'critical' ? 'featured' : '' ?> <?= $hasPendingClaim || $isInReview ? 'pending-action' : '' ?>">
                    <div class="task-header">
                        <div class="task-meta">
                            <span class="task-id">#<?= $task['task_id'] ?></span>
                            <?php if ($task['parent_task_id'] ?? null): ?>
                            <span class="task-hierarchy">(<?= $hierarchyPath ?>)</span>
                            <?php endif; ?>
                            
                            <span class="task-badge" style="background: rgba(<?= $task['priority'] === 'critical' ? '231,76,60' : ($task['priority'] === 'high' ? '243,156,18' : ($task['priority'] === 'medium' ? '52,152,219' : '46,204,113')) ?>, 0.2); color: <?= $priorityColors[$task['priority']] ?>;">
                                <?= ucfirst($task['priority']) ?>
                            </span>
                            
                            <span class="task-badge" style="background: rgba(<?= $task['status'] === 'open' ? '46,204,113' : ($task['status'] === 'completed' ? '212,175,55' : ($task['status'] === 'review' ? '155,89,182' : '243,156,18')) ?>, 0.2); color: <?= $statusColors[$task['status']] ?>;">
                                <?= ucfirst(str_replace('_', ' ', $task['status'])) ?>
                            </span>
                            
                            <?php if ($hasPendingClaim): ?>
                            <span class="task-badge" style="background: rgba(243,156,18,0.2); color: var(--warning);">
                                ⏳ Claim Pending
                            </span>
                            <?php endif; ?>
                        </div>
                        <div class="task-points">
                            <span>+<?= $task['points'] ?></span>
                            <span>pts</span>
                        </div>
                    </div>
                    
                    <h3 class="task-title"><?= htmlspecialchars($task['title']) ?></h3>
                    
                    <p class="task-description"><?= nl2br(htmlspecialchars(substr($task['short_description'], 0, 300))) ?><?= strlen($task['short_description']) > 300 ? '...' : '' ?></p>
                    
                    <!-- Claim Status Info -->
                    <?php if ($hasPendingClaim && $currentTab === 'pm'): ?>
                    <div class="claim-status">
                        <span class="claim-status-dot" style="background: var(--warning);"></span>
                        <span><strong>@<?= htmlspecialchars($task['claimed_by_username']) ?></strong> wants to claim this task</span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($isInReview && $currentTab === 'pm'): ?>
                    <div class="claim-status">
                        <span class="claim-status-dot" style="background: var(--purple);"></span>
                        <span><strong>@<?= htmlspecialchars($task['claimed_by_username']) ?></strong> marked this as complete - needs review</span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="task-footer">
                        <div class="task-tags">
                            <?php if ($task['skill_name']): ?>
                            <span class="task-tag"><?= htmlspecialchars($task['skill_name']) ?></span>
                            <?php endif; ?>
                            <?php if ($isFromSeed): ?>
                            <span class="task-tag" style="background: rgba(46,204,113,0.1); color: var(--success);">🌱 From seed</span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="task-actions">
                            <?php if ($currentTab === 'available' && $task['status'] === 'open'): ?>
                                <!-- Request to Claim -->
                                <a href="task.php?id=<?= $task['task_id'] ?>" class="btn btn-secondary btn-small">View Details</a>
                                <form action="/api/claim.php" method="POST" style="display:inline;">
                                    <input type="hidden" name="task_id" value="<?= $task['task_id'] ?>">
                                    <input type="hidden" name="action" value="request">
                                    <button type="submit" class="btn btn-primary btn-small" style="color:#000!important">Request to Claim</button>
                                </form>
                                
                            <?php elseif ($currentTab === 'mywork'): ?>
                                <!-- Working on it -->
                                <a href="task.php?id=<?= $task['task_id'] ?>" class="btn btn-secondary btn-small">View Details</a>
                                <?php if ($task['status'] === 'claimed'): ?>
                                <form action="/api/claim.php" method="POST" style="display:inline;">
                                    <input type="hidden" name="task_id" value="<?= $task['task_id'] ?>">
                                    <input type="hidden" name="action" value="start">
                                    <button type="submit" class="btn btn-warning btn-small" style="color:#1a1a1a!important">Start Working</button>
                                </form>
                                <?php elseif ($task['status'] === 'in_progress'): ?>
                                <form action="/api/claim.php" method="POST" style="display:inline;">
                                    <input type="hidden" name="task_id" value="<?= $task['task_id'] ?>">
                                    <input type="hidden" name="action" value="complete">
                                    <button type="submit" class="btn btn-success btn-small" style="color:#fff!important">Mark Complete</button>
                                </form>
                                <?php endif; ?>
                                
                            <?php elseif ($currentTab === 'completed'): ?>
                                <!-- Completed task -->
                                <span style="color: var(--gold);">✓ Completed <?= date('M j, Y', strtotime($task['completed_at'])) ?></span>
                                
                            <?php elseif ($currentTab === 'pm'): ?>
                                <!-- PM Actions -->
                                <?php if ($hasPendingClaim): ?>
                                <form action="/api/claim.php" method="POST" style="display:inline;">
                                    <input type="hidden" name="task_id" value="<?= $task['task_id'] ?>">
                                    <input type="hidden" name="action" value="approve_claim">
                                    <button type="submit" class="btn btn-success btn-small" style="color:#fff!important">✓ Approve Claim</button>
                                </form>
                                <form action="/api/claim.php" method="POST" style="display:inline;">
                                    <input type="hidden" name="task_id" value="<?= $task['task_id'] ?>">
                                    <input type="hidden" name="action" value="deny_claim">
                                    <button type="submit" class="btn btn-danger btn-small" style="color:#fff!important">✗ Deny</button>
                                </form>
                                <?php elseif ($isInReview): ?>
                                <form action="/api/claim.php" method="POST" style="display:inline;">
                                    <input type="hidden" name="task_id" value="<?= $task['task_id'] ?>">
                                    <input type="hidden" name="action" value="approve_complete">
                                    <button type="submit" class="btn btn-success btn-small" style="color:#fff!important">✓ Approve Completion</button>
                                </form>
                                <form action="/api/claim.php" method="POST" style="display:inline;">
                                    <input type="hidden" name="task_id" value="<?= $task['task_id'] ?>">
                                    <input type="hidden" name="action" value="reject_complete">
                                    <button type="submit" class="btn btn-warning btn-small" style="color:#1a1a1a!important">↩ Needs More Work</button>
                                </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <?php endif; ?>
    </div>
    
    <!-- Create Task Modal (PM only) -->
    <?php if ($isPM): ?>
    <div class="modal" id="createModal">
        <div class="modal-content">
            <h3>📋 Create New Task</h3>
            <form id="createForm">
                <div class="form-group">
                    <label>Task Title *</label>
                    <input type="text" id="createTitle" name="title" placeholder="Clear, actionable title" required maxlength="255">
                </div>
                
                <div class="form-group">
                    <label>Short Description *</label>
                    <p style="color: var(--gold); font-size: 0.85em; margin-bottom: 8px;">📝 All content must be appropriate for all ages.</p>
                    <textarea id="createDescription" name="description" placeholder="What needs to be done?" required></textarea>
                </div>
                
                <div class="form-group">
                    <label>Parent Task (optional - for subtasks)</label>
                    <select id="createParent" name="parent_task_id">
                        <option value="">None (top-level task)</option>
                        <?php 
                        $allTasks = $pdo->query("SELECT task_id, title FROM tasks ORDER BY task_id DESC")->fetchAll();
                        foreach ($allTasks as $t): 
                        ?>
                        <option value="<?= $t['task_id'] ?>">#<?= $t['task_id'] ?> - <?= htmlspecialchars(substr($t['title'], 0, 50)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Priority</label>
                    <select id="createPriority" name="priority">
                        <option value="low">🟢 Low</option>
                        <option value="medium" selected>🔵 Medium</option>
                        <option value="high">🟠 High</option>
                        <option value="critical">🔴 Critical</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Skill Area</label>
                    <select id="createSkill" name="skill_set_id">
                        <option value="">Select skill area...</option>
                        <?php foreach ($skillSets as $skill): ?>
                        <option value="<?= $skill['skill_set_id'] ?>"><?= htmlspecialchars($skill['set_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div style="display: flex; gap: 15px;">
                    <div class="form-group" style="flex: 1;">
                        <label>Points</label>
                        <input type="number" id="createPoints" name="points" value="25" min="5" max="500">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label>Due Date (optional)</label>
                        <input type="date" id="createDueDate" name="due_date">
                    </div>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeCreateModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="color:#000!important">📋 Create Task</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Expand Seed Modal -->
    <div class="modal" id="expandModal">
        <div class="modal-content">
            <h3>🌱 → 🌿 Expand Seed to Task</h3>
            <form id="expandForm">
                <input type="hidden" id="expandThoughtId" name="thought_id">
                
                <div class="form-group">
                    <label>Task Title *</label>
                    <input type="text" id="expandTitle" name="title" placeholder="Clear, actionable title" required maxlength="255">
                </div>
                
                <div class="form-group">
                    <label>Priority</label>
                    <select id="expandPriority" name="priority">
                        <option value="low">🟢 Low</option>
                        <option value="medium" selected>🔵 Medium</option>
                        <option value="high">🟠 High</option>
                        <option value="critical">🔴 Critical</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Skill Area</label>
                    <select id="expandSkill" name="skill_set_id">
                        <option value="">Select skill area...</option>
                        <?php foreach ($skillSets as $skill): ?>
                        <option value="<?= $skill['skill_set_id'] ?>"><?= htmlspecialchars($skill['set_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Points</label>
                    <input type="number" id="expandPoints" name="points" value="25" min="5" max="500">
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeExpandModal()">Cancel</button>
                    <button type="submit" class="btn btn-success" style="color:#fff!important">🌿 Create Task Card</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    
    <script>
    // Modal Functions
    function openCreateModal() {
        document.getElementById('createModal')?.classList.add('show');
    }
    
    function closeCreateModal() {
        document.getElementById('createModal')?.classList.remove('show');
    }
    
    function openExpandModal(thoughtId, previewContent) {
        document.getElementById('expandThoughtId').value = thoughtId;
        document.getElementById('expandTitle').value = '';
        document.getElementById('expandTitle').placeholder = previewContent + '...';
        document.getElementById('expandModal')?.classList.add('show');
    }
    
    function closeExpandModal() {
        document.getElementById('expandModal')?.classList.remove('show');
    }
    
    // Close modals on outside click
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('show');
            }
        });
    });
    
    // Handle create form submission
    document.getElementById('createForm')?.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const title = document.getElementById('createTitle').value.trim();
        const description = document.getElementById('createDescription').value.trim();
        const parentTaskId = document.getElementById('createParent').value;
        const priority = document.getElementById('createPriority').value;
        const skillSetId = document.getElementById('createSkill').value;
        const points = document.getElementById('createPoints').value;
        const dueDate = document.getElementById('createDueDate').value;
        
        if (!title || !description) {
            alert('Please fill in title and description');
            return;
        }
        
        const btn = this.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.textContent = 'Creating...';
        
        try {
            const response = await fetch('/api/create-task.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    title,
                    description,
                    parent_task_id: parentTaskId || null,
                    priority,
                    skill_set_id: skillSetId || null,
                    points: parseInt(points),
                    due_date: dueDate || null
                })
            });
            
            const data = await response.json();
            
            if (data.status === 'success') {
                closeCreateModal();
                window.location.reload();
            } else {
                alert(data.message || 'Failed to create task');
                btn.disabled = false;
                btn.textContent = '📋 Create Task';
            }
        } catch (err) {
            alert('Error creating task. Please try again.');
            btn.disabled = false;
            btn.textContent = '📋 Create Task';
        }
    });
    
    // Handle expand form submission
    document.getElementById('expandForm')?.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const thoughtId = document.getElementById('expandThoughtId').value;
        const title = document.getElementById('expandTitle').value.trim();
        const priority = document.getElementById('expandPriority').value;
        const skillSetId = document.getElementById('expandSkill').value;
        const points = document.getElementById('expandPoints').value;
        
        if (!title) {
            alert('Please enter a task title');
            return;
        }
        
        const btn = this.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.textContent = 'Creating...';
        
        try {
            const response = await fetch('/api/expand-task.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    thought_id: thoughtId,
                    title,
                    priority,
                    skill_set_id: skillSetId || null,
                    points: parseInt(points)
                })
            });
            
            const data = await response.json();
            
            if (data.status === 'success') {
                closeExpandModal();
                window.location.reload();
            } else {
                alert(data.message || 'Failed to expand task');
                btn.disabled = false;
                btn.textContent = '🌿 Create Task Card';
            }
        } catch (err) {
            alert('Error expanding task. Please try again.');
            btn.disabled = false;
            btn.textContent = '🌿 Create Task Card';
        }
    });
    </script>
</body>
</html>
