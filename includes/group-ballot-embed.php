<?php
/**
 * Group Ballot Card Component — embeddable include for group pages.
 *
 * Renders the active ballot for a group using ballot-card.php,
 * plus facilitator tools panel when the user is a facilitator.
 *
 * REQUIRED VARIABLES (set by the including page before require):
 *   $pdo        — PDO connection
 *   $dbUser     — user array from getUser() or false
 *   $groupId    — int, the idea_groups.id
 *
 * OPTIONAL:
 *   $ballotPrefix — string for unique DOM IDs (default: 'grp')
 */

require_once __DIR__ . '/ballot.php';
require_once __DIR__ . '/facilitator.php';

$ballotPrefix = $ballotPrefix ?? 'grp';
$userId = $dbUser ? (int) $dbUser['user_id'] : 0;
$isFacilitator = $dbUser ? Facilitator::isFacilitator($pdo, $groupId, $userId) : false;

// Load active ballot
$activeBallot = Facilitator::getActiveBallot($pdo, $groupId);

// Load declarations
$declarations = Facilitator::listDeclarations($pdo, $groupId);

// Load all ballots for facilitator panel
$allBallots = $isFacilitator ? Facilitator::getGroupBallots($pdo, $groupId) : [];
?>

<div class="group-ballot-embed" id="group-ballot-<?= $groupId ?>" data-group-id="<?= $groupId ?>">

    <!-- ================================================================ -->
    <!-- Active Ballot                                                     -->
    <!-- ================================================================ -->
    <div class="gbe-section">
        <h3 class="gbe-heading">Active Ballot</h3>

        <?php if ($activeBallot): ?>
            <?php
            $ballot = $activeBallot;
            $tally = Ballot::tally($pdo, (int) $ballot['poll_id']);
            $userVote = [];
            if ($dbUser) {
                $stmt = $pdo->prepare(
                    "SELECT vote_choice, option_id, rank_position
                     FROM poll_votes
                     WHERE poll_id = :pid AND user_id = :uid"
                );
                $stmt->execute([':pid' => $ballot['poll_id'], ':uid' => $userId]);
                $userVote = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            $canVote = (bool) $dbUser;
            require __DIR__ . '/ballot-card.php';
            ?>
        <?php else: ?>
            <div class="gbe-empty">
                <p>No active ballot for this group.</p>
                <?php if ($isFacilitator): ?>
                    <p class="gbe-hint">Use the Facilitator Tools below to call a vote.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ================================================================ -->
    <!-- Declarations                                                      -->
    <!-- ================================================================ -->
    <?php if (!empty($declarations)): ?>
    <div class="gbe-section">
        <h3 class="gbe-heading">Declarations</h3>
        <div class="gbe-declarations-list">
            <?php foreach ($declarations as $decl): ?>
                <div class="declaration-card <?= $decl['status'] === 'ratified' ? 'ratified' : '' ?>"
                     data-declaration-id="<?= (int) $decl['declaration_id'] ?>">
                    <div class="declaration-header">
                        <h4 class="declaration-title">
                            <?= htmlspecialchars($decl['title'], ENT_QUOTES, 'UTF-8') ?>
                        </h4>
                        <span class="declaration-status-badge status-<?= htmlspecialchars($decl['status'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($decl['status'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>
                    <div class="declaration-body">
                        <?= nl2br(htmlspecialchars($decl['body'], ENT_QUOTES, 'UTF-8')) ?>
                    </div>
                    <div class="declaration-meta">
                        <span class="declaration-votes">
                            <?= (int) $decl['yes_count'] ?>/<?= (int) $decl['vote_count'] ?> votes
                        </span>
                        <?php if ($decl['threshold_met']): ?>
                            <span class="declaration-threshold">
                                <?= htmlspecialchars(str_replace('_', ' ', $decl['threshold_met']), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($decl['ratified_at']): ?>
                            <span class="declaration-date">
                                Ratified <?= date('M j, Y', strtotime($decl['ratified_at'])) ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($decl['author_name'])): ?>
                            <span class="declaration-author">
                                by <?= htmlspecialchars($decl['author_name'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php if ($isFacilitator && $decl['status'] === 'draft'): ?>
                        <div class="declaration-actions">
                            <button class="btn-facilitator btn-ratify"
                                    onclick="facilitatorRatify(<?= (int) $decl['declaration_id'] ?>)">
                                Ratify Declaration
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ================================================================ -->
    <!-- Facilitator Tools (visible only to facilitators)                   -->
    <!-- ================================================================ -->
    <?php if ($isFacilitator): ?>
    <div class="gbe-section facilitator-panel" id="facilitator-panel-<?= $groupId ?>">
        <h3 class="gbe-heading facilitator-heading">Facilitator Tools</h3>

        <!-- Call a Vote -->
        <div class="facilitator-tool">
            <h4 class="facilitator-tool-title">Call a Vote</h4>
            <form id="call-vote-form-<?= $groupId ?>" onsubmit="return facilitatorCallVote(event, <?= $groupId ?>)">
                <div class="fac-field">
                    <label for="fac-question-<?= $groupId ?>">Question</label>
                    <input type="text" id="fac-question-<?= $groupId ?>" name="question"
                           placeholder="What should we decide?" required>
                </div>
                <div class="fac-field">
                    <label for="fac-vote-type-<?= $groupId ?>">Vote Type</label>
                    <select id="fac-vote-type-<?= $groupId ?>" name="vote_type">
                        <option value="yes_no">Yes / No</option>
                        <option value="yes_no_novote">Yes / No / No Vote</option>
                        <option value="multi_choice">Multi Choice</option>
                        <option value="ranked_choice">Ranked Choice</option>
                    </select>
                </div>
                <div class="fac-field">
                    <label for="fac-threshold-<?= $groupId ?>">Threshold</label>
                    <select id="fac-threshold-<?= $groupId ?>" name="threshold_type">
                        <option value="plurality">Plurality</option>
                        <option value="majority" selected>Majority</option>
                        <option value="three_fifths">Three-Fifths</option>
                        <option value="two_thirds">Two-Thirds</option>
                        <option value="three_quarters">Three-Quarters</option>
                        <option value="unanimous">Unanimous</option>
                    </select>
                </div>
                <div class="fac-field fac-options-field" id="fac-options-wrap-<?= $groupId ?>" style="display:none;">
                    <label>Options</label>
                    <div id="fac-options-list-<?= $groupId ?>">
                        <input type="text" name="options[]" placeholder="Option 1" class="fac-option-input">
                        <input type="text" name="options[]" placeholder="Option 2" class="fac-option-input">
                    </div>
                    <button type="button" class="btn-facilitator btn-small"
                            onclick="facilitatorAddOption(<?= $groupId ?>)">+ Add Option</button>
                </div>
                <button type="submit" class="btn-facilitator btn-call-vote">Call Vote</button>
            </form>
        </div>

        <!-- Ballot History -->
        <?php if (!empty($allBallots)): ?>
        <div class="facilitator-tool">
            <h4 class="facilitator-tool-title">Ballot History</h4>
            <div class="fac-ballot-history">
                <?php foreach ($allBallots as $b): ?>
                    <div class="fac-ballot-row" data-poll-id="<?= (int) $b['poll_id'] ?>">
                        <span class="fac-ballot-question">
                            <?= htmlspecialchars(mb_substr($b['question'] ?? '', 0, 60), ENT_QUOTES, 'UTF-8') ?>
                            <?= mb_strlen($b['question'] ?? '') > 60 ? '...' : '' ?>
                        </span>
                        <span class="fac-ballot-round">R<?= (int) ($b['round'] ?? 1) ?></span>
                        <span class="fac-ballot-votes"><?= (int) ($b['total_votes'] ?? 0) ?> votes</span>
                        <span class="fac-ballot-status <?= $b['active'] ? 'active' : 'closed' ?>">
                            <?= $b['active'] ? 'Active' : 'Closed' ?>
                        </span>
                        <?php if ($b['active']): ?>
                            <button class="btn-facilitator btn-small"
                                    onclick="facilitatorNewRound(<?= (int) $b['poll_id'] ?>)">New Round</button>
                            <button class="btn-facilitator btn-small"
                                    onclick="facilitatorShowDraftForm(<?= (int) $b['poll_id'] ?>, <?= $groupId ?>)">Draft Declaration</button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Draft Declaration Form (hidden, shown via JS) -->
        <div class="facilitator-tool" id="draft-declaration-form-wrap-<?= $groupId ?>" style="display:none;">
            <h4 class="facilitator-tool-title">Draft Declaration</h4>
            <form id="draft-declaration-form-<?= $groupId ?>"
                  onsubmit="return facilitatorDraftDeclaration(event, <?= $groupId ?>)">
                <input type="hidden" id="draft-poll-id-<?= $groupId ?>" name="poll_id" value="">
                <div class="fac-field">
                    <label for="draft-title-<?= $groupId ?>">Title</label>
                    <input type="text" id="draft-title-<?= $groupId ?>" name="title"
                           placeholder="Declaration title" required>
                </div>
                <div class="fac-field">
                    <label for="draft-body-<?= $groupId ?>">Body</label>
                    <textarea id="draft-body-<?= $groupId ?>" name="body" rows="5"
                              placeholder="Declaration text..." required></textarea>
                </div>
                <button type="submit" class="btn-facilitator btn-call-vote">Create Draft</button>
                <button type="button" class="btn-facilitator btn-small"
                        onclick="document.getElementById('draft-declaration-form-wrap-<?= $groupId ?>').style.display='none'">Cancel</button>
            </form>
        </div>

    </div><!-- .facilitator-panel -->
    <?php endif; ?>

</div><!-- .group-ballot-embed -->
