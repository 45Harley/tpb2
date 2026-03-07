<?php
/**
 * Opinion Card Component — reusable opinion buttons + sentiment bar.
 *
 * REQUIRED VARIABLES (set by the including page before require):
 *   $targetType    — string: declaration|mandate|issue|bill|executive_order
 *   $targetId      — int: target record ID
 *   $sentiment     — array from Opinion::getSentiment() ['agree'=>N, 'disagree'=>N, 'mixed'=>N, 'total'=>N]
 *   $userOpinion   — array from Opinion::getUserOpinion() or null
 *   $opinionPrefix — string: unique CSS prefix to avoid DOM conflicts (e.g. 'decl-5')
 *
 * OPTIONAL (expected from outer scope):
 *   $dbUser        — user array from getUser() or false
 */

$opinionPrefix = $opinionPrefix ?? 'op';
$identityLevel = $dbUser ? (int) ($dbUser['identity_level_id'] ?? 1) : 0;
$canOpine = $dbUser && $identityLevel >= 2;
$currentStance = $userOpinion['stance'] ?? null;

// Calculate percentages for sentiment bar
$total = $sentiment['total'] ?? 0;
$agreeP    = $total > 0 ? round(($sentiment['agree']    / $total) * 100, 1) : 0;
$disagreeP = $total > 0 ? round(($sentiment['disagree'] / $total) * 100, 1) : 0;
$mixedP    = $total > 0 ? round(($sentiment['mixed']    / $total) * 100, 1) : 0;
?>

<div class="opinion-card" id="<?= $opinionPrefix ?>-card"
     data-target-type="<?= htmlspecialchars($targetType, ENT_QUOTES, 'UTF-8') ?>"
     data-target-id="<?= (int) $targetId ?>">

    <!-- Opinion Buttons -->
    <div class="opinion-buttons">
        <button class="opinion-btn opinion-agree <?= $currentStance === 'agree' ? 'active' : '' ?>"
                id="<?= $opinionPrefix ?>-agree"
                <?= $canOpine ? "onclick=\"opinionSubmit('$opinionPrefix', 'agree')\"" : 'disabled' ?>
                title="Agree">
            <span class="opinion-icon">&#x2713;</span>
            <span class="opinion-label">Agree</span>
            <span class="opinion-count" id="<?= $opinionPrefix ?>-agree-count"><?= (int) $sentiment['agree'] ?></span>
        </button>
        <button class="opinion-btn opinion-disagree <?= $currentStance === 'disagree' ? 'active' : '' ?>"
                id="<?= $opinionPrefix ?>-disagree"
                <?= $canOpine ? "onclick=\"opinionSubmit('$opinionPrefix', 'disagree')\"" : 'disabled' ?>
                title="Disagree">
            <span class="opinion-icon">&#x2717;</span>
            <span class="opinion-label">Disagree</span>
            <span class="opinion-count" id="<?= $opinionPrefix ?>-disagree-count"><?= (int) $sentiment['disagree'] ?></span>
        </button>
        <button class="opinion-btn opinion-mixed <?= $currentStance === 'mixed' ? 'active' : '' ?>"
                id="<?= $opinionPrefix ?>-mixed"
                <?= $canOpine ? "onclick=\"opinionSubmit('$opinionPrefix', 'mixed')\"" : 'disabled' ?>
                title="Mixed feelings">
            <span class="opinion-icon">&#x2014;</span>
            <span class="opinion-label">Mixed</span>
            <span class="opinion-count" id="<?= $opinionPrefix ?>-mixed-count"><?= (int) $sentiment['mixed'] ?></span>
        </button>
    </div>

    <!-- Sentiment Bar -->
    <?php if ($total > 0): ?>
    <div class="opinion-sentiment-bar" id="<?= $opinionPrefix ?>-bar">
        <?php if ($agreeP > 0): ?>
            <div class="sentiment-segment sentiment-agree" style="width: <?= $agreeP ?>%"
                 title="Agree: <?= $agreeP ?>%"></div>
        <?php endif; ?>
        <?php if ($disagreeP > 0): ?>
            <div class="sentiment-segment sentiment-disagree" style="width: <?= $disagreeP ?>%"
                 title="Disagree: <?= $disagreeP ?>%"></div>
        <?php endif; ?>
        <?php if ($mixedP > 0): ?>
            <div class="sentiment-segment sentiment-mixed" style="width: <?= $mixedP ?>%"
                 title="Mixed: <?= $mixedP ?>%"></div>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="opinion-sentiment-bar opinion-empty-bar" id="<?= $opinionPrefix ?>-bar">
        <div class="sentiment-segment sentiment-empty" style="width: 100%"></div>
    </div>
    <?php endif; ?>

    <div class="opinion-total" id="<?= $opinionPrefix ?>-total">
        <?= $total ?> opinion<?= $total !== 1 ? 's' : '' ?>
    </div>

    <!-- Comment toggle (for verified users) -->
    <?php if ($canOpine): ?>
    <div class="opinion-comment-section">
        <button class="opinion-comment-toggle" id="<?= $opinionPrefix ?>-comment-toggle"
                onclick="opinionToggleComment('<?= $opinionPrefix ?>')">
            <?= $userOpinion && !empty($userOpinion['comment']) ? 'Edit comment' : 'Add a comment' ?>
        </button>
        <div class="opinion-comment-form" id="<?= $opinionPrefix ?>-comment-form" style="display:none;">
            <textarea class="opinion-comment-input" id="<?= $opinionPrefix ?>-comment-input"
                      placeholder="Share your reasoning (optional)..."
                      rows="3" maxlength="2000"><?= htmlspecialchars($userOpinion['comment'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
            <div class="opinion-comment-actions">
                <button class="opinion-comment-save"
                        onclick="opinionSaveComment('<?= $opinionPrefix ?>')">Save Comment</button>
                <button class="opinion-comment-cancel"
                        onclick="opinionToggleComment('<?= $opinionPrefix ?>')">Cancel</button>
            </div>
        </div>
    </div>
    <?php elseif (!$dbUser): ?>
    <div class="opinion-login-hint">
        <a href="/login.php">Log in</a> to share your opinion.
    </div>
    <?php elseif ($identityLevel < 2): ?>
    <div class="opinion-login-hint">
        Verify your email to share your opinion.
    </div>
    <?php endif; ?>

</div>
