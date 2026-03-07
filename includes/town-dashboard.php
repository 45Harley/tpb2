<?php
/**
 * Town Dashboard Component — Civic Engine dashboard for town pages.
 *
 * Renders active groups, declarations with opinion cards, and a pulse meter.
 *
 * REQUIRED VARIABLES (set by the including page before require):
 *   $pdo        — PDO connection
 *   $dbUser     — user array from getUser() or false
 *   $townId     — int: towns.id
 *   $stateAbbr  — string: e.g. 'ct'
 *   $townSlug   — string: e.g. 'putnam'
 *
 * OPTIONAL:
 *   $dashPrefix — string for unique DOM IDs (default: 'td')
 */

require_once __DIR__ . '/opinion.php';

$dashPrefix = $dashPrefix ?? 'td';
$scopeId = strtolower($stateAbbr) . '-' . $townSlug;

// =====================================================
// QUERY: Active Groups for this town
// =====================================================
$stmtGroups = $pdo->prepare("
    SELECT g.id, g.name, g.description, g.status, g.created_at,
           COUNT(DISTINCT m.user_id) AS member_count
    FROM idea_groups g
    LEFT JOIN idea_group_members m ON m.group_id = g.id
    WHERE g.scope = 'town' AND g.town_id = :town_id
      AND g.status IN ('forming', 'active', 'crystallizing', 'crystallized')
    GROUP BY g.id
    ORDER BY FIELD(g.status, 'active', 'crystallizing', 'forming', 'crystallized'), g.created_at DESC
");
$stmtGroups->execute([':town_id' => $townId]);
$townGroups = $stmtGroups->fetchAll(PDO::FETCH_ASSOC);

// =====================================================
// QUERY: Declarations for this town scope
// =====================================================
$stmtDecl = $pdo->prepare("
    SELECT d.*, u.display_name AS author_name
    FROM declarations d
    LEFT JOIN users u ON u.user_id = d.created_by
    WHERE d.scope_type = 'town' AND d.scope_id = :scope_id
    ORDER BY d.status = 'ratified' DESC, d.created_at DESC
");
$stmtDecl->execute([':scope_id' => $scopeId]);
$townDeclarations = $stmtDecl->fetchAll(PDO::FETCH_ASSOC);

// Pre-fetch sentiment for all declarations in one batch
$declIds = array_column($townDeclarations, 'declaration_id');
$declSentiments = !empty($declIds) ? Opinion::getBatchSentiment($pdo, 'declaration', $declIds) : [];

// Pre-fetch user opinions if logged in
$userDeclOpinions = [];
if ($dbUser && !empty($declIds)) {
    $placeholders = implode(',', array_fill(0, count($declIds), '?'));
    $params = array_merge([(int) $dbUser['user_id'], 'declaration'], array_map('intval', $declIds));
    $stmtUO = $pdo->prepare(
        "SELECT * FROM public_opinions
         WHERE user_id = ? AND target_type = ? AND target_id IN ($placeholders)"
    );
    $stmtUO->execute($params);
    foreach ($stmtUO->fetchAll(PDO::FETCH_ASSOC) as $uo) {
        $userDeclOpinions[(int) $uo['target_id']] = $uo;
    }
}

// =====================================================
// PULSE METER calculations
// =====================================================
$totalGroups   = count($townGroups);
$activeGroups  = count(array_filter($townGroups, fn($g) => in_array($g['status'], ['active', 'crystallizing'])));
$totalDecl     = count($townDeclarations);
$ratifiedDecl  = count(array_filter($townDeclarations, fn($d) => $d['status'] === 'ratified'));

// Calculate "civic pulse" as a percentage (weighted composite)
// Groups: 40% weight, Declarations: 30% weight, Participation: 30% weight
$pulseGroups = $totalGroups > 0 ? min(100, ($activeGroups / max($totalGroups, 1)) * 100) : 0;
$pulseDeclare = $totalDecl > 0 ? min(100, ($ratifiedDecl / max($totalDecl, 1)) * 100) : 0;

// Participation: opinions submitted this month for town-scoped items
$stmtPartMonth = $pdo->prepare("
    SELECT COUNT(DISTINCT user_id) AS cnt
    FROM public_opinions
    WHERE scope_type = 'town' AND scope_id = :sid
      AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$stmtPartMonth->execute([':sid' => $scopeId]);
$monthlyParticipants = (int) $stmtPartMonth->fetchColumn();

// Use a target of 10 monthly participants = 100% for small towns
$pulsePart = min(100, ($monthlyParticipants / 10) * 100);

$pulseScore = round(($pulseGroups * 0.4) + ($pulseDeclare * 0.3) + ($pulsePart * 0.3));

// Status badge colors
$statusColors = [
    'forming'       => '#4fc3f7',
    'active'        => '#27ae60',
    'crystallizing' => '#f39c12',
    'crystallized'  => '#d4af37',
];
?>

<div class="town-dashboard" id="<?= $dashPrefix ?>-dashboard">

    <!-- ================================================================ -->
    <!-- PULSE METER                                                       -->
    <!-- ================================================================ -->
    <div class="td-section td-pulse-section">
        <h3 class="td-heading">Civic Pulse</h3>
        <div class="td-pulse-meter">
            <div class="td-pulse-bar-track">
                <div class="td-pulse-bar-fill" style="width: <?= $pulseScore ?>%"></div>
            </div>
            <div class="td-pulse-score"><?= $pulseScore ?>%</div>
        </div>
        <div class="td-pulse-stats">
            <span class="td-pulse-stat">
                <strong><?= $activeGroups ?></strong> active group<?= $activeGroups !== 1 ? 's' : '' ?>
            </span>
            <span class="td-pulse-stat">
                <strong><?= $ratifiedDecl ?></strong> ratified declaration<?= $ratifiedDecl !== 1 ? 's' : '' ?>
            </span>
            <span class="td-pulse-stat">
                <strong><?= $monthlyParticipants ?></strong> participant<?= $monthlyParticipants !== 1 ? 's' : '' ?> this month
            </span>
        </div>
    </div>

    <!-- ================================================================ -->
    <!-- ACTIVE GROUPS GRID                                                -->
    <!-- ================================================================ -->
    <div class="td-section td-groups-section">
        <h3 class="td-heading">Active Groups</h3>

        <?php if (empty($townGroups)): ?>
            <div class="td-empty">
                <p>No civic groups yet for this town.</p>
                <p class="td-hint">Groups form around shared ideas and concerns.</p>
            </div>
        <?php else: ?>
            <div class="td-groups-grid">
                <?php foreach ($townGroups as $group): ?>
                    <div class="td-group-card" data-group-id="<?= (int) $group['id'] ?>">
                        <div class="td-group-header">
                            <h4 class="td-group-name">
                                <?= htmlspecialchars($group['name'], ENT_QUOTES, 'UTF-8') ?>
                            </h4>
                            <span class="td-status-badge"
                                  style="background: <?= $statusColors[$group['status']] ?? '#888' ?>22; color: <?= $statusColors[$group['status']] ?? '#888' ?>; border: 1px solid <?= $statusColors[$group['status']] ?? '#888' ?>44;">
                                <?= htmlspecialchars($group['status'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                        <?php if (!empty($group['description'])): ?>
                            <p class="td-group-desc">
                                <?= htmlspecialchars(mb_substr($group['description'], 0, 120), ENT_QUOTES, 'UTF-8') ?><?= mb_strlen($group['description']) > 120 ? '...' : '' ?>
                            </p>
                        <?php endif; ?>
                        <div class="td-group-meta">
                            <span class="td-group-members"><?= (int) $group['member_count'] ?> member<?= (int) $group['member_count'] !== 1 ? 's' : '' ?></span>
                            <span class="td-group-date">Since <?= date('M Y', strtotime($group['created_at'])) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ================================================================ -->
    <!-- ACTIVE DECLARATIONS                                               -->
    <!-- ================================================================ -->
    <div class="td-section td-declarations-section">
        <h3 class="td-heading">Declarations</h3>

        <?php if (empty($townDeclarations)): ?>
            <div class="td-empty">
                <p>No declarations yet for this town.</p>
                <p class="td-hint">Declarations are created by groups after reaching consensus.</p>
            </div>
        <?php else: ?>
            <div class="td-declarations-list">
                <?php foreach ($townDeclarations as $decl):
                    $dId = (int) $decl['declaration_id'];
                    $targetType = 'declaration';
                    $targetId = $dId;
                    $sentiment = $declSentiments[$dId] ?? ['agree' => 0, 'disagree' => 0, 'mixed' => 0, 'total' => 0];
                    $userOpinion = $userDeclOpinions[$dId] ?? null;
                    $opinionPrefix = $dashPrefix . '-decl-' . $dId;
                ?>
                    <div class="td-declaration-card <?= $decl['status'] === 'ratified' ? 'td-ratified' : '' ?>">
                        <div class="td-declaration-header">
                            <h4 class="td-declaration-title">
                                <?= htmlspecialchars($decl['title'], ENT_QUOTES, 'UTF-8') ?>
                            </h4>
                            <span class="td-declaration-status status-<?= htmlspecialchars($decl['status'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($decl['status'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                        <div class="td-declaration-body">
                            <?= nl2br(htmlspecialchars(mb_substr($decl['body'], 0, 300), ENT_QUOTES, 'UTF-8')) ?><?= mb_strlen($decl['body']) > 300 ? '...' : '' ?>
                        </div>
                        <div class="td-declaration-meta">
                            <span><?= (int) $decl['yes_count'] ?>/<?= (int) $decl['vote_count'] ?> votes</span>
                            <?php if ($decl['ratified_at']): ?>
                                <span>Ratified <?= date('M j, Y', strtotime($decl['ratified_at'])) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($decl['author_name'])): ?>
                                <span>by <?= htmlspecialchars($decl['author_name'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        </div>

                        <!-- Opinion Card -->
                        <?php require __DIR__ . '/opinion-card.php'; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div><!-- .town-dashboard -->
