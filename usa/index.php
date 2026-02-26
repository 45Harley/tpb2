<?php
/**
 * USA Civic Map — Multi-mode interactive map of the United States.
 * Front door to the /usa/ civic data section.
 *
 * Modes:
 *   National  — delegation party balance, rep info on hover
 *   Election  — 2026 race activity (requires election_candidates table)
 *   Bills     — legislative activity / per-bill vote coloring
 *   Orders    — executive orders (requires executive_orders table)
 *   Courts    — federal circuit map (requires court_opinions table)
 *
 * Usage: /usa/   (this is the landing page)
 */
$c = require dirname(__DIR__) . '/config.php';
$pdo = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once dirname(__DIR__) . '/includes/get-user.php';
$dbUser = getUser($pdo);
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'usa';
$pageTitle = 'USA — The People\'s Branch';

// Secondary nav
$secondaryNavBrand = 'USA';
$secondaryNav = [
    ['label' => 'Map', 'url' => '/usa/'],
    ['label' => 'Congressional', 'url' => '/usa/digest.php'],
    ['label' => 'Executive', 'url' => '/usa/executive.php'],
    ['label' => 'Judicial', 'url' => '/usa/judicial.php'],
    ['label' => 'Documents', 'url' => '/usa/docs/'],
    ['label' => 'Glossary', 'url' => '/usa/glossary.php'],
];

$congress = 119;

// ── Load state delegation summary for map coloring + tooltip ──
$stateData = [];

// Party counts per state from rep_scorecard
$reps = $pdo->prepare("
    SELECT eo.party, eo.state_code, rs.chamber
    FROM rep_scorecard rs
    JOIN elected_officials eo ON rs.official_id = eo.official_id
    WHERE rs.congress = ?
");
$reps->execute([$congress]);

foreach ($reps->fetchAll(PDO::FETCH_ASSOC) as $rep) {
    $sc = $rep['state_code'];
    if (!isset($stateData[$sc])) {
        $stateData[$sc] = ['dem' => 0, 'rep' => 0, 'ind' => 0];
    }
    $p = substr($rep['party'], 0, 1);
    if ($p === 'D') $stateData[$sc]['dem']++;
    elseif ($p === 'R') $stateData[$sc]['rep']++;
    else $stateData[$sc]['ind']++;
}

// State names + compute totals
$stRows = $pdo->query("SELECT abbreviation, state_name FROM states")->fetchAll(PDO::FETCH_ASSOC);
$stateNames = [];
foreach ($stRows as $s) $stateNames[$s['abbreviation']] = $s['state_name'];

foreach ($stateData as $sc => &$sd) {
    $sd['total'] = $sd['dem'] + $sd['rep'] + $sd['ind'];
    $sd['name'] = $stateNames[$sc] ?? $sc;
}
unset($sd);

$stateDataJson = json_encode($stateData, JSON_UNESCAPED_UNICODE);

// Page styles (injected into header.php via $pageStyles)
$pageStyles = <<<'CSS'
/* ── Map page layout ── */
.map-page { max-width: 100%; margin: 0; padding: 0; }

/* USA title */
.usa-title {
    text-align: center;
    font-size: 22px;
    font-weight: 600;
    color: #f0f2f8;
    margin: 0;
    padding: 18px 0 0;
    background: #0a0e1a;
    cursor: pointer;
    transition: color 0.2s;
}
.usa-title:hover { color: #62a4d0; }

/* Map container */
.map-wrap {
    position: relative;
    background: #0a0e1a;
    padding: 20px 32px 0;
    min-height: 400px;
    overflow-x: scroll;
    overflow-y: hidden;
}
.map-wrap svg {
    min-width: 1200px;
    width: 100%;
    height: auto;
    display: block;
}
.map-wrap svg .state path,
.map-wrap svg .state circle {
    fill: #1a2035;
    stroke: #252d44;
    stroke-width: 0.5;
    cursor: pointer;
    transition: fill 0.3s, opacity 0.3s;
}
.map-wrap svg .state path:hover,
.map-wrap svg .state circle:hover {
    opacity: 0.85;
    stroke: #f0f2f8;
    stroke-width: 1.5;
}
.map-wrap svg .borders path {
    fill: none;
    stroke: #252d44;
    stroke-width: 0.8;
    pointer-events: none;
}

/* Small tooltip (follows cursor, immediate) */
.map-tooltip {
    position: absolute;
    background: #141929;
    border: 1px solid #4a9eff;
    color: #f0f2f8;
    padding: 8px 14px;
    border-radius: 6px;
    font-size: 13px;
    pointer-events: none;
    opacity: 0;
    transition: opacity 0.15s ease;
    z-index: 60;
    white-space: nowrap;
}
.map-tooltip.visible { opacity: 1; }
.map-tooltip .tt-name { font-weight: 700; color: #4a9eff; }
.map-tooltip .tt-sub { color: #a0a8c0; font-size: 12px; margin-top: 2px; }

/* Rich popup modal (appears after delay, stays put, draggable) */
.map-popup {
    display: none;
    position: absolute;
    background: #141929;
    border: 1px solid #4a9eff;
    border-radius: 12px;
    padding: 20px 24px;
    min-width: 340px;
    max-width: 460px;
    z-index: 50;
    pointer-events: auto;
    box-shadow: 0 8px 32px rgba(0,0,0,0.5);
    font-size: 15px;
    line-height: 1.7;
    color: #f0f2f8;
    cursor: grab;
}
.map-popup.dragging {
    cursor: grabbing;
    opacity: 0.92;
}
.map-popup.visible {
    display: block;
    animation: popupFadeIn 0.2s ease;
}
@keyframes popupFadeIn {
    from { opacity: 0; transform: translateY(4px); }
    to { opacity: 1; transform: translateY(0); }
}
.map-popup .popup-close {
    position: absolute;
    top: 10px;
    right: 12px;
    background: none;
    border: none;
    color: #6b7394;
    font-size: 22px;
    cursor: pointer;
    line-height: 1;
    padding: 0;
}
.map-popup .popup-close:hover { color: #4a9eff; }
.map-popup .popup-title {
    font-size: 20px;
    font-weight: 700;
    margin-bottom: 12px;
    padding-bottom: 10px;
    border-bottom: 1px solid #252d44;
    color: #4a9eff;
}
.map-popup .stat-row {
    display: flex;
    justify-content: space-between;
    padding: 6px 0;
    border-bottom: 1px solid #1a2035;
    font-size: 14px;
}
.map-popup .stat-row:last-of-type { border-bottom: none; }
.map-popup .stat-label { color: #8892a8; }
.map-popup .stat-value { color: #f0f2f8; font-weight: 500; text-align: right; }
.map-popup .pop-pct { color: #6b7394; font-size: 12px; margin-left: 4px; }
.map-popup .gov-party {
    font-size: 12px;
    font-weight: 600;
    padding: 1px 6px;
    border-radius: 3px;
    margin-left: 6px;
}
.map-popup .gov-party.dem { background: rgba(59,130,246,0.2); color: #3b82f6; }
.map-popup .gov-party.rep { background: rgba(239,68,68,0.2); color: #ef4444; }
.map-popup .voter-section {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid #252d44;
}
.map-popup .voter-section h4 {
    color: #8892a8;
    font-size: 12px;
    font-weight: 600;
    margin: 0 0 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.map-popup .voter-bar {
    height: 8px;
    border-radius: 4px;
    display: flex;
    overflow: hidden;
    background: #1a2035;
    margin-bottom: 6px;
}
.map-popup .voter-bar .dem { background: #3b82f6; }
.map-popup .voter-bar .rep { background: #ef4444; }
.map-popup .voter-bar .ind { background: #a78bfa; }
.map-popup .voter-legend {
    display: flex;
    gap: 12px;
    font-size: 12px;
    color: #a0a8c0;
}
.map-popup .voter-legend .dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-right: 4px;
    vertical-align: middle;
}
.map-popup .voter-legend .dot.dem { background: #3b82f6; }
.map-popup .voter-legend .dot.rep { background: #ef4444; }
.map-popup .voter-legend .dot.ind { background: #a78bfa; }
.map-popup .no-party-reg {
    margin-top: 10px;
    font-size: 12px;
    color: #6b7394;
    font-style: italic;
}
.map-popup .popup-link {
    display: inline-block;
    margin-top: 12px;
    font-size: 14px;
    font-weight: 600;
    color: #4a9eff;
    text-decoration: none;
}
.map-popup .popup-link:hover { text-decoration: underline; }
.map-popup .popup-loading {
    text-align: center;
    padding: 20px 0;
    color: #6b7394;
}

/* State code labels */
.map-wrap svg .state-label {
    font-size: 10px;
    font-weight: 700;
    fill: #f0f2f8;
    text-anchor: middle;
    dominant-baseline: central;
    pointer-events: none;
    text-shadow: 0 1px 2px rgba(0,0,0,0.8);
    letter-spacing: 0.5px;
}
.map-wrap svg .state-label.small-state {
    font-size: 9px;
}
.map-wrap svg .label-line {
    stroke: #8892a8;
    stroke-width: 0.7;
    pointer-events: none;
    opacity: 0.6;
}

/* Legend */
.map-legend {
    padding: 12px 32px 20px;
    background: #0a0e1a;
    display: flex;
    gap: 20px;
    align-items: center;
    font-size: 12px;
    color: #8892a8;
    flex-wrap: wrap;
}
.legend-item {
    display: flex;
    align-items: center;
    gap: 6px;
}
.legend-swatch {
    width: 14px;
    height: 14px;
    border-radius: 3px;
}

/* Footer */
.map-footer {
    padding: 16px 32px;
    font-size: 12px;
    color: #6b7394;
    text-align: center;
    border-top: 1px solid #252d44;
}
.map-footer a { color: #4a9eff; text-decoration: none; }
.map-footer strong { color: #f0b429; }

/* Responsive */
@media (max-width: 768px) {
    .mode-bar { padding: 10px 16px; gap: 12px; }
    .map-wrap { padding: 10px 8px 0; }
    .map-popup { min-width: 280px; max-width: 360px; font-size: 13px; padding: 16px 18px; }
    .map-legend { padding: 10px 16px; }
    .map-footer { padding: 12px 16px; }
}
CSS;

require_once dirname(__DIR__) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/nav.php';
?>

<div class="map-page">

<h2 class="usa-title" id="usaTitle">United States of America</h2>

<!-- Map -->
<div class="map-wrap" id="mapWrap">
    <div id="mapHolder">
        <p style="text-align:center;color:#6b7394;padding:100px 0;">Loading map...</p>
    </div>
    <div class="map-tooltip" id="mapTooltip">
        <div class="tt-name"></div>
        <div class="tt-sub"></div>
    </div>
    <div class="map-popup" id="mapPopup"></div>
</div>

<!-- Legend -->
<div class="map-legend" id="mapLegend">
    <span style="color:#6b7394">Delegation balance:</span>
    <div class="legend-item"><div class="legend-swatch" style="background:#2563eb"></div> All Democrat</div>
    <div class="legend-item"><div class="legend-swatch" style="background:#7c3aed"></div> Mixed</div>
    <div class="legend-item"><div class="legend-swatch" style="background:#dc2626"></div> All Republican</div>
    <div class="legend-item"><div class="legend-swatch" style="background:#1a2035"></div> No data</div>
</div>

<!-- Footer -->
<div class="map-footer">
    119th Congress &middot; <strong>The People's Branch</strong>
    &middot; <a href="/usa/digest.php">Congressional Digest</a>
    &middot; <a href="/usa/glossary.php">Glossary</a>
</div>

</div>

<script>
(function() {
    const stateData = <?= $stateDataJson ?>;
    const tooltip = document.getElementById('mapTooltip');
    const popup = document.getElementById('mapPopup');
    const mapWrap = document.getElementById('mapWrap');
    let currentMode = 'national';

    // Hover timer state (matches homepage pattern)
    let hoverTimer = null;
    let currentHoverState = null;
    const HOVER_DELAY = 1000;
    let lastMousePos = { x: 0, y: 0 };

    function clearHoverTimer() {
        if (hoverTimer) { clearTimeout(hoverTimer); hoverTimer = null; }
        currentHoverState = null;
    }

    // ── USA title hover → national stats popup, click → executive page ──
    var usaTitleEl = document.getElementById('usaTitle');
    var usaTitleTimer = null;

    usaTitleEl.addEventListener('mouseenter', function(e) {
        usaTitleTimer = setTimeout(function() {
            var rect = usaTitleEl.getBoundingClientRect();
            var x = rect.left + rect.width / 2;
            var y = rect.bottom + 8;
            showPopupModal('USA', x, y);
        }, HOVER_DELAY);
    });

    usaTitleEl.addEventListener('mouseleave', function() {
        if (usaTitleTimer) { clearTimeout(usaTitleTimer); usaTitleTimer = null; }
    });

    usaTitleEl.addEventListener('click', function() {
        if (usaTitleTimer) { clearTimeout(usaTitleTimer); usaTitleTimer = null; }
        window.location.href = '/usa/executive.php';
    });

    function hideTooltip() {
        tooltip.classList.remove('visible');
    }

    function hidePopup() {
        popup.classList.remove('visible');
    }

    // ── Load SVG map ──
    fetch('/usa-map.svg')
        .then(function(r) { return r.text(); })
        .then(function(svg) {
            document.getElementById('mapHolder').innerHTML = svg;
            var svgEl = document.querySelector('#mapHolder svg');
            var borders = svgEl ? svgEl.querySelector('.borders') : null;
            if (svgEl && borders) svgEl.appendChild(borders);
            colorMap();
            addStateLabels();
            attachStateEvents();
        })
        .catch(function() {
            document.getElementById('mapHolder').innerHTML = '<p style="text-align:center;color:#6b7394;">Map could not be loaded.</p>';
        });

    // ── Get state code from a path/circle element's class list ──
    function getStateCode(el) {
        var classes = el.className.baseVal ? el.className.baseVal.split(/\s+/) : [];
        for (var i = 0; i < classes.length; i++) {
            var c = classes[i].toUpperCase();
            if (c.length === 2 && stateData[c]) return c;
        }
        for (var i = 0; i < classes.length; i++) {
            if (classes[i].length === 2 && /^[a-z]{2}$/.test(classes[i])) return classes[i].toUpperCase();
        }
        return null;
    }

    // ── Color map based on current mode ──
    function colorMap() {
        var svgEl = document.querySelector('#mapHolder svg');
        if (!svgEl) return;

        svgEl.querySelectorAll('.state path, .state circle').forEach(function(el) {
            var sc = getStateCode(el);
            if (!sc) return;
            var sd = stateData[sc];

            if (currentMode === 'national') {
                if (!sd || sd.total === 0) {
                    el.style.fill = '#1a2035';
                    return;
                }
                var total = sd.total;
                var dPct = sd.dem / total;
                var rPct = sd.rep / total;

                if (dPct === 1) el.style.fill = '#2563eb';
                else if (rPct === 1) el.style.fill = '#dc2626';
                else {
                    var r = Math.round(37 + (220 - 37) * rPct);
                    var g = Math.round(99 - 60 * Math.abs(dPct - rPct));
                    var b = Math.round(235 - (235 - 38) * rPct);
                    el.style.fill = 'rgb(' + r + ',' + g + ',' + b + ')';
                }
            }
        });
    }

    // ── Add 2-letter state code labels to the map ──
    function addStateLabels() {
        var svgEl = document.querySelector('#mapHolder svg');
        if (!svgEl) return;
        var ns = 'http://www.w3.org/2000/svg';

        // Small NE states that need external labels with line pointers
        // offset: [labelDx, labelDy] relative to bbox center → label position outside the state
        var smallStates = {
            CT: { dx: 40, dy: -10 },
            DE: { dx: 38, dy: 5 },
            DC: { dx: 42, dy: 18 },
            MA: { dx: 40, dy: -12 },
            MD: { dx: 38, dy: 22 },
            NH: { dx: 36, dy: -18 },
            NJ: { dx: 36, dy: 8 },
            RI: { dx: 36, dy: 4 },
            VT: { dx: 32, dy: -12 }
        };

        // Manual centroid nudges for states where bbox center is off
        var nudge = {
            FL: { dx: 20, dy: 12 },
            LA: { dx: -10, dy: 5 },
            MI: { dx: 18, dy: 20 },
            AK: { dx: 0, dy: 5 },
            HI: { dx: 0, dy: 5 },
            CA: { dx: -5, dy: 10 },
            ID: { dx: 0, dy: 8 },
            OK: { dx: 10, dy: 0 },
            TX: { dx: 0, dy: 8 },
            NY: { dx: 8, dy: 5 },
            VA: { dx: 5, dy: -3 }
        };

        // Create a group for labels (render on top of everything)
        var labelGroup = document.createElementNS(ns, 'g');
        labelGroup.setAttribute('class', 'state-labels');

        // Collect all paths per state, find combined bbox
        var statePaths = {};
        svgEl.querySelectorAll('.state path, .state circle').forEach(function(el) {
            var sc = getStateCode(el);
            if (!sc) return;
            if (!statePaths[sc]) statePaths[sc] = [];
            statePaths[sc].push(el);
        });

        Object.keys(statePaths).forEach(function(sc) {
            var paths = statePaths[sc];
            // Combined bounding box across all paths for this state
            var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
            paths.forEach(function(p) {
                try {
                    var bb = p.getBBox();
                    if (bb.x < minX) minX = bb.x;
                    if (bb.y < minY) minY = bb.y;
                    if (bb.x + bb.width > maxX) maxX = bb.x + bb.width;
                    if (bb.y + bb.height > maxY) maxY = bb.y + bb.height;
                } catch(e) {}
            });
            if (minX === Infinity) return;

            var cx = (minX + maxX) / 2;
            var cy = (minY + maxY) / 2;

            // Apply manual nudges
            if (nudge[sc]) {
                cx += nudge[sc].dx;
                cy += nudge[sc].dy;
            }

            var isSmall = !!smallStates[sc];

            if (isSmall) {
                // External label with line pointer
                var off = smallStates[sc];
                var lx = cx + off.dx;
                var ly = cy + off.dy;

                // Line from state center to label
                var line = document.createElementNS(ns, 'line');
                line.setAttribute('x1', cx);
                line.setAttribute('y1', cy);
                line.setAttribute('x2', lx);
                line.setAttribute('y2', ly);
                line.setAttribute('class', 'label-line');
                labelGroup.appendChild(line);

                // Label text
                var txt = document.createElementNS(ns, 'text');
                txt.setAttribute('x', lx);
                txt.setAttribute('y', ly);
                txt.setAttribute('class', 'state-label small-state');
                txt.textContent = sc;
                labelGroup.appendChild(txt);
            } else {
                // Centered label inside state
                var txt = document.createElementNS(ns, 'text');
                txt.setAttribute('x', cx);
                txt.setAttribute('y', cy);
                txt.setAttribute('class', 'state-label');
                txt.textContent = sc;
                labelGroup.appendChild(txt);
            }
        });

        svgEl.appendChild(labelGroup);
    }

    // ── Build tooltip text for a state ──
    function getTooltipText(sc) {
        var sd = stateData[sc];
        if (!sd) return { name: sc, sub: 'No data' };
        var name = sd.name;
        var sub = sd.dem + 'D / ' + sd.rep + 'R' + (sd.ind > 0 ? ' / ' + sd.ind + 'I' : '') + ' &middot; ' + sd.total + ' members';
        return { name: name, sub: sub };
    }

    // ── State info cache (from API) ──
    var stateInfoCache = {};

    // ── Show the full popup modal at a fixed position ──
    function showPopupModal(sc, x, y) {
        var sd = stateData[sc];
        var stateName = sc === 'USA' ? 'United States of America' : (sd ? sd.name : sc);

        // Show loading state first
        popup.innerHTML = '<button class="popup-close">&times;</button>'
            + '<div class="popup-title">' + stateName + '</div>'
            + '<div class="popup-loading">Loading...</div>';
        positionPopup(x, y);
        popup.querySelector('.popup-close').addEventListener('click', function() { hidePopup(); });

        // Fetch or use cache
        if (stateInfoCache[sc]) {
            renderStatePopup(sc, stateInfoCache[sc], x, y);
        } else {
            fetch('/api/get-state-info.php?state=' + sc)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    stateInfoCache[sc] = data;
                    // Only render if popup is still open for this state
                    if (popup.classList.contains('visible')) {
                        renderStatePopup(sc, data, x, y);
                    }
                })
                .catch(function() {
                    popup.innerHTML = '<button class="popup-close">&times;</button>'
                        + '<div class="popup-title">' + stateName + '</div>'
                        + '<div class="popup-loading">Data unavailable</div>';
                    popup.querySelector('.popup-close').addEventListener('click', function() { hidePopup(); });
                });
        }
    }

    // ── Render state info popup (homepage-style content) ──
    function renderStatePopup(sc, data, x, y) {
        var sd = stateData[sc];
        var stateName = sc === 'USA' ? 'United States of America' : (data.state_name || (sd ? sd.name : sc));

        var html = '<button class="popup-close">&times;</button>';
        html += '<div class="popup-title">' + stateName + '</div>';

        // Population
        var pop = data.population;
        html += '<div class="stat-row"><span class="stat-label">Population</span>';
        html += '<span class="stat-value">' + (pop ? pop.toLocaleString() : '—');
        if (pop) {
            var pct = data.population_pct || ((pop / 331900000) * 100).toFixed(1);
            html += '<span class="pop-pct">(' + pct + '% of US)</span>';
        }
        html += '</span></div>';

        // Capital
        html += '<div class="stat-row"><span class="stat-label">Capital</span>';
        html += '<span class="stat-value">' + (data.capital_city || '—') + '</span></div>';

        // Largest city
        var largest = data.largest_city || '—';
        var lcPop = data.largest_city_population;
        html += '<div class="stat-row"><span class="stat-label">Largest City</span>';
        html += '<span class="stat-value">' + largest + (lcPop ? ' (' + lcPop.toLocaleString() + ')' : '') + '</span></div>';

        // Governor
        if (data.governor_name) {
            var gParty = data.governor_party || '';
            var gpClass = gParty === 'D' ? ' dem' : (gParty === 'R' ? ' rep' : '');
            html += '<div class="stat-row"><span class="stat-label">' + (sc === 'USA' ? 'President' : 'Governor') + '</span>';
            html += '<span class="stat-value">' + data.governor_name;
            if (gParty) html += '<span class="gov-party' + gpClass + '">(' + gParty + ')</span>';
            html += '</span></div>';
        }

        // Delegation (from stateData)
        if (sd && sd.total > 0) {
            html += '<div class="stat-row"><span class="stat-label">Delegation</span>';
            html += '<span class="stat-value">' + sd.dem + 'D / ' + sd.rep + 'R';
            if (sd.ind > 0) html += ' / ' + sd.ind + 'I';
            html += ' &middot; ' + sd.total + ' members</span></div>';
        }

        // Voter registration bar
        var dem = data.voters_democrat, rep = data.voters_republican, ind = data.voters_independent;
        if (dem || rep || ind) {
            var vTotal = (dem || 0) + (rep || 0) + (ind || 0);
            if (vTotal > 0) {
                var dPct = ((dem || 0) / vTotal * 100).toFixed(1);
                var rPct = ((rep || 0) / vTotal * 100).toFixed(1);
                var iPct = ((ind || 0) / vTotal * 100).toFixed(1);
                html += '<div class="voter-section">';
                html += '<h4>Registered Voters</h4>';
                html += '<div class="voter-bar">';
                html += '<div class="dem" style="width:' + dPct + '%"></div>';
                html += '<div class="rep" style="width:' + rPct + '%"></div>';
                html += '<div class="ind" style="width:' + iPct + '%"></div>';
                html += '</div>';
                html += '<div class="voter-legend">';
                html += '<span><span class="dot dem"></span>' + dPct + '% Dem</span>';
                html += '<span><span class="dot rep"></span>' + rPct + '% Rep</span>';
                html += '<span><span class="dot ind"></span>' + iPct + '% Ind</span>';
                html += '</div></div>';
            }
        } else {
            html += '<div class="no-party-reg">This state doesn\'t register voters by party</div>';
        }

        // View delegation link
        html += '<a class="popup-link" href="/usa/digest.php?state=' + sc + '">View Delegation &rarr;</a>';

        popup.innerHTML = html;
        positionPopup(x, y);
        popup.querySelector('.popup-close').addEventListener('click', function() { hidePopup(); });
    }

    // ── Position popup near a point, keep on screen ──
    function positionPopup(x, y) {
        var rect = mapWrap.getBoundingClientRect();
        var px = x + 20;
        var py = y - 50;

        popup.style.left = '-9999px';
        popup.style.top = '-9999px';
        popup.classList.add('visible');

        var pw = popup.offsetWidth;
        var ph = popup.offsetHeight;
        if (px + pw > rect.width - 20) px = x - pw - 20;
        if (py + ph > rect.height - 20) py = y - ph - 20;
        if (px < 10) px = 10;
        if (py < 10) py = 10;

        popup.style.left = px + 'px';
        popup.style.top = py + 'px';
    }

    // ── Attach hover/click to each state path ──
    function attachStateEvents() {
        var svgEl = document.querySelector('#mapHolder svg');
        if (!svgEl) return;

        svgEl.querySelectorAll('.state path, .state circle').forEach(function(el) {
            var sc = getStateCode(el);
            if (!sc) return;

            // Mouseenter: show small tooltip + start timer for full popup
            el.addEventListener('mouseenter', function(e) {
                var tt = getTooltipText(sc);
                tooltip.querySelector('.tt-name').innerHTML = tt.name;
                tooltip.querySelector('.tt-sub').innerHTML = tt.sub;
                tooltip.classList.add('visible');

                // Start timer for rich popup
                clearHoverTimer();
                currentHoverState = sc;
                var rect = mapWrap.getBoundingClientRect();
                lastMousePos = { x: e.clientX - rect.left, y: e.clientY - rect.top };

                hoverTimer = setTimeout(function() {
                    if (currentHoverState === sc) {
                        hideTooltip();
                        showPopupModal(sc, lastMousePos.x, lastMousePos.y);
                    }
                }, HOVER_DELAY);
            });

            // Mousemove: track tooltip position + update last pos for timer
            el.addEventListener('mousemove', function(e) {
                var rect = mapWrap.getBoundingClientRect();
                tooltip.style.left = (e.clientX - rect.left + 15) + 'px';
                tooltip.style.top = (e.clientY - rect.top - 10) + 'px';
                lastMousePos = { x: e.clientX - rect.left, y: e.clientY - rect.top };
            });

            // Mouseleave: hide tooltip + clear timer (popup stays if open)
            el.addEventListener('mouseleave', function() {
                hideTooltip();
                clearHoverTimer();
            });

            // Click: go to full delegation page
            el.addEventListener('click', function() {
                window.location.href = '/usa/digest.php?state=' + sc;
            });
        });

        // Click outside popup to close
        document.addEventListener('click', function(e) {
            if (popup.classList.contains('visible') && !popup.contains(e.target)) {
                var target = e.target;
                if (target.closest && target.closest('.state')) return;
                hidePopup();
            }
        });
    }

    // ── Popup drag — let users reposition when it covers content ──
    (function() {
        var dragging = false, startX, startY, origLeft, origTop;

        popup.addEventListener('mousedown', function(e) {
            if (e.target.closest('button, a, input')) return;
            dragging = true;
            startX = e.clientX;
            startY = e.clientY;
            origLeft = parseInt(popup.style.left) || 0;
            origTop = parseInt(popup.style.top) || 0;
            popup.classList.add('dragging');
            e.preventDefault();
        });

        document.addEventListener('mousemove', function(e) {
            if (!dragging) return;
            popup.style.left = (origLeft + e.clientX - startX) + 'px';
            popup.style.top = (origTop + e.clientY - startY) + 'px';
        });

        document.addEventListener('mouseup', function() {
            if (dragging) {
                dragging = false;
                popup.classList.remove('dragging');
            }
        });

        // Touch support
        popup.addEventListener('touchstart', function(e) {
            if (e.target.closest('button, a, input')) return;
            dragging = true;
            var t = e.touches[0];
            startX = t.clientX;
            startY = t.clientY;
            origLeft = parseInt(popup.style.left) || 0;
            origTop = parseInt(popup.style.top) || 0;
            popup.classList.add('dragging');
        }, {passive: true});

        document.addEventListener('touchmove', function(e) {
            if (!dragging) return;
            var t = e.touches[0];
            popup.style.left = (origLeft + t.clientX - startX) + 'px';
            popup.style.top = (origTop + t.clientY - startY) + 'px';
        }, {passive: true});

        document.addEventListener('touchend', function() {
            if (dragging) {
                dragging = false;
                popup.classList.remove('dragging');
            }
        });
    })();

})();
</script>

</body>
</html>
