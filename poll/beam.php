<?php
/**
 * Beam to Desk — Representative Targeting View.
 *
 * Shows ratified declarations, public opinion support, representatives,
 * and a formatted civic mandate message.
 *
 * Phase 5 of the Civic Engine.
 *
 * Params: scope_type, scope_id
 */

$config = require __DIR__ . '/../config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

require_once __DIR__ . '/../includes/get-user.php';
require_once __DIR__ . '/../includes/rollup.php';

$dbUser = getUser($pdo);

// Nav variables
$navVars = getNavVarsForUser($dbUser);
extract($navVars);

$currentPage = 'ballots';
$pageTitle   = 'Beam to Desk | The People\'s Branch';
$headLinks   = '<link rel="stylesheet" href="/assets/rollup.css">';

// Get scope from URL
$scopeType = $_GET['scope_type'] ?? '';
$scopeId   = $_GET['scope_id'] ?? '';

if (empty($scopeType) || !in_array($scopeType, ['federal', 'state', 'town'], true)) {
    $scopeType = 'federal';
    $scopeId   = 'federal';
}

// Require login
$requireLogin = !$dbUser;

// Fetch beam data if logged in
$beamData = null;
if (!$requireLogin && !empty($scopeId)) {
    $beamData = Rollup::beamToDesk($pdo, $scopeType, $scopeId);
}

// Scope label for display
$scopeLabel = ucfirst($scopeType);
if ($scopeType === 'town' && $scopeId) {
    $parts = explode('-', $scopeId, 2);
    $scopeLabel = ucfirst($parts[1] ?? $scopeId) . ', ' . strtoupper($parts[0] ?? '');
} elseif ($scopeType === 'state' && $scopeId) {
    $scopeLabel = strtoupper($scopeId);
} elseif ($scopeType === 'federal') {
    $scopeLabel = 'United States';
}

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/nav.php';
?>

<div class="rollup-page">

    <!-- ================================================================ -->
    <!-- PAGE HEADER                                                       -->
    <!-- ================================================================ -->
    <div class="rollup-header">
        <h1 class="rollup-title">Beam to Desk</h1>
        <p class="rollup-subtitle">Civic mandate for <?= htmlspecialchars($scopeLabel, ENT_QUOTES, 'UTF-8') ?></p>
    </div>

    <?php if ($requireLogin): ?>
        <!-- ============================================================ -->
        <!-- LOGIN REQUIRED                                                -->
        <!-- ============================================================ -->
        <div class="rollup-card">
            <div class="rollup-empty">
                <p>You must be logged in to use Beam to Desk.</p>
                <p><a href="/login.php" style="color: #d4af37;">Log in</a> or <a href="/join.php" style="color: #d4af37;">create an account</a>.</p>
            </div>
        </div>

    <?php elseif (!$beamData || empty($beamData['declarations'])): ?>
        <!-- ============================================================ -->
        <!-- NO DECLARATIONS                                               -->
        <!-- ============================================================ -->
        <div class="rollup-card">
            <div class="rollup-empty">
                <p>No ratified declarations for this scope yet.</p>
                <p class="rollup-hint">Groups must deliberate, vote, and ratify declarations before they can be beamed to representatives.</p>
            </div>
        </div>

    <?php else: ?>
        <!-- ============================================================ -->
        <!-- DECLARATIONS WITH OPINION SUPPORT                             -->
        <!-- ============================================================ -->
        <div class="rollup-card">
            <h2 class="rollup-card-heading">Ratified Declarations (<?= count($beamData['declarations']) ?>)</h2>
            <div class="beam-declarations-list">
                <?php foreach ($beamData['declarations'] as $decl):
                    $dId = (int) $decl['declaration_id'];
                    $sentiment = $beamData['opinion_support'][$dId] ?? ['agree' => 0, 'disagree' => 0, 'mixed' => 0, 'total' => 0];
                    $agreeP = $sentiment['total'] > 0 ? round(($sentiment['agree'] / $sentiment['total']) * 100, 1) : 0;
                ?>
                    <div class="beam-declaration-item">
                        <div class="beam-declaration-title">
                            <?= htmlspecialchars($decl['title'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div class="beam-declaration-body">
                            <?= nl2br(htmlspecialchars(mb_substr($decl['body'], 0, 500), ENT_QUOTES, 'UTF-8')) ?><?= mb_strlen($decl['body']) > 500 ? '...' : '' ?>
                        </div>
                        <div class="beam-declaration-stats">
                            <span class="beam-stat">
                                <strong><?= (int) $decl['yes_count'] ?></strong>/<?= (int) $decl['vote_count'] ?> votes
                            </span>
                            <?php if ($sentiment['total'] > 0): ?>
                                <span class="beam-stat beam-stat-agree">
                                    <?= $agreeP ?>% public agreement (<?= $sentiment['total'] ?> opinions)
                                </span>
                            <?php endif; ?>
                            <?php if ($decl['ratified_at']): ?>
                                <span class="beam-stat">
                                    Ratified <?= date('M j, Y', strtotime($decl['ratified_at'])) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <!-- Opinion sentiment bar -->
                        <?php if ($sentiment['total'] > 0):
                            $disagreeP = round(($sentiment['disagree'] / $sentiment['total']) * 100, 1);
                            $mixedP    = round(($sentiment['mixed'] / $sentiment['total']) * 100, 1);
                        ?>
                            <div class="beam-sentiment-bar">
                                <?php if ($agreeP > 0): ?>
                                    <div class="beam-seg beam-seg-agree" style="width: <?= $agreeP ?>%" title="Agree: <?= $agreeP ?>%"></div>
                                <?php endif; ?>
                                <?php if ($disagreeP > 0): ?>
                                    <div class="beam-seg beam-seg-disagree" style="width: <?= $disagreeP ?>%" title="Disagree: <?= $disagreeP ?>%"></div>
                                <?php endif; ?>
                                <?php if ($mixedP > 0): ?>
                                    <div class="beam-seg beam-seg-mixed" style="width: <?= $mixedP ?>%" title="Mixed: <?= $mixedP ?>%"></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- REPRESENTATIVES                                               -->
        <!-- ============================================================ -->
        <div class="rollup-card">
            <h2 class="rollup-card-heading">Representatives</h2>
            <?php if (empty($beamData['representatives'])): ?>
                <div class="rollup-empty">
                    <p>No representative data available for this scope.</p>
                    <p class="rollup-hint">Representative data will be linked as the platform grows.</p>
                </div>
            <?php else: ?>
                <div class="beam-reps-grid">
                    <?php foreach ($beamData['representatives'] as $rep): ?>
                        <div class="beam-rep-card">
                            <div class="beam-rep-name"><?= htmlspecialchars($rep['full_name'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="beam-rep-meta">
                                <?= htmlspecialchars($rep['position'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                <?php if (!empty($rep['party'])): ?>
                                    &middot; <?= htmlspecialchars($rep['party'], ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                                <?php if (!empty($rep['state'])): ?>
                                    &middot; <?= htmlspecialchars($rep['state'], ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ============================================================ -->
        <!-- CIVIC MANDATE MESSAGE                                         -->
        <!-- ============================================================ -->
        <div class="rollup-card">
            <h2 class="rollup-card-heading">Civic Mandate Message</h2>
            <p class="beam-message-hint">This is the formatted message summarizing the civic mandate. Copy or use the send button below.</p>
            <pre class="beam-message" id="beamMessage"><?= htmlspecialchars($beamData['message'], ENT_QUOTES, 'UTF-8') ?></pre>
            <div class="beam-actions">
                <button class="beam-copy-btn" id="beamCopyBtn" onclick="copyBeamMessage()">
                    Copy to Clipboard
                </button>
                <button class="beam-send-btn" disabled title="Coming soon: direct email/fax to representatives">
                    Send (Coming Soon)
                </button>
            </div>
        </div>
    <?php endif; ?>

    <!-- Back link -->
    <div class="rollup-back">
        <a href="/poll/pulse.php">&larr; Back to The Pulse</a>
    </div>

</div>

<script src="/assets/rollup.js"></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
