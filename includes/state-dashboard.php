<?php
/**
 * State Dashboard Component — Civic Engine dashboard for state pages.
 *
 * Renders state pulse, declaration roll-ups from towns, active towns grid,
 * convergence signals, and Beam to Desk link.
 *
 * REQUIRED VARIABLES (set by the including page before require):
 *   $pdo        — PDO connection
 *   $dbUser     — user array from getUser() or false
 *   $stateAbbr  — string: e.g. 'ct' (lowercase)
 *
 * OPTIONAL:
 *   $dashPrefix — string for unique DOM IDs (default: 'sd')
 */

require_once __DIR__ . '/rollup.php';

$dashPrefix = $dashPrefix ?? 'sd';

// =====================================================
// Fetch state roll-up data
// =====================================================
$stateRollup = Rollup::getStateRollup($pdo, $stateAbbr);

if (isset($stateRollup['error'])) {
    echo '<div class="sd-error">Unable to load state dashboard data.</div>';
    return;
}

$stateName     = $stateRollup['state_name'];
$pulseScore    = $stateRollup['pulse_score'];
$townsTotal    = $stateRollup['towns']['total'];
$townsActive   = $stateRollup['towns']['active'];
$groupsTotal   = $stateRollup['groups']['total'];
$groupsActive  = $stateRollup['groups']['active'];
$declTotal     = $stateRollup['declarations']['total'];
$declRatified  = $stateRollup['declarations']['ratified'];
$opinionsTotal = $stateRollup['opinions']['total'];
$topDecl       = $stateRollup['top_declarations'];
$activeTowns   = $stateRollup['active_towns'];

// =====================================================
// Convergence signals
// =====================================================
$convergence = Rollup::findConvergence($pdo, 'state', $stateAbbr);
$convergenceSlice = array_slice($convergence, 0, 5);
?>

<div class="state-dashboard" id="<?= $dashPrefix ?>-dashboard">

    <!-- ================================================================ -->
    <!-- STATE PULSE METER                                                 -->
    <!-- ================================================================ -->
    <div class="sd-section sd-pulse-section">
        <h3 class="sd-heading">State Pulse &mdash; <?= htmlspecialchars($stateName, ENT_QUOTES, 'UTF-8') ?></h3>
        <div class="sd-pulse-meter">
            <div class="sd-pulse-big-score"><?= $pulseScore ?></div>
            <div class="sd-pulse-bar-track">
                <div class="sd-pulse-bar-fill" style="width: <?= min($pulseScore, 100) ?>%"></div>
            </div>
        </div>
        <div class="sd-pulse-stats">
            <span class="sd-stat">
                <strong><?= $townsActive ?></strong> / <?= $townsTotal ?> town<?= $townsTotal !== 1 ? 's' : '' ?> active
            </span>
            <span class="sd-stat">
                <strong><?= $groupsActive ?></strong> active group<?= $groupsActive !== 1 ? 's' : '' ?>
            </span>
            <span class="sd-stat">
                <strong><?= $declRatified ?></strong> ratified declaration<?= $declRatified !== 1 ? 's' : '' ?>
            </span>
            <span class="sd-stat">
                <strong><?= $opinionsTotal ?></strong> opinion<?= $opinionsTotal !== 1 ? 's' : '' ?>
            </span>
        </div>
    </div>

    <!-- ================================================================ -->
    <!-- TOP DECLARATIONS                                                  -->
    <!-- ================================================================ -->
    <div class="sd-section sd-declarations-section">
        <h3 class="sd-heading">Top Declarations</h3>

        <?php if (empty($topDecl)): ?>
            <div class="sd-empty">
                <p>No ratified declarations yet across <?= htmlspecialchars($stateName, ENT_QUOTES, 'UTF-8') ?>.</p>
                <p class="sd-hint">Town groups deliberate, vote, and declare. Those declarations roll up here.</p>
            </div>
        <?php else: ?>
            <div class="sd-declarations-list">
                <?php foreach ($topDecl as $decl): ?>
                    <div class="sd-declaration-card">
                        <div class="sd-declaration-header">
                            <h4 class="sd-declaration-title">
                                <?= htmlspecialchars($decl['title'], ENT_QUOTES, 'UTF-8') ?>
                            </h4>
                            <span class="sd-declaration-scope">
                                <?= htmlspecialchars($decl['scope_type'] . ':' . ($decl['scope_id'] ?? 'federal'), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                        <div class="sd-declaration-meta">
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
        <?php endif; ?>
    </div>

    <!-- ================================================================ -->
    <!-- ACTIVE TOWNS GRID                                                 -->
    <!-- ================================================================ -->
    <div class="sd-section sd-towns-section">
        <h3 class="sd-heading">Active Towns</h3>

        <?php if (empty($activeTowns)): ?>
            <div class="sd-empty">
                <p>No towns with civic activity yet.</p>
                <p class="sd-hint">When town groups form and create declarations, they appear here.</p>
            </div>
        <?php else: ?>
            <div class="sd-towns-grid">
                <?php foreach ($activeTowns as $at): ?>
                    <a class="sd-town-card" href="/z-states/<?= htmlspecialchars(strtolower($stateAbbr), ENT_QUOTES, 'UTF-8') ?>/<?= htmlspecialchars($at['slug'], ENT_QUOTES, 'UTF-8') ?>/">
                        <div class="sd-town-name"><?= htmlspecialchars($at['town_name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="sd-town-pulse">
                            <div class="sd-town-pulse-bar">
                                <div class="sd-town-pulse-fill" style="width: <?= min($at['pulse_score'], 100) ?>%"></div>
                            </div>
                            <span class="sd-town-pulse-num"><?= $at['pulse_score'] ?></span>
                        </div>
                        <div class="sd-town-meta">
                            <?= (int) $at['declarations'] ?> decl &middot; <?= (int) $at['groups'] ?> groups
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ================================================================ -->
    <!-- CONVERGENCE SIGNALS                                               -->
    <!-- ================================================================ -->
    <?php if (!empty($convergenceSlice)): ?>
    <div class="sd-section sd-convergence-section">
        <h3 class="sd-heading">Convergence Signals</h3>
        <div class="sd-convergence-list">
            <?php foreach ($convergenceSlice as $conv): ?>
                <div class="sd-convergence-card">
                    <div class="sd-convergence-topic"><?= htmlspecialchars($conv['topic'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="sd-convergence-detail">
                        <?= (int) $conv['jurisdiction_count'] ?> jurisdiction<?= (int) $conv['jurisdiction_count'] !== 1 ? 's' : '' ?>
                        &middot;
                        <?= (int) $conv['declaration_count'] ?> declaration<?= (int) $conv['declaration_count'] !== 1 ? 's' : '' ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ================================================================ -->
    <!-- BEAM TO DESK LINK                                                 -->
    <!-- ================================================================ -->
    <?php if ($declRatified > 0): ?>
    <div class="sd-section sd-beam-section">
        <a href="/poll/beam.php?scope_type=state&scope_id=<?= urlencode($stateAbbr) ?>" class="sd-beam-btn">
            Beam to Desk &mdash; Send <?= $declRatified ?> declaration<?= $declRatified !== 1 ? 's' : '' ?> to representatives
        </a>
    </div>
    <?php endif; ?>

</div><!-- .state-dashboard -->
