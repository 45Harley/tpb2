<?php
/**
 * Executive Branch Overview — Photo card grid
 * Shows Trump + 26 direct reports with threat counts.
 * Click a card → executive.php#official-{id}
 */
$c = require dirname(__DIR__) . '/config.php';
$pdo = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once dirname(__DIR__) . '/includes/get-user.php';
$dbUser = getUser($pdo);
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'usa';
$pageTitle = 'Executive Branch Overview — The People\'s Branch';

$secondaryNavBrand = 'USA';
$secondaryNav = [
    ['label' => 'Map', 'url' => '/usa/'],
    ['label' => 'Congressional', 'url' => '/usa/digest.php'],
    ['label' => 'Executive', 'url' => '/usa/executive-overview.php'],
    ['label' => 'Judicial', 'url' => '/usa/judicial.php'],
    ['label' => 'Documents', 'url' => '/usa/docs/'],
    ['label' => 'Glossary', 'url' => '/usa/glossary.php'],
];

// President
$president = $pdo->query("
    SELECT * FROM elected_officials WHERE title = 'President' AND is_current = 1 LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
$presId = $president ? $president['official_id'] : 326;

// Cabinet + VP (reports_to president)
$stmt = $pdo->prepare("
    SELECT * FROM elected_officials
    WHERE is_current = 1 AND reports_to = ?
    ORDER BY full_name
");
$stmt->execute([$presId]);
$cabinet = $stmt->fetchAll(PDO::FETCH_ASSOC);

// All official IDs
$allIds = [$presId];
foreach ($cabinet as $m) $allIds[] = $m['official_id'];

// Threat counts per official
$placeholders = implode(',', array_fill(0, count($allIds), '?'));
$stmt = $pdo->prepare("
    SELECT official_id, COUNT(*) as cnt
    FROM executive_threats
    WHERE official_id IN ($placeholders)
    GROUP BY official_id
");
$stmt->execute($allIds);
$threatCounts = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $threatCounts[(int)$row['official_id']] = (int)$row['cnt'];
}

// Sort cabinet by threat count desc
usort($cabinet, function($a, $b) use ($threatCounts) {
    $ca = $threatCounts[$a['official_id']] ?? 0;
    $cb = $threatCounts[$b['official_id']] ?? 0;
    return $cb - $ca;
});

// Find max threat count for border intensity scaling
$maxThreats = 1;
foreach ($allIds as $id) {
    $ct = $threatCounts[$id] ?? 0;
    if ($ct > $maxThreats) $maxThreats = $ct;
}

$pageStyles = <<<'CSS'
.exec-overview {
    max-width: 1200px;
    margin: 0 auto;
    padding: 30px 20px;
}
.exec-overview h2 {
    text-align: center;
    color: #d4af37;
    font-size: 1.6em;
    margin-bottom: 30px;
}
.cards-grid {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 20px;
}
.official-card {
    width: 150px;
    text-align: center;
    cursor: pointer;
    padding: 15px 10px;
    border-radius: 8px;
    background: #1a1a1a;
    border: 2px solid #2a2a2a;
    transition: transform 0.2s, border-color 0.2s, box-shadow 0.2s;
    text-decoration: none;
    display: block;
}
.official-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 6px 20px rgba(0,0,0,0.5);
}
.official-card.has-threats {
    border-color: var(--threat-color);
    box-shadow: 0 0 8px var(--threat-glow);
}
.official-card.has-threats:hover {
    box-shadow: 0 0 16px var(--threat-glow), 0 6px 20px rgba(0,0,0,0.5);
}
.official-card.boss-card {
    width: 170px;
    border-color: #d4af37;
    box-shadow: 0 0 12px rgba(212,175,55,0.3);
}
.official-card.boss-card:hover {
    box-shadow: 0 0 20px rgba(212,175,55,0.5), 0 6px 20px rgba(0,0,0,0.5);
}
.card-photo {
    width: 120px;
    height: 120px;
    object-fit: cover;
    border-radius: 6px;
    margin: 0 auto 10px;
    display: block;
    background: #2a2a2a;
}
.card-photo-placeholder {
    width: 120px;
    height: 120px;
    border-radius: 6px;
    margin: 0 auto 10px;
    background: #2a2a2a;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #555;
    font-size: 2em;
}
.no-threats-card .card-photo {
    opacity: 0.6;
}
.card-name {
    color: #d4af37;
    font-weight: 700;
    font-size: 0.9em;
    margin-bottom: 4px;
    line-height: 1.2;
}
.card-title {
    color: #888;
    font-size: 0.75em;
    line-height: 1.3;
    margin-bottom: 8px;
    min-height: 2.6em;
}
.card-threats {
    color: #dc2626;
    font-weight: 700;
    font-size: 0.85em;
}
.card-threats.zero {
    color: #555;
    font-weight: 400;
    font-style: italic;
}
.boss-card .card-name {
    color: #ffdb58;
    font-size: 1em;
}

/* Responsive */
@media (max-width: 768px) {
    .official-card { width: calc(33.33% - 14px); min-width: 120px; }
    .official-card.boss-card { width: calc(33.33% - 14px); min-width: 120px; }
    .card-photo, .card-photo-placeholder { width: 100px; height: 100px; }
}
@media (max-width: 480px) {
    .official-card { width: calc(50% - 12px); }
    .official-card.boss-card { width: calc(50% - 12px); }
}
CSS;

require_once dirname(__DIR__) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/nav.php';

function renderCard($official, $threatCount, $maxThreats, $isBoss = false) {
    $oid = $official['official_id'];
    $name = htmlspecialchars($official['full_name']);
    $title = htmlspecialchars($official['title']);
    $photo = $official['photo_url'] ?? '';

    // Threat border color: scale red intensity with count
    $intensity = $maxThreats > 0 ? $threatCount / $maxThreats : 0;
    $r = 220;
    $g = (int)(38 + (1 - $intensity) * 40);
    $b = (int)(38 + (1 - $intensity) * 40);
    $alpha = 0.3 + $intensity * 0.5;
    $threatColor = "rgb($r,$g,$b)";
    $threatGlow = "rgba($r,$g,$b,$alpha)";

    $classes = 'official-card';
    if ($isBoss) $classes .= ' boss-card';
    if ($threatCount > 0) $classes .= ' has-threats';
    else $classes .= ' no-threats-card';

    $style = $threatCount > 0 ? " style=\"--threat-color:$threatColor;--threat-glow:$threatGlow\"" : '';

    echo "<a class=\"$classes\" href=\"/usa/executive.php#official-$oid\"$style>";

    if ($photo) {
        echo "<img class=\"card-photo\" src=\"" . htmlspecialchars($photo) . "\" alt=\"$name\" loading=\"lazy\">";
    } else {
        echo "<div class=\"card-photo-placeholder\">👤</div>";
    }

    echo "<div class=\"card-name\">$name</div>";
    echo "<div class=\"card-title\">$title</div>";

    if ($threatCount > 0) {
        echo "<div class=\"card-threats\">⚠ $threatCount threat" . ($threatCount !== 1 ? 's' : '') . "</div>";
    } else {
        echo "<div class=\"card-threats zero\">No active threats</div>";
    }

    echo "</a>\n";
}
?>

<div class="exec-overview">
    <h2>Executive Branch</h2>

    <div class="cards-grid">
        <?php
        // Trump first
        if ($president) {
            renderCard($president, $threatCounts[$presId] ?? 0, $maxThreats, true);
        }

        // Then 26 sorted by threat count desc
        foreach ($cabinet as $official) {
            renderCard($official, $threatCounts[$official['official_id']] ?? 0, $maxThreats);
        }
        ?>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
