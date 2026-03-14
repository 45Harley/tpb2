<?php
/**
 * Benefit Scale — Benefit Zone Helper
 * Mirror of criminality scale (severity.php), reversed color palette.
 *
 * Scale: 0-1000 geometric. Rates the ACT's positive impact on citizens.
 * Returns ['label' => string, 'color' => hex, 'class' => css-class]
 */
function getBenefitZone($score) {
    if ($score === null) return ['label' => 'Unscored', 'color' => '#9e9e9e', 'class' => 'unscored'];
    if ($score === 0) return ['label' => 'Neutral', 'color' => '#9e9e9e', 'class' => 'neutral'];
    if ($score <= 10) return ['label' => 'Minor Positive', 'color' => '#c8e6c9', 'class' => 'minor-positive'];
    if ($score <= 30) return ['label' => 'Helpful', 'color' => '#a5d6a7', 'class' => 'helpful'];
    if ($score <= 70) return ['label' => 'Significant', 'color' => '#81c784', 'class' => 'significant'];
    if ($score <= 150) return ['label' => 'Major Benefit', 'color' => '#66bb6a', 'class' => 'major-benefit'];
    if ($score <= 300) return ['label' => 'Transformative', 'color' => '#4caf50', 'class' => 'transformative'];
    if ($score <= 500) return ['label' => 'Historic', 'color' => '#43a047', 'class' => 'historic'];
    if ($score <= 700) return ['label' => 'Landmark', 'color' => '#388e3c', 'class' => 'landmark'];
    if ($score <= 900) return ['label' => 'Epochal', 'color' => '#2e7d32', 'class' => 'epochal'];
    return ['label' => 'Civilizational', 'color' => '#1b5e20', 'class' => 'civilizational'];
}
