<?php
/**
 * Benefit Scale Explainer — Shared Include
 * =========================================
 * Mirror of criminality-scale.php with green color palette.
 * Self-contained: emits its own CSS (once) + HTML block.
 *
 * Usage:
 *   <?php require_once __DIR__ . '/../includes/benefit-scale.php'; ?>
 */

require_once __DIR__ . '/benefit-severity.php';

$_bsZones = [
    ['label' => 'Neutral', 'range' => '0', 'color' => '#9e9e9e', 'min' => 0],
    ['label' => 'Minor Positive', 'range' => '1-10', 'color' => '#c8e6c9', 'min' => 1],
    ['label' => 'Helpful', 'range' => '11-30', 'color' => '#a5d6a7', 'min' => 11],
    ['label' => 'Significant', 'range' => '31-70', 'color' => '#81c784', 'min' => 31],
    ['label' => 'Major Benefit', 'range' => '71-150', 'color' => '#66bb6a', 'min' => 71],
    ['label' => 'Transformative', 'range' => '151-300', 'color' => '#4caf50', 'min' => 151],
    ['label' => 'Historic', 'range' => '301-500', 'color' => '#43a047', 'min' => 301],
    ['label' => 'Landmark', 'range' => '501-700', 'color' => '#388e3c', 'min' => 501],
    ['label' => 'Epochal', 'range' => '701-900', 'color' => '#2e7d32', 'min' => 701],
    ['label' => 'Civilizational', 'range' => '901-1000', 'color' => '#1b5e20', 'min' => 901]
];

if (!defined('BENEFIT_SCALE_CSS_EMITTED')) {
    define('BENEFIT_SCALE_CSS_EMITTED', true);
?>
<style>
.benefit-scale-container { background: #0a0f0a; padding: 1.5rem; border-radius: 8px; border: 1px solid #2e4a2e; margin-bottom: 0; border-bottom: none; border-radius: 8px 8px 0 0; }
.benefit-scale-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.benefit-scale-header h3 { color: #4caf50; margin: 0; font-size: 1.1rem; text-transform: uppercase; letter-spacing: 1px; }
.benefit-threshold-marker { color: #4caf50; font-weight: bold; font-size: 0.8rem; text-transform: uppercase; border: 1px solid #4caf50; padding: 2px 6px; border-radius: 4px; }
.benefit-bar { display: flex; height: 12px; border-radius: 6px; overflow: hidden; margin-bottom: 1rem; background: #222; }
.benefit-segment { height: 100%; transition: transform 0.3s; cursor: help; }
.benefit-labels { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; }
.benefit-label-item { font-size: 0.7rem; color: #888; display: flex; align-items: center; gap: 5px; }
.bs-dot { width: 8px; height: 8px; border-radius: 50%; }
.benefit-scale-box {
    background: #0f1a0f; border: 1px solid #2e4a2e; border-top: none; border-radius: 0 0 8px 8px;
    padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; color: #ccc;
    font-size: 0.9rem; line-height: 1.6;
}
.benefit-scale-box p { margin: 0 0 0.5rem; }
@media (max-width: 600px) { .benefit-labels { grid-template-columns: repeat(2, 1fr); } }
</style>
<?php } ?>

<div class="benefit-scale-container" id="benefit-scale">
    <div class="benefit-scale-header">
        <h3>Benefit Scale</h3>
        <span class="benefit-threshold-marker">Citizen Impact: 0-1000</span>
    </div>
    <div class="benefit-bar">
        <?php foreach ($_bsZones as $z):
            $width = ($z['label'] == 'Neutral') ? 5 : 10.5;
        ?>
            <div class="benefit-segment"
                 style="width: <?= $width ?>%; background: <?= $z['color'] ?>;"
                 title="<?= $z['label'] ?> (<?= $z['range'] ?>)"></div>
        <?php endforeach; ?>
    </div>
    <div class="benefit-labels">
        <?php foreach ($_bsZones as $z): ?>
            <div class="benefit-label-item">
                <span class="bs-dot" style="background: <?= $z['color'] ?>"></span>
                <span><strong><?= $z['range'] ?></strong> <?= $z['label'] ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<div class="benefit-scale-box">
    <p>The <strong style="color:#4caf50">benefit scale</strong> measures positive citizen impact &mdash; how much an action or statement helps people. It mirrors the criminality scale: same 0-1000 geometric range, same zone boundaries, opposite direction. An action can score on both scales independently.</p>
</div>
