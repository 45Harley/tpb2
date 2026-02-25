<?php
/**
 * Criminality Scale — Severity Zone Helper
 * Shared across executive.php and poll/ pages.
 *
 * Scale: 0-1000 geometric. Rates the ACT, not the actor.
 * Returns ['label' => string, 'color' => hex, 'class' => css-class]
 */
function getSeverityZone($score) {
    if ($score === null) return ['label' => 'Unscored', 'color' => '#555', 'class' => 'unscored'];
    if ($score === 0) return ['label' => 'Clean', 'color' => '#4caf50', 'class' => 'clean'];
    if ($score <= 10) return ['label' => 'Questionable', 'color' => '#8bc34a', 'class' => 'questionable'];
    if ($score <= 30) return ['label' => 'Misconduct', 'color' => '#cddc39', 'class' => 'misconduct'];
    if ($score <= 70) return ['label' => 'Misdemeanor', 'color' => '#ffeb3b', 'class' => 'misdemeanor'];
    if ($score <= 150) return ['label' => 'Felony', 'color' => '#ff9800', 'class' => 'felony'];
    if ($score <= 300) return ['label' => 'Serious Felony', 'color' => '#ff5722', 'class' => 'serious-felony'];
    if ($score <= 500) return ['label' => 'High Crime', 'color' => '#f44336', 'class' => 'high-crime'];
    if ($score <= 700) return ['label' => 'Atrocity', 'color' => '#d32f2f', 'class' => 'atrocity'];
    if ($score <= 900) return ['label' => 'Crime Against Humanity', 'color' => '#b71c1c', 'class' => 'crime-humanity'];
    return ['label' => 'Genocide', 'color' => '#000', 'class' => 'genocide'];
}
