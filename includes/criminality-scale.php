<?php
/**
 * Criminality Scale Explainer — Shared Include
 * =============================================
 * Self-contained: emits its own CSS (once) + HTML block.
 * Includes intro text, two-audience framing, conspirators note,
 * nav links, and the 0–1000 scale legend.
 *
 * Usage:
 *   <?php require_once __DIR__ . '/../includes/criminality-scale.php'; ?>
 */

if (!defined('CRIMINALITY_SCALE_CSS_EMITTED')) {
    define('CRIMINALITY_SCALE_CSS_EMITTED', true);
?>
<style>
.criminality-scale-box {
    background: #1a1a2e; border: 1px solid #333; border-radius: 8px;
    padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; color: #ccc;
    font-size: 0.9rem; line-height: 1.6;
}
.criminality-scale-box p { margin: 0 0 0.5rem; }
.criminality-scale-box ul { margin: 0 0 0.75rem 1.25rem; padding: 0; }
.criminality-scale-box li { margin-bottom: 0.25rem; }
.criminality-scale-box a { color: #d4af37; }
.scale-legend { margin-top: 0.5rem; }
.scale-row { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-bottom: 0.3rem; }
.scale-chip {
    display: inline-block; padding: 0.15rem 0.5rem; border-radius: 4px;
    font-size: 0.7rem; font-weight: 700; color: #fff;
}
.scale-muted {
    display: inline-block; padding: 0.15rem 0.5rem; border-radius: 4px;
    font-size: 0.7rem; font-weight: 600;
}
</style>
<?php } ?>
<div class="criminality-scale-box" id="criminality-scale">
    <p>Every executive, legislative, and judicial action scoring <strong>300 or higher</strong> on the <strong style="color:#d4af37">criminality scale</strong> becomes a poll question here. These are documented threats to constitutional order &mdash; court orders defied, civil rights violated, public institutions dismantled. Note how low the bar is for impeachment: the Constitution requires only &ldquo;High Crimes and Misdemeanors&rdquo; &mdash; that&rsquo;s a <strong>31</strong> on this scale.</p>
    <p><strong>Two audiences, two questions:</strong></p>
    <ul>
        <li><strong>Citizens:</strong> &ldquo;Is this acceptable?&rdquo; &mdash; your moral judgment on the act</li>
        <li><strong>Congress Members:</strong> &ldquo;Will you act on this?&rdquo; &mdash; their commitment, on the record</li>
    </ul>
    <p>Each threat lists one primary executive official, but most acts involve additional conspirators &mdash; aides, appointees, or agencies that carried out or enabled the action. The score rates the act itself, not any single person.</p>
    <p>After you vote, see how the country responded in <a href="/poll/national/">National</a>, how your state compares in <a href="/poll/by-state/">By State</a>, and whether your representatives are listening in <a href="/poll/by-rep/">By Rep</a>.</p>
    <div class="scale-legend">
        <div style="font-size:0.8rem; font-weight:700; color:#d4af37; margin-bottom:0.3rem;">Criminality Scale &nbsp;1 &ndash; 1,000</div>
        <div class="scale-row">
            <span class="scale-muted" style="color:#4caf50; border:1px solid #4caf50">0 Clean</span>
            <span class="scale-muted" style="color:#8bc34a; border:1px solid #8bc34a">1&ndash;10 Questionable</span>
            <span class="scale-muted" style="color:#cddc39; border:1px solid #cddc39">11&ndash;30 Misconduct</span>
            <span class="scale-muted" style="color:#ffeb3b; border:1px solid #ffeb3b">31&ndash;70 Misdemeanor</span>
            <span class="scale-muted" style="color:#ff9800; border:1px solid #ff9800">71&ndash;150 Felony</span>
            <span class="scale-muted" style="color:#ff5722; border:1px solid #ff5722">151&ndash;300 Serious Felony</span>
        </div>
        <div class="scale-row">
            <span class="scale-chip" style="background:#f44336">301&ndash;500 High Crime</span>
            <span class="scale-chip" style="background:#d32f2f">501&ndash;700 Atrocity</span>
            <span class="scale-chip" style="background:#b71c1c">701&ndash;900 Crime Against Humanity</span>
            <span class="scale-chip" style="background:#4a0000; border:1px solid #888">901&ndash;1000 Genocide</span>
        </div>
    </div>
</div>
