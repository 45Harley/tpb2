<?php
/**
 * TPB Ballots Page — Scoped Voting Instruments
 * =============================================
 * Shows ballots (polls created via the Ballot class) filtered by scope:
 * federal, state, town, group.
 *
 * URL params:
 *   ?scope=federal|state|town|group  (default: federal)
 *   ?scope_id=N                      (required for state/town/group)
 */

$config = require __DIR__ . '/../config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

require_once __DIR__ . '/../includes/get-user.php';
require_once __DIR__ . '/../includes/ballot.php';

$dbUser = getUser($pdo);

// --- Scope from URL ---
$validScopes = ['federal', 'state', 'town', 'group'];
$scopeType   = $_GET['scope'] ?? 'federal';
if (!in_array($scopeType, $validScopes, true)) {
    $scopeType = 'federal';
}
$scopeId = isset($_GET['scope_id']) ? (int) $_GET['scope_id'] : null;

// Map scope_type to the DB value used by Ballot (federal = national in DB)
$dbScopeType = ($scopeType === 'federal') ? 'national' : $scopeType;

// --- Fetch ballots ---
$ballots = Ballot::listByScope($pdo, $dbScopeType, $scopeId);

// --- Auth / can-vote check ---
$canVote = $dbUser && (($dbUser['identity_level_id'] ?? 0) >= 2);

// --- User location for scope tabs ---
$userStateId   = $dbUser['current_state_id'] ?? null;
$userTownId    = $dbUser['current_town_id'] ?? null;
$userStateName = $dbUser['state_name'] ?? ($dbUser['current_state_name'] ?? null);
$userTownName  = $dbUser['town_name'] ?? ($dbUser['current_town_name'] ?? null);

// --- Page setup ---
$currentPage = 'ballots';
$pageTitle   = 'Ballots | The People\'s Branch';

$headLinks = '<link rel="stylesheet" href="/assets/ballot.css">';

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/nav.php';
?>

<div style="max-width: 800px; margin: 0 auto; padding: 30px 20px;">

    <!-- Page header -->
    <h1 style="color: #d4af37; margin-bottom: 8px;">Ballots</h1>
    <p style="color: #b0b0b0; margin-bottom: 24px;">Vote on civic issues. Your voice counts.</p>

    <!-- Scope tabs -->
    <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 28px;">
        <?php
        $tabs = [];
        $tabs[] = ['label' => 'National', 'scope' => 'federal', 'scope_id' => null];
        if ($userStateId) {
            $stateLabel = $userStateName ? "My State ($userStateName)" : 'My State';
            $tabs[] = ['label' => $stateLabel, 'scope' => 'state', 'scope_id' => $userStateId];
        }
        if ($userTownId) {
            $townLabel = $userTownName ? "My Town ($userTownName)" : 'My Town';
            $tabs[] = ['label' => $townLabel, 'scope' => 'town', 'scope_id' => $userTownId];
        }

        foreach ($tabs as $tab):
            $isActive = ($scopeType === $tab['scope'])
                && (($tab['scope_id'] === null && $scopeId === null) || $tab['scope_id'] == $scopeId);
            $href = '?scope=' . urlencode($tab['scope']);
            if ($tab['scope_id'] !== null) {
                $href .= '&scope_id=' . (int) $tab['scope_id'];
            }
            $bgColor    = $isActive ? '#d4af37' : '#1a1a2e';
            $textColor  = $isActive ? '#000'    : '#b0b0b0';
            $border     = $isActive ? '1px solid #d4af37' : '1px solid #333';
        ?>
            <a href="<?= htmlspecialchars($href) ?>"
               style="display: inline-block; padding: 8px 18px; border-radius: 20px;
                      background: <?= $bgColor ?>; color: <?= $textColor ?>; border: <?= $border ?>;
                      text-decoration: none; font-size: 0.95rem; font-weight: 500;
                      transition: all 0.2s;"><?= htmlspecialchars($tab['label']) ?></a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($ballots)): ?>
        <!-- Empty state -->
        <div style="text-align: center; padding: 60px 20px; color: #888;">
            <div style="font-size: 2.5rem; margin-bottom: 12px; opacity: 0.5;">&#9745;</div>
            <p style="font-size: 1.1rem; margin-bottom: 16px; color: #b0b0b0;">No ballots yet for this scope.</p>
            <p style="color: #888;">Check out the <a href="/poll/" style="color: #d4af37; text-decoration: none;">Threat Polls</a> in the meantime.</p>
        </div>
    <?php else: ?>
        <!-- Ballot list -->
        <?php foreach ($ballots as $i => $summaryBallot):
            $pollId = (int) $summaryBallot['poll_id'];

            // Full ballot data with options
            $ballot = Ballot::get($pdo, $pollId);
            if (!$ballot) continue;

            // Tally
            $tally = Ballot::tally($pdo, $pollId);

            // User's votes for this ballot
            $userVote = [];
            if ($dbUser) {
                $voteStmt = $pdo->prepare(
                    "SELECT vote_choice, option_id, rank_position
                     FROM poll_votes
                     WHERE poll_id = :pid AND user_id = :uid"
                );
                $voteStmt->execute([':pid' => $pollId, ':uid' => (int) $dbUser['user_id']]);
                $userVote = $voteStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Unique prefix for DOM IDs
            $ballotPrefix = 'b' . $i;

            require __DIR__ . '/../includes/ballot-card.php';
        endforeach; ?>
    <?php endif; ?>

</div>

<script src="/assets/ballot.js"></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
