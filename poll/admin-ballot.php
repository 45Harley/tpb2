<?php
/**
 * TPB Poll System - Admin Ballot Creator
 * ========================================
 * Create ballots of any type: Yes/No, Yes/No/No Vote, Multi-Choice, Ranked Choice.
 * Uses Ballot::create() from includes/ballot.php.
 */

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

// Auth
require_once __DIR__ . '/../includes/get-user.php';
$dbUser = getUser($pdo);

if (!$dbUser) {
    header('Location: /');
    exit;
}

// Check admin role
$adminCheck = $pdo->prepare("SELECT 1 FROM user_role_membership WHERE user_id = ? AND role_id = 1");
$adminCheck->execute([$dbUser['user_id']]);
if (!$adminCheck->fetch()) {
    header('Location: /');
    exit;
}

require_once __DIR__ . '/../includes/ballot.php';

$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $question  = trim($_POST['question'] ?? '');
    $voteType  = $_POST['vote_type'] ?? 'yes_no';
    $scopeType = $_POST['scope_type'] ?? 'national';
    $scopeId   = trim($_POST['scope_id'] ?? '');
    $threshold = $_POST['threshold_type'] ?? 'majority';
    $quorumType  = $_POST['quorum_type'] ?? 'none';
    $quorumValue = trim($_POST['quorum_value'] ?? '');

    // Collect options, filter empty strings
    $options = [];
    if (!empty($_POST['options']) && is_array($_POST['options'])) {
        $options = array_values(array_filter(array_map('trim', $_POST['options']), function ($v) {
            return $v !== '';
        }));
    }

    $data = [
        'question'       => $question,
        'vote_type'      => $voteType,
        'scope_type'     => $scopeType,
        'scope_id'       => $scopeId !== '' ? $scopeId : null,
        'threshold_type' => $threshold,
        'quorum_type'    => $quorumType,
        'quorum_value'   => $quorumValue !== '' ? (int) $quorumValue : null,
        'created_by'     => $dbUser['user_id'],
        'options'        => $options,
    ];

    $result = Ballot::create($pdo, $data);

    if (isset($result['error'])) {
        $error = $result['error'];
    } else {
        $message = "Ballot created successfully (Poll ID: {$result['poll_id']}).";
    }
}

$currentPage = 'admin';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav.php';
?>

<style>
    .ballot-form-wrap {
        max-width: 700px;
        margin: 40px auto;
        padding: 0 20px 60px;
    }
    .ballot-form-wrap h1 {
        color: #fff;
        font-size: 1.6rem;
        margin-bottom: 24px;
    }
    .ballot-form-wrap label {
        display: block;
        color: #ccc;
        font-size: 0.95rem;
        margin-bottom: 6px;
        margin-top: 18px;
    }
    .ballot-form-wrap textarea,
    .ballot-form-wrap select,
    .ballot-form-wrap input[type="text"],
    .ballot-form-wrap input[type="number"] {
        width: 100%;
        padding: 10px 12px;
        background: #1a1a2e;
        border: 1px solid #333;
        border-radius: 6px;
        color: #e0e0e0;
        font-size: 0.95rem;
        box-sizing: border-box;
    }
    .ballot-form-wrap textarea {
        min-height: 80px;
        resize: vertical;
    }
    .ballot-form-wrap select {
        cursor: pointer;
    }
    .ballot-form-wrap .msg-success {
        background: rgba(0,180,80,0.12);
        border: 1px solid #2ecc71;
        color: #2ecc71;
        padding: 12px 16px;
        border-radius: 6px;
        margin-bottom: 18px;
    }
    .ballot-form-wrap .msg-error {
        background: rgba(220,50,50,0.12);
        border: 1px solid #e74c3c;
        color: #e74c3c;
        padding: 12px 16px;
        border-radius: 6px;
        margin-bottom: 18px;
    }
    .ballot-form-wrap .btn-submit {
        display: inline-block;
        margin-top: 24px;
        padding: 12px 32px;
        background: linear-gradient(135deg, #f5c518, #e0a800);
        color: #1a1a2e;
        font-weight: 700;
        font-size: 1rem;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        transition: opacity 0.2s;
    }
    .ballot-form-wrap .btn-submit:hover {
        opacity: 0.85;
    }
    #options-section {
        display: none;
        margin-top: 18px;
        padding: 16px;
        background: rgba(255,255,255,0.03);
        border: 1px solid #333;
        border-radius: 6px;
    }
    #options-section h3 {
        color: #ccc;
        font-size: 1rem;
        margin: 0 0 12px;
    }
    .option-row {
        display: flex;
        gap: 8px;
        margin-bottom: 8px;
        align-items: center;
    }
    .option-row input[type="text"] {
        flex: 1;
        padding: 8px 10px;
        background: #1a1a2e;
        border: 1px solid #333;
        border-radius: 4px;
        color: #e0e0e0;
        font-size: 0.9rem;
    }
    .option-row .btn-remove {
        background: transparent;
        border: 1px solid #e74c3c;
        color: #e74c3c;
        padding: 6px 10px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 0.85rem;
    }
    .option-row .btn-remove:hover {
        background: rgba(231,76,60,0.15);
    }
    .btn-add-option {
        background: transparent;
        border: 1px solid #555;
        color: #b0b0b0;
        padding: 6px 14px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 0.85rem;
        margin-top: 4px;
    }
    .btn-add-option:hover {
        border-color: #888;
        color: #e0e0e0;
    }
    .ballot-links {
        margin-top: 30px;
        display: flex;
        gap: 20px;
    }
    .ballot-links a {
        color: #f5c518;
        text-decoration: none;
        font-size: 0.9rem;
    }
    .ballot-links a:hover {
        text-decoration: underline;
    }
</style>

<div class="ballot-form-wrap">
    <h1>Create Ballot</h1>

    <?php if ($message): ?>
        <div class="msg-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="msg-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <label for="question">Question *</label>
        <textarea id="question" name="question" required placeholder="What should the ballot ask?"><?= htmlspecialchars($_POST['question'] ?? '') ?></textarea>

        <label for="vote_type">Vote Type</label>
        <select id="vote_type" name="vote_type" onchange="toggleOptions()">
            <option value="yes_no" <?= ($_POST['vote_type'] ?? '') === 'yes_no' ? 'selected' : '' ?>>Yes / No</option>
            <option value="yes_no_novote" <?= ($_POST['vote_type'] ?? '') === 'yes_no_novote' ? 'selected' : '' ?>>Yes / No / No Vote</option>
            <option value="multi_choice" <?= ($_POST['vote_type'] ?? '') === 'multi_choice' ? 'selected' : '' ?>>Multi-Choice</option>
            <option value="ranked_choice" <?= ($_POST['vote_type'] ?? '') === 'ranked_choice' ? 'selected' : '' ?>>Ranked Choice</option>
        </select>

        <div id="options-section">
            <h3>Options</h3>
            <div id="options-list">
                <div class="option-row">
                    <input type="text" name="options[]" placeholder="Option 1">
                    <button type="button" class="btn-remove" onclick="removeOption(this)">Remove</button>
                </div>
                <div class="option-row">
                    <input type="text" name="options[]" placeholder="Option 2">
                    <button type="button" class="btn-remove" onclick="removeOption(this)">Remove</button>
                </div>
            </div>
            <button type="button" class="btn-add-option" onclick="addOption()">+ Add Option</button>
        </div>

        <label for="scope_type">Scope Type</label>
        <select id="scope_type" name="scope_type">
            <option value="national" <?= ($_POST['scope_type'] ?? '') === 'national' ? 'selected' : '' ?>>Federal</option>
            <option value="state" <?= ($_POST['scope_type'] ?? '') === 'state' ? 'selected' : '' ?>>State</option>
            <option value="town" <?= ($_POST['scope_type'] ?? '') === 'town' ? 'selected' : '' ?>>Town</option>
            <option value="group" <?= ($_POST['scope_type'] ?? '') === 'group' ? 'selected' : '' ?>>Group</option>
        </select>

        <label for="scope_id">Scope ID <span style="color:#888; font-size:0.85rem;">(state abbr, town slug, or group ID — leave blank for federal)</span></label>
        <input type="text" id="scope_id" name="scope_id" placeholder="e.g. ct, putnam, 5" value="<?= htmlspecialchars($_POST['scope_id'] ?? '') ?>">

        <label for="threshold_type">Threshold</label>
        <select id="threshold_type" name="threshold_type">
            <option value="plurality" <?= ($_POST['threshold_type'] ?? '') === 'plurality' ? 'selected' : '' ?>>Plurality</option>
            <option value="majority" <?= ($_POST['threshold_type'] ?? 'majority') === 'majority' ? 'selected' : '' ?>>Majority</option>
            <option value="three_fifths" <?= ($_POST['threshold_type'] ?? '') === 'three_fifths' ? 'selected' : '' ?>>Three-Fifths</option>
            <option value="two_thirds" <?= ($_POST['threshold_type'] ?? '') === 'two_thirds' ? 'selected' : '' ?>>Two-Thirds</option>
            <option value="three_quarters" <?= ($_POST['threshold_type'] ?? '') === 'three_quarters' ? 'selected' : '' ?>>Three-Quarters</option>
            <option value="unanimous" <?= ($_POST['threshold_type'] ?? '') === 'unanimous' ? 'selected' : '' ?>>Unanimous</option>
        </select>

        <label for="quorum_type">Quorum Type</label>
        <select id="quorum_type" name="quorum_type">
            <option value="none" <?= ($_POST['quorum_type'] ?? 'none') === 'none' ? 'selected' : '' ?>>None</option>
            <option value="minimum" <?= ($_POST['quorum_type'] ?? '') === 'minimum' ? 'selected' : '' ?>>Minimum votes required</option>
            <option value="percent" <?= ($_POST['quorum_type'] ?? '') === 'percent' ? 'selected' : '' ?>>Percentage of eligible</option>
        </select>

        <label for="quorum_value">Quorum Value <span style="color:#888; font-size:0.85rem;">(vote count or percentage)</span></label>
        <input type="number" id="quorum_value" name="quorum_value" min="0" placeholder="e.g. 10 or 50" value="<?= htmlspecialchars($_POST['quorum_value'] ?? '') ?>">

        <button type="submit" class="btn-submit">Create Ballot</button>
    </form>

    <div class="ballot-links">
        <a href="/poll/admin.php">&larr; Back to Poll Admin</a>
        <a href="/poll/ballots.php">View Ballots &rarr;</a>
    </div>
</div>

<script>
function toggleOptions() {
    var voteType = document.getElementById('vote_type').value;
    var section = document.getElementById('options-section');
    if (voteType === 'multi_choice' || voteType === 'ranked_choice') {
        section.style.display = 'block';
    } else {
        section.style.display = 'none';
    }
}

function addOption() {
    var list = document.getElementById('options-list');
    var count = list.querySelectorAll('.option-row').length + 1;
    var row = document.createElement('div');
    row.className = 'option-row';
    row.innerHTML = '<input type="text" name="options[]" placeholder="Option ' + count + '">' +
                    '<button type="button" class="btn-remove" onclick="removeOption(this)">Remove</button>';
    list.appendChild(row);
}

function removeOption(btn) {
    var list = document.getElementById('options-list');
    if (list.querySelectorAll('.option-row').length > 2) {
        btn.parentElement.remove();
    }
}

// Initialize on page load
toggleOptions();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
