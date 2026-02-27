<?php
/**
 * Criminality Scale Explainer — Shared Include
 * =============================================
 * Self-contained: emits its own CSS (once) + HTML block.
 * Visual color bar + dot legend (Gemini design), then explainer text.
 *
 * Usage:
 *   <?php require_once __DIR__ . '/../includes/criminality-scale.php'; ?>
 */

$_csZones = [
    ['label' => 'Clean', 'range' => '0', 'color' => '#4caf50', 'min' => 0],
    ['label' => 'Questionable', 'range' => '1-10', 'color' => '#8bc34a', 'min' => 1],
    ['label' => 'Misconduct', 'range' => '11-30', 'color' => '#cddc39', 'min' => 11],
    ['label' => 'Misdemeanor', 'range' => '31-70', 'color' => '#ffeb3b', 'min' => 31],
    ['label' => 'Felony', 'range' => '71-150', 'color' => '#fbc02d', 'min' => 71],
    ['label' => 'Serious Felony', 'range' => '151-300', 'color' => '#f9a825', 'min' => 151],
    ['label' => 'High Crime', 'range' => '301-500', 'color' => '#f57f17', 'min' => 301],
    ['label' => 'Atrocity', 'range' => '501-700', 'color' => '#ef5350', 'min' => 501],
    ['label' => 'Crime Against Humanity', 'range' => '701-900', 'color' => '#c62828', 'min' => 701],
    ['label' => 'Genocide', 'range' => '901-1000', 'color' => '#b71c1c', 'min' => 901]
];

if (!defined('CRIMINALITY_SCALE_CSS_EMITTED')) {
    define('CRIMINALITY_SCALE_CSS_EMITTED', true);
?>
<style>
.civic-scale-container { background: #0a0a0f; padding: 1.5rem; border-radius: 8px; border: 1px solid #333; margin-bottom: 0; border-bottom: none; border-radius: 8px 8px 0 0; }
.civic-scale-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.civic-scale-header h3 { color: #d4af37; margin: 0; font-size: 1.1rem; text-transform: uppercase; letter-spacing: 1px; }
.threshold-marker { color: #ef5350; font-weight: bold; font-size: 0.8rem; text-transform: uppercase; border: 1px solid #ef5350; padding: 2px 6px; border-radius: 4px; }
.scale-bar { display: flex; height: 12px; border-radius: 6px; overflow: hidden; margin-bottom: 1rem; background: #222; }
.scale-segment { height: 100%; transition: transform 0.3s; cursor: help; }
.scale-labels { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; }
.label-item { font-size: 0.7rem; color: #888; display: flex; align-items: center; gap: 5px; }
.cs-dot { width: 8px; height: 8px; border-radius: 50%; }
.criminality-scale-box {
    background: #1a1a2e; border: 1px solid #333; border-top: none; border-radius: 0 0 8px 8px;
    padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; color: #ccc;
    font-size: 0.9rem; line-height: 1.6;
}
.criminality-scale-box p { margin: 0 0 0.5rem; }
.criminality-scale-box ul { margin: 0 0 0.75rem 1.25rem; padding: 0; }
.criminality-scale-box li { margin-bottom: 0.25rem; }
.criminality-scale-box a { color: #d4af37; }
@media (max-width: 600px) { .scale-labels { grid-template-columns: repeat(2, 1fr); } }
</style>
<?php } ?>

<div class="civic-scale-container" id="criminality-scale">
    <div class="civic-scale-header">
        <h3>Criminality Scale</h3>
        <span class="threshold-marker">Constitutional Threshold: 31+</span>
    </div>
    <div class="scale-bar">
        <?php foreach ($_csZones as $z):
            $width = ($z['label'] == 'Clean') ? 5 : 10.5;
        ?>
            <div class="scale-segment"
                 style="width: <?= $width ?>%; background: <?= $z['color'] ?>;"
                 title="<?= $z['label'] ?> (<?= $z['range'] ?>)"></div>
        <?php endforeach; ?>
    </div>
    <div class="scale-labels">
        <?php foreach ($_csZones as $z): ?>
            <div class="label-item">
                <span class="cs-dot" style="background: <?= $z['color'] ?>"></span>
                <span><strong><?= $z['range'] ?></strong> <?= $z['label'] ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<div class="criminality-scale-box">
    <p>Every executive, legislative, and judicial action scoring <strong>300 or higher</strong> on the <strong style="color:#d4af37">criminality scale</strong> becomes a poll question here. These are documented threats to constitutional order &mdash; court orders defied, civil rights violated, public institutions dismantled. Note how low the bar is for impeachment: the Constitution requires only &ldquo;High Crimes and Misdemeanors&rdquo; &mdash; that&rsquo;s a <strong>31</strong> on this scale.</p>
    <p><strong>Two audiences, two questions:</strong></p>
    <ul>
        <li><strong>Citizens:</strong> &ldquo;Is this acceptable?&rdquo; &mdash; your moral judgment on the act</li>
        <li><strong>Congress Members:</strong> &ldquo;Will you act on this?&rdquo; &mdash; their commitment, on the record</li>
    </ul>
    <p>Each threat lists one primary executive official, but most acts involve additional conspirators &mdash; aides, appointees, or agencies that carried out or enabled the action. The score rates the act itself, not any single person.</p>
    <p>After you vote, see how the country responded in <a href="/poll/national/">National</a>, how your state compares in <a href="/poll/by-state/">By State</a>, and whether your representatives are listening in <a href="/poll/by-rep/">By Rep</a>.</p>
</div>
