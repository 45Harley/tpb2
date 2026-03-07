<?php
/**
 * Pulse Dashboard — Federal-level Civic Pulse.
 *
 * National view of civic activity across all states, towns, and groups.
 * Phase 5 of the Civic Engine.
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
$pageTitle   = 'The Pulse | The People\'s Branch';
$headLinks   = '<link rel="stylesheet" href="/assets/rollup.css">';

// Fetch federal roll-up
$federal = Rollup::getFederalRollup($pdo);

// Convergence signals
$convergence = Rollup::findConvergence($pdo, 'federal');
$convergenceSlice = array_slice($convergence, 0, 8);

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/nav.php';
?>

<div class="rollup-page">

    <!-- ================================================================ -->
    <!-- PAGE HEADER                                                       -->
    <!-- ================================================================ -->
    <div class="rollup-header">
        <h1 class="rollup-title">The Pulse</h1>
        <p class="rollup-subtitle">National civic activity across The People's Branch</p>
    </div>

    <!-- ================================================================ -->
    <!-- NATIONAL PULSE                                                    -->
    <!-- ================================================================ -->
    <div class="rollup-card rollup-pulse-card">
        <div class="rollup-pulse-big">
            <div class="rollup-pulse-number"><?= $federal['pulse_score'] ?></div>
            <div class="rollup-pulse-label">National Pulse</div>
        </div>
        <div class="rollup-pulse-bar-track">
            <div class="rollup-pulse-bar-fill" style="width: <?= min($federal['pulse_score'], 100) ?>%"></div>
        </div>
        <div class="rollup-stats-row">
            <div class="rollup-stat-item">
                <div class="rollup-stat-num"><?= $federal['states']['active'] ?></div>
                <div class="rollup-stat-label">Active States</div>
            </div>
            <div class="rollup-stat-item">
                <div class="rollup-stat-num"><?= $federal['groups']['total'] ?></div>
                <div class="rollup-stat-label">Groups</div>
            </div>
            <div class="rollup-stat-item">
                <div class="rollup-stat-num"><?= $federal['declarations']['ratified'] ?></div>
                <div class="rollup-stat-label">Ratified</div>
            </div>
            <div class="rollup-stat-item">
                <div class="rollup-stat-num"><?= $federal['opinions']['total'] ?></div>
                <div class="rollup-stat-label">Opinions</div>
            </div>
            <div class="rollup-stat-item">
                <div class="rollup-stat-num"><?= $federal['ballots']['active'] ?></div>
                <div class="rollup-stat-label">Active Ballots</div>
            </div>
        </div>
    </div>

    <!-- ================================================================ -->
    <!-- ACTIVE STATES GRID                                                -->
    <!-- ================================================================ -->
    <div class="rollup-card">
        <h2 class="rollup-card-heading">Active States</h2>

        <?php if (empty($federal['active_states'])): ?>
            <div class="rollup-empty">
                <p>No states with civic activity yet.</p>
                <p class="rollup-hint">When citizens form groups and make declarations, their states light up here.</p>
            </div>
        <?php else: ?>
            <div class="rollup-states-grid">
                <?php foreach ($federal['active_states'] as $as): ?>
                    <a class="rollup-state-card" href="/z-states/<?= htmlspecialchars(strtolower($as['state_abbr']), ENT_QUOTES, 'UTF-8') ?>/">
                        <div class="rollup-state-abbr"><?= htmlspecialchars($as['state_abbr'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="rollup-state-name"><?= htmlspecialchars($as['state_name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="rollup-state-meta">
                            <?= (int) $as['groups'] ?> groups &middot; <?= (int) $as['declarations'] ?> declarations
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ================================================================ -->
    <!-- TOP DECLARATIONS NATIONALLY                                       -->
    <!-- ================================================================ -->
    <?php if (!empty($federal['top_declarations'])): ?>
    <div class="rollup-card">
        <h2 class="rollup-card-heading">Top Declarations</h2>
        <div class="rollup-declarations-list">
            <?php foreach ($federal['top_declarations'] as $decl): ?>
                <div class="rollup-declaration-item">
                    <div class="rollup-declaration-title">
                        <?= htmlspecialchars($decl['title'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div class="rollup-declaration-meta">
                        <span class="rollup-declaration-scope"><?= htmlspecialchars($decl['scope_type'] . ':' . ($decl['scope_id'] ?? 'federal'), ENT_QUOTES, 'UTF-8') ?></span>
                        <span><?= (int) $decl['yes_count'] ?>/<?= (int) $decl['vote_count'] ?> votes</span>
                        <?php if ($decl['ratified_at']): ?>
                            <span>Ratified <?= date('M j, Y', strtotime($decl['ratified_at'])) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($decl['group_name'])): ?>
                            <span>by <?= htmlspecialchars($decl['group_name'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ================================================================ -->
    <!-- CONVERGENCE SIGNALS                                               -->
    <!-- ================================================================ -->
    <?php if (!empty($convergenceSlice)): ?>
    <div class="rollup-card">
        <h2 class="rollup-card-heading">Convergence Signals</h2>
        <p class="rollup-card-desc">Topics where multiple jurisdictions are declaring the same thing.</p>
        <div class="rollup-convergence-grid">
            <?php foreach ($convergenceSlice as $conv): ?>
                <div class="rollup-convergence-item">
                    <div class="rollup-convergence-topic"><?= htmlspecialchars($conv['topic'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="rollup-convergence-detail">
                        <?= (int) $conv['jurisdiction_count'] ?> jurisdictions &middot;
                        <?= (int) $conv['declaration_count'] ?> declarations
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ================================================================ -->
    <!-- BEAM TO DESK                                                      -->
    <!-- ================================================================ -->
    <?php if ($federal['declarations']['ratified'] > 0): ?>
    <div class="rollup-card rollup-beam-card">
        <h2 class="rollup-card-heading">Beam to Desk</h2>
        <p class="rollup-beam-desc">
            <?= $federal['declarations']['ratified'] ?> ratified declaration<?= $federal['declarations']['ratified'] !== 1 ? 's' : '' ?>
            ready to send to representatives.
        </p>
        <a href="/poll/beam.php?scope_type=federal&scope_id=federal" class="rollup-beam-btn">
            View Civic Mandate
        </a>
    </div>
    <?php endif; ?>

</div>

<script src="/assets/rollup.js"></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
