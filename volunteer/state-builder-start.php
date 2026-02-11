<?php
/**
 * State Builder - Start Page
 * ===========================
 * Onboarding for volunteers beginning a state build
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

// Load user
require_once __DIR__ . '/../includes/get-user.php';
$dbUser = getUser($pdo);

if (!$dbUser) {
    header('Location: /');
    exit;
}

$stateAbbr = $_GET['state'] ?? '';
$taskKey = $_GET['task_key'] ?? '';

// Get state info
$state = null;
if ($stateAbbr) {
    $stmt = $pdo->prepare("SELECT * FROM states WHERE abbreviation = ?");
    $stmt->execute([$stateAbbr]);
    $state = $stmt->fetch();
}

// Get task info
$task = null;
if ($taskKey) {
    $stmt = $pdo->prepare("SELECT * FROM tasks WHERE task_key = ?");
    $stmt->execute([$taskKey]);
    $task = $stmt->fetch();
}

$currentPage = 'volunteer';
$pageTitle = 'Start Building: ' . ($state['state_name'] ?? 'State') . ' | The People\'s Branch';

// Include header
require_once '../includes/header.php';
require_once '../includes/nav.php';
?>

<style>
.container {
    max-width: 900px;
    margin: 0 auto;
    padding: 40px 20px;
}
h1 {
    color: #d4af37;
    font-size: 2em;
    margin-bottom: 20px;
}
h2 {
    color: #88c0d0;
    font-size: 1.5em;
    margin-top: 2em;
    margin-bottom: 1em;
}
h3 {
    color: #e0e0e0;
    font-size: 1.2em;
    margin-top: 1.5em;
    margin-bottom: 0.5em;
}
p, li {
    color: #ccc;
    line-height: 1.7;
}
.intro {
    background: #1a1a2e;
    padding: 25px;
    border-left: 4px solid #d4af37;
    border-radius: 8px;
    margin-bottom: 2em;
}
.resource-card {
    background: #1a1a2e;
    border: 1px solid #2a2a3e;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 15px;
    transition: border-color 0.2s;
}
.resource-card:hover {
    border-color: #d4af37;
}
.resource-card h3 {
    margin-top: 0;
    color: #d4af37;
}
.btn {
    display: inline-block;
    padding: 12px 25px;
    background: #d4af37;
    color: #0a0a0a;
    text-decoration: none;
    border-radius: 8px;
    font-weight: bold;
    transition: background 0.2s;
}
.btn:hover {
    background: #e4bf47;
    text-decoration: none;
}
.btn-secondary {
    background: transparent;
    border: 1px solid #d4af37;
    color: #d4af37;
}
.btn-secondary:hover {
    background: rgba(212, 175, 55, 0.1);
}
.workflow {
    background: #0d1117;
    padding: 25px;
    border-radius: 10px;
    margin: 2em 0;
}
.workflow ol {
    margin-left: 20px;
    color: #ccc;
}
.workflow li {
    margin-bottom: 10px;
}
.workflow strong {
    color: #d4af37;
}
.estimated-time {
    background: rgba(212, 175, 55, 0.1);
    border: 1px solid #d4af37;
    padding: 15px 20px;
    border-radius: 8px;
    margin: 2em 0;
    text-align: center;
}
.actions {
    display: flex;
    gap: 15px;
    margin: 2em 0;
    flex-wrap: wrap;
}
.tips {
    background: #1a1a2e;
    padding: 20px;
    border-radius: 8px;
    margin-top: 2em;
}
.tips h3 {
    color: #88c0d0;
    margin-top: 0;
}
.tips ul {
    margin-left: 20px;
    color: #ccc;
}
.tips li {
    margin-bottom: 8px;
}
</style>

<div class="container">
    <h1>🏛️ Build: <?= htmlspecialchars($state['state_name'] ?? 'State') ?> State Page</h1>

    <div class="intro">
        <p><strong>Welcome, <?= htmlspecialchars($dbUser['first_name'] ?? $dbUser['username']) ?>!</strong></p>
        <p>You're about to build a comprehensive state page for <?= htmlspecialchars($state['state_name'] ?? 'this state') ?>.
        This page will help thousands of residents find benefits, understand their government, and connect with their representatives.</p>
        <p style="margin-bottom: 0;">The process takes 4-6 hours and you'll work with AI assistance to research data and generate code. No coding knowledge required!</p>
    </div>

    <div class="resources">
        <h2>📚 Your Resources</h2>

        <div class="resource-card">
            <h3>1. AI Build Guide</h3>
            <p>Work with Claude AI through structured research and content creation. This is your main resource.</p>
            <p><strong>What it includes:</strong> Phase-by-phase workflow, research tips, PHP code generation guidance, quality checklist.</p>
            <a href="/docs/state-builder/STATE-BUILDER-AI-GUIDE.md" target="_blank" class="btn">
                📖 View AI Guide →
            </a>
            <p style="margin-top: 10px; font-size: 0.9em; color: #888;">
                <strong>Tip:</strong> Download this file and upload it to Claude when you start your AI session.
            </p>
        </div>

        <div class="resource-card">
            <h3>2. Data Template</h3>
            <p>JSON schema showing all sections and data structure with Connecticut as example.</p>
            <p><strong>What it includes:</strong> Complete data structure for all 11 sections (overview, benefits, government, budget, etc.).</p>
            <a href="/docs/state-builder/state-data-template.json" target="_blank" class="btn">
                📊 View Template →
            </a>
        </div>

        <div class="resource-card">
            <h3>3. Reference Page</h3>
            <p>Current <?= htmlspecialchars($state['state_name'] ?? 'state') ?> page to understand existing content.</p>
            <p><strong>Use this to see:</strong> What structure to follow, what a state page looks like, existing data.</p>
            <a href="/<?= strtolower($stateAbbr) ?>/" target="_blank" class="btn">
                🔍 View Current Page →
            </a>
        </div>

        <div class="resource-card">
            <h3>4. Quality Checklist</h3>
            <p>Ensure your build meets all quality standards before submission.</p>
            <p><strong>What to check:</strong> Data accuracy, links working, officials current, mobile responsive.</p>
            <a href="/docs/state-builder/state-build-checklist.md" target="_blank" class="btn">
                ✅ View Checklist →
            </a>
        </div>

        <div class="resource-card">
            <h3>5. README (Overview)</h3>
            <p>Complete overview of the State Builder system, FAQ, and tips.</p>
            <a href="/docs/state-builder/README.md" target="_blank" class="btn">
                📄 View README →
            </a>
        </div>
    </div>

    <div class="workflow">
        <h2>🛠️ Build Workflow (7 Phases)</h2>
        <ol>
            <li><strong>State Basics & Overview</strong> - State facts, history, identity (15-20 min)</li>
            <li><strong>Benefits Programs Research</strong> - Housing, energy, seniors, EV, education (60-90 min) ⭐ Most time here</li>
            <li><strong>Government Structure</strong> - Governor, officials, legislature composition (30-45 min)</li>
            <li><strong>Budget, Economy, Elections</strong> - Fiscal data, economic indicators, voting (45-60 min)</li>
            <li><strong>Agencies, Education, Towns</strong> - State agencies, colleges, towns grid (30-45 min)</li>
            <li><strong>PHP Code Generation</strong> - Generate complete PHP file with all sections (60-90 min)</li>
            <li><strong>SQL & Documentation</strong> - Create SQL updates and BUILD-LOG (15-30 min)</li>
        </ol>
    </div>

    <div class="estimated-time">
        <strong>⏱️ Estimated Time:</strong> 4-6 hours total (can be done in multiple sessions)
    </div>

    <div class="actions">
        <a href="https://claude.ai" target="_blank" class="btn">
            🤖 Start AI Session in New Tab →
        </a>
        <?php if ($taskKey): ?>
        <a href="/volunteer/task.php?key=<?= htmlspecialchars($taskKey) ?>" class="btn btn-secondary">
            ← Back to Task
        </a>
        <?php endif; ?>
        <a href="/volunteer/" class="btn btn-secondary">
            ← Back to Dashboard
        </a>
    </div>

    <div class="tips">
        <h3>💡 Tips for Success</h3>
        <ul>
            <li><strong>Start with official .gov websites</strong> - Most reliable sources for state data</li>
            <li><strong>Benefits section is most valuable</strong> - Prioritize accuracy here, residents need this</li>
            <li><strong>Document every source</strong> - Builds trust and allows future updates</li>
            <li><strong>Write for citizens, not officials</strong> - Clear, direct, and actionable language</li>
            <li><strong>Take breaks</strong> - This is 4-6 hours of work, quality over speed</li>
            <li><strong>Don't aim for perfection</strong> - 95% accurate is better than never shipping</li>
            <li><strong>When stuck, ask Claude</strong> - AI can help find sources or draft content</li>
            <li><strong>Save your progress</strong> - Save the JSON file as you go, pick up later if needed</li>
        </ul>
    </div>

    <div style="background: rgba(76, 175, 80, 0.1); border: 1px solid #4caf50; padding: 20px; border-radius: 8px; margin-top: 2em;">
        <h3 style="color: #4caf50; margin-top: 0;">🎯 What You'll Deliver</h3>
        <p>When you're done, you'll upload a ZIP file containing:</p>
        <ul style="color: #ccc;">
            <li><strong><?= strtolower($stateAbbr) ?>-state-page.php</strong> - Complete PHP file (1,500-2,000 lines)</li>
            <li><strong><?= strtolower($stateAbbr) ?>-state-data.json</strong> - Structured data for reference</li>
            <li><strong><?= strtolower($stateAbbr) ?>-state-updates.sql</strong> - Database updates</li>
            <li><strong>BUILD-LOG-<?= strtoupper($stateAbbr) ?>.md</strong> - Build documentation</li>
        </ul>
        <p style="margin-bottom: 0;"><strong>Recognition:</strong> 500 civic points + "State Builder" badge + Your name in the BUILD-LOG</p>
    </div>

    <div style="margin-top: 3em; padding-top: 2em; border-top: 1px solid #333; text-align: center; color: #888;">
        <p>Questions? Check the <a href="/docs/state-builder/README.md" style="color: #88c0d0;">README FAQ</a> or ask in volunteer Slack.</p>
        <p style="margin-top: 1em;"><strong>Ready to build civic infrastructure? Open Claude and let's go!</strong> 🏛️</p>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
