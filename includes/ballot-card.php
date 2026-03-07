<?php
/**
 * Ballot Card Component — reusable include for rendering a single ballot.
 *
 * REQUIRED VARIABLES (set by the including page before require):
 *   $ballot       — array from Ballot::get() (poll row with 'options' array)
 *   $tally        — array from Ballot::tally()
 *   $userVote     — array of user's vote rows (each has vote_choice, option_id, rank_position) or []
 *   $canVote      — bool (user logged in and meets identity level)
 *   $ballotPrefix — optional string for unique DOM IDs (default: 'b')
 */

// Defaults / guards
$ballotPrefix = $ballotPrefix ?? 'b';
$bid = htmlspecialchars($ballotPrefix, ENT_QUOTES, 'UTF-8');
$pollId = (int) $ballot['poll_id'];
$voteType = $ballot['vote_type'] ?? 'yes_no';
$question = htmlspecialchars($ballot['question'] ?? '', ENT_QUOTES, 'UTF-8');
$round = (int) ($ballot['round'] ?? 1);
$thresholdType = htmlspecialchars($ballot['threshold_type'] ?? 'majority', ENT_QUOTES, 'UTF-8');

// Derive current user selections
$currentChoice   = '';
$currentOptionId = null;
$userRankings    = []; // option_id => rank_position

if (!empty($userVote)) {
    foreach ($userVote as $uv) {
        if (!empty($uv['vote_choice'])) {
            $currentChoice = $uv['vote_choice'];
        }
        if (!empty($uv['option_id']) && $voteType === 'multi_choice') {
            $currentOptionId = (int) $uv['option_id'];
        }
        if (!empty($uv['option_id']) && isset($uv['rank_position'])) {
            $userRankings[(int) $uv['option_id']] = (int) $uv['rank_position'];
        }
    }
}
?>

<div class="ballot-card" id="<?= $bid ?>-card-<?= $pollId ?>" data-poll-id="<?= $pollId ?>" data-vote-type="<?= htmlspecialchars($voteType, ENT_QUOTES, 'UTF-8') ?>">

    <!-- Header -->
    <div class="ballot-header">
        <h3 class="ballot-question"><?= $question ?></h3>
        <div class="ballot-meta">
            <span class="ballot-badge type-badge"><?= htmlspecialchars(str_replace('_', ' ', $voteType), ENT_QUOTES, 'UTF-8') ?></span>
            <?php if ($round > 1): ?>
                <span class="ballot-badge round-badge">Round <?= $round ?></span>
            <?php endif; ?>
            <span class="ballot-badge threshold-badge"><?= htmlspecialchars(str_replace('_', ' ', $thresholdType), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </div>

    <!-- Vote UI -->
    <div class="ballot-body">

        <?php if ($voteType === 'yes_no' || $voteType === 'yes_no_novote'): ?>
            <!-- ========== YES / NO ========== -->
            <div class="ballot-yn-buttons">
                <button class="vote-btn yea <?= $currentChoice === 'yea' ? 'selected' : '' ?>"
                        onclick="ballotVote(<?= $pollId ?>, {vote_choice:'yea'})"
                        data-choice="yea"
                        <?= !$canVote ? 'disabled' : '' ?>>Yea</button>
                <button class="vote-btn nay <?= $currentChoice === 'nay' ? 'selected' : '' ?>"
                        onclick="ballotVote(<?= $pollId ?>, {vote_choice:'nay'})"
                        data-choice="nay"
                        <?= !$canVote ? 'disabled' : '' ?>>Nay</button>
                <button class="vote-btn abstain-btn <?= $currentChoice === 'abstain' ? 'selected' : '' ?>"
                        onclick="ballotVote(<?= $pollId ?>, {vote_choice:'abstain'})"
                        data-choice="abstain"
                        <?= !$canVote ? 'disabled' : '' ?>>Abstain</button>
                <?php if ($voteType === 'yes_no_novote'): ?>
                    <button class="vote-btn novote-btn <?= $currentChoice === 'novote' ? 'selected' : '' ?>"
                            onclick="ballotVote(<?= $pollId ?>, {vote_choice:'novote'})"
                            data-choice="novote"
                            <?= !$canVote ? 'disabled' : '' ?>>No Vote</button>
                <?php endif; ?>
            </div>

            <!-- Tally bar -->
            <?php
            $yea     = (int) ($tally['yea'] ?? 0);
            $nay     = (int) ($tally['nay'] ?? 0);
            $total   = (int) ($tally['total_votes'] ?? 0);
            $yeaPct  = $total > 0 ? round($yea / $total * 100, 1) : 0;
            $nayPct  = $total > 0 ? round($nay / $total * 100, 1) : 0;
            ?>
            <div class="ballot-tally-bar">
                <div class="tally-yea" style="width: <?= $yeaPct ?>%;" title="Yea <?= $yeaPct ?>%"></div>
                <div class="tally-nay" style="width: <?= $nayPct ?>%;" title="Nay <?= $nayPct ?>%"></div>
            </div>
            <div class="ballot-tally-labels">
                <span><?= $yeaPct ?>% Yea</span>
                <span><?= $nayPct ?>% Nay</span>
            </div>
            <div class="ballot-counts">
                <span><?= $yea ?> Yea</span>
                <span class="ballot-dot">&middot;</span>
                <span><?= $nay ?> Nay</span>
                <span class="ballot-dot">&middot;</span>
                <span><?= $total ?> total</span>
                <?php if (!empty($tally['threshold_met'])): ?>
                    <span class="ballot-badge threshold-met-badge">Threshold met</span>
                <?php endif; ?>
            </div>

        <?php elseif ($voteType === 'multi_choice'): ?>
            <!-- ========== MULTI CHOICE ========== -->
            <?php
            $tallyOptions  = $tally['options'] ?? [];
            $tallyCounts   = [];
            foreach ($tallyOptions as $to) {
                $tallyCounts[(int) $to['option_id']] = (int) $to['vote_count'];
            }
            ?>
            <div class="ballot-multi-options">
                <?php foreach ($ballot['options'] as $opt):
                    $optId = (int) $opt['option_id'];
                    $isSelected = ($currentOptionId === $optId);
                    $optCount = $tallyCounts[$optId] ?? 0;
                ?>
                    <button class="ballot-option <?= $isSelected ? 'selected' : '' ?>"
                            onclick="ballotVote(<?= $pollId ?>, {option_id:<?= $optId ?>})"
                            data-option-id="<?= $optId ?>"
                            <?= !$canVote ? 'disabled' : '' ?>>
                        <span class="option-text"><?= htmlspecialchars($opt['option_text'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="option-count-badge"><?= $optCount ?></span>
                    </button>
                <?php endforeach; ?>
            </div>

        <?php elseif ($voteType === 'ranked_choice'): ?>
            <!-- ========== RANKED CHOICE ========== -->
            <?php
            // Sort options by user's existing rankings, or by option_order
            $rankedOptions = $ballot['options'];
            if (!empty($userRankings)) {
                usort($rankedOptions, function ($a, $b) use ($userRankings) {
                    $ra = $userRankings[(int) $a['option_id']] ?? 999;
                    $rb = $userRankings[(int) $b['option_id']] ?? 999;
                    return $ra - $rb;
                });
            }
            ?>
            <div class="ballot-ranked-wrapper">
                <div class="ballot-ranked-list" id="<?= $bid ?>-ranked-list" data-poll-id="<?= $pollId ?>">
                    <?php $rank = 1; foreach ($rankedOptions as $opt):
                        $optId = (int) $opt['option_id'];
                    ?>
                        <div class="ranked-item" draggable="true" data-option-id="<?= $optId ?>">
                            <span class="rank-number"><?= $rank ?></span>
                            <span class="rank-text"><?= htmlspecialchars($opt['option_text'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="drag-handle">&#9776;</span>
                        </div>
                    <?php $rank++; endforeach; ?>
                </div>
                <button class="btn ballot-submit-ranked"
                        onclick="ballotSubmitRanked(<?= $pollId ?>, '<?= $bid ?>')"
                        <?= !$canVote ? 'disabled' : '' ?>>Submit Rankings</button>
            </div>

            <!-- RCV results -->
            <?php if (!empty($tally['rounds'])): ?>
                <div class="ballot-rcv-results">
                    <?php if (!empty($tally['winner'])): ?>
                        <div class="rcv-winner">
                            Winner: <strong><?= htmlspecialchars($tally['winner'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <?php if (!empty($tally['decided_in_round'])): ?>
                                <span class="rcv-round-info">(decided in round <?= (int) $tally['decided_in_round'] ?>)</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php foreach ($tally['rounds'] as $roundInfo): ?>
                        <div class="rcv-round">
                            <strong>Round <?= (int) $roundInfo['round'] ?></strong>
                            <ul>
                                <?php foreach ($roundInfo['results'] as $res): ?>
                                    <li>
                                        <?= htmlspecialchars($res['option_text'], ENT_QUOTES, 'UTF-8') ?>
                                        &mdash; <?= (int) $res['votes'] ?> vote<?= (int) $res['votes'] !== 1 ? 's' : '' ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>

        <!-- Login prompt for unauthenticated users -->
        <?php if (!$canVote): ?>
            <div class="ballot-login-prompt">
                <a href="/login.php">Log in to vote</a>
            </div>
        <?php endif; ?>

    </div><!-- .ballot-body -->
</div><!-- .ballot-card -->
