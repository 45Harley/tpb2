<?php
/**
 * Public Mandate Summary
 * ======================
 * Displays aggregated mandate statistics for a geographic scope.
 *
 * Routes:
 *   /mandate-summary.php?scope=federal&value=CT-2
 *   /mandate-summary.php?scope=state&value=7
 *   /mandate-summary.php?scope=town&value=42
 */

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/get-user.php';
require_once __DIR__ . '/config/mandate-topics.php';

$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$dbUser    = getUser($pdo);
$isLoggedIn = (bool)$dbUser;
$navVars = getNavVarsForUser($dbUser);
extract($navVars);

$scope      = $_GET['scope'] ?? 'federal';
$scopeValue = trim($_GET['value'] ?? '');

// Validate scope
if (!in_array($scope, ['federal', 'state', 'town'], true)) {
    $scope = 'federal';
}

// Resolve display name for the scope
$scopeDisplay = $scopeValue;
if ($scope === 'state' && ctype_digit($scopeValue)) {
    $s = $pdo->prepare("SELECT state_name FROM states WHERE state_id = ?");
    $s->execute([(int)$scopeValue]);
    $row = $s->fetch();
    if ($row) $scopeDisplay = $row['state_name'];
} elseif ($scope === 'town' && ctype_digit($scopeValue)) {
    $s = $pdo->prepare("SELECT town_name FROM towns WHERE town_id = ?");
    $s->execute([(int)$scopeValue]);
    $row = $s->fetch();
    if ($row) $scopeDisplay = $row['town_name'];
}

$scopeLabel = ucfirst($scope);
$pageTitle  = "The People's Pulse: {$scopeDisplay} | The People's Branch";
$currentPage = 'mandate';

$headLinks = '';

$pageStyles = <<<'CSS'

/* ── Summary page layout ───────────────────────────────── */
.summary-wrap {
    max-width: 900px;
    margin: 0 auto;
    padding: 2rem 1rem;
}
.summary-header {
    text-align: center;
    margin-bottom: 2rem;
}
.summary-header h1 {
    color: #d4af37;
    font-size: 1.6rem;
    margin-bottom: 0.25rem;
}
.summary-header .scope-label {
    color: #b0b0b0;
    font-size: 0.95rem;
}

/* ── Scoreboard ────────────────────────────────────────── */
.scoreboard {
    display: flex;
    gap: 1rem;
    justify-content: center;
    flex-wrap: wrap;
    margin-bottom: 2rem;
}
.score-box {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    border: 1px solid rgba(212,175,55,0.25);
    border-radius: 12px;
    padding: 1.25rem 1.5rem;
    text-align: center;
    min-width: 160px;
    flex: 1;
    max-width: 220px;
}
.score-box .number {
    font-size: 2rem;
    font-weight: 700;
    color: #fff;
    display: block;
}
.score-box .label {
    font-size: 0.85rem;
    color: #b0b0b0;
    margin-top: 0.25rem;
}
.score-box .trend {
    font-size: 0.8rem;
    margin-top: 0.25rem;
}
.trend-up { color: #81c784; }
.trend-down { color: #e57373; }
.trend-flat { color: #b0b0b0; }

/* ── Topic breakdown ───────────────────────────────────── */
.topics-section {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    border: 1px solid rgba(212,175,55,0.25);
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 2rem;
}
.topics-section h2 {
    color: #d4af37;
    font-size: 1.1rem;
    margin: 0 0 1rem 0;
}
.topic-row {
    margin-bottom: 1rem;
}
.topic-label {
    display: flex;
    justify-content: space-between;
    margin-bottom: 4px;
}
.topic-name { color: #ccc; font-size: 0.9rem; }
.topic-stats { color: #b0b0b0; font-size: 0.85rem; }
.topic-bar-bg {
    background: rgba(255,255,255,0.08);
    border-radius: 4px;
    height: 22px;
    overflow: hidden;
}
.topic-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #d4af37 0%, #f0d060 100%);
    border-radius: 4px;
    transition: width 0.3s ease;
}
.citizen-voices {
    margin-top: 4px;
    padding-left: 0.5rem;
    font-size: 0.82rem;
    color: #999;
    font-style: italic;
}

/* ── Mandate list ──────────────────────────────────────── */
.mandates-section {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    border: 1px solid rgba(212,175,55,0.25);
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 2rem;
}
.mandates-section h2 {
    color: #d4af37;
    font-size: 1.1rem;
    margin: 0 0 1rem 0;
}
.mandate-item {
    border-bottom: 1px solid rgba(255,255,255,0.06);
    padding: 0.75rem 0;
}
.mandate-item:last-child { border-bottom: none; }
.mandate-topic-badge {
    display: inline-block;
    background: rgba(212,175,55,0.15);
    color: #d4af37;
    font-size: 0.7rem;
    padding: 2px 8px;
    border-radius: 10px;
    margin-right: 6px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.mandate-content { color: #ccc; font-size: 0.9rem; line-height: 1.4; }
.mandate-meta { color: #888; font-size: 0.8rem; margin-top: 4px; }

/* ── Period tabs ───────────────────────────────────────── */
.period-tabs {
    display: flex;
    gap: 0.5rem;
    justify-content: center;
    margin-bottom: 1.5rem;
}
.period-tab {
    background: rgba(255,255,255,0.06);
    border: 1px solid rgba(212,175,55,0.15);
    color: #b0b0b0;
    padding: 6px 16px;
    border-radius: 20px;
    cursor: pointer;
    font-size: 0.85rem;
    transition: all 0.2s;
}
.period-tab:hover { border-color: rgba(212,175,55,0.4); color: #fff; }
.period-tab.active { background: rgba(212,175,55,0.15); color: #d4af37; border-color: #d4af37; }

/* ── Export ─────────────────────────────────────────────── */
.export-bar {
    text-align: center;
    margin-bottom: 2rem;
}
.export-btn {
    background: rgba(212,175,55,0.12);
    border: 1px solid rgba(212,175,55,0.3);
    color: #d4af37;
    padding: 8px 20px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 0.9rem;
    text-decoration: none;
    display: inline-block;
}
.export-btn:hover { background: rgba(212,175,55,0.2); }

/* ── Empty state ───────────────────────────────────────── */
.empty-state {
    text-align: center;
    padding: 3rem 1rem;
    color: #b0b0b0;
}
.empty-state p { margin: 0.5rem 0; }

/* ── Responsive ────────────────────────────────────────── */
@media (max-width: 600px) {
    .scoreboard { flex-direction: column; align-items: center; }
    .score-box { max-width: 100%; width: 100%; }
}

CSS;

require __DIR__ . '/includes/header.php';
?>

<div class="summary-wrap">

    <div class="summary-header">
        <h1>The People's Pulse</h1>
        <p class="scope-label" id="scopeLabel"><?= htmlspecialchars($scopeLabel) ?> &mdash; <?= htmlspecialchars($scopeDisplay) ?></p>
    </div>

    <!-- Geo Scope Tabs -->
    <div class="period-tabs" id="geoTabs">
        <button class="period-tab<?= $scope === 'federal' ? ' active' : '' ?>" data-scope="federal" title="U.S. Congress &mdash; your district">Federal</button>
        <button class="period-tab<?= $scope === 'state' ? ' active' : '' ?>" data-scope="state" title="State legislature">State</button>
        <button class="period-tab<?= $scope === 'town' ? ' active' : '' ?>" data-scope="town" title="Local town government">Town</button>
    </div>

    <!-- Period Tabs -->
    <div class="period-tabs">
        <button class="period-tab active" data-period="all">All Time</button>
        <button class="period-tab" data-period="month">Last 30 Days</button>
        <button class="period-tab" data-period="week">This Week</button>
    </div>

    <!-- Scoreboard (JS-filled) -->
    <div class="scoreboard" id="scoreboard"></div>

    <!-- Topic Breakdown (JS-filled) -->
    <div class="topics-section" id="topicsSection" style="display:none;">
        <h2>What Constituents Care About</h2>
        <div id="topicsBody"></div>
    </div>

    <!-- Mandates List (JS-filled) -->
    <div class="mandates-section" id="mandatesSection" style="display:none;">
        <h2>Recent Mandates</h2>
        <div id="mandatesBody"></div>
    </div>

    <!-- Export -->
    <div class="export-bar">
        <a class="export-btn" id="csvExport" href="#">Download CSV</a>
    </div>

    <!-- Empty state -->
    <div class="empty-state" id="emptyState" style="display:none;">
        <p>No mandates yet for this area.</p>
        <p>Be the first to <a href="/talk/" style="color:#d4af37;">draft your mandate</a>.</p>
    </div>

</div>

<script>
(function() {
    var scope      = <?= json_encode($scope) ?>;
    var scopeValue = <?= json_encode($scopeValue) ?>;
    var currentPeriod = 'all';

    // User geo data for scope switching
    var userGeo = {
        federal: <?= json_encode($dbUser ? ($dbUser['us_congress_district'] ?? '') : '') ?>,
        state:   <?= json_encode($dbUser ? ($dbUser['current_state_id'] ?? '') : '') ?>,
        town:    <?= json_encode($dbUser ? ($dbUser['current_town_id'] ?? '') : '') ?>
    };
    var userGeoLabels = {
        federal: <?= json_encode($dbUser ? ($dbUser['us_congress_district'] ?? 'All') : 'All') ?>,
        state:   <?= json_encode($dbUser ? ($dbUser['state_name'] ?? 'All') : 'All') ?>,
        town:    <?= json_encode($dbUser ? ($dbUser['town_name'] ?? 'All') : 'All') ?>
    };

    function escHtml(s) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s || ''));
        return d.innerHTML;
    }

    function load(period) {
        currentPeriod = period;
        var url = '/api/mandate-summary.php?scope=' + encodeURIComponent(scope)
                + '&scope_value=' + encodeURIComponent(scopeValue)
                + '&period=' + encodeURIComponent(period);

        fetch(url).then(function(r) { return r.json(); }).then(render).catch(function() {
            document.getElementById('emptyState').style.display = 'block';
        });
    }

    function render(data) {
        if (!data.success || data.mandate_count === 0) {
            document.getElementById('scoreboard').innerHTML = '';
            document.getElementById('topicsSection').style.display = 'none';
            document.getElementById('mandatesSection').style.display = 'none';
            document.getElementById('emptyState').style.display = 'block';
            return;
        }
        document.getElementById('emptyState').style.display = 'none';

        // Scoreboard
        var act = data.recent_activity || {};
        var trendClass = act.trend === 'up' ? 'trend-up' : (act.trend === 'down' ? 'trend-down' : 'trend-flat');
        var trendArrow = act.trend === 'up' ? '&#9650;' : (act.trend === 'down' ? '&#9660;' : '&#8212;');
        document.getElementById('scoreboard').innerHTML =
            '<div class="score-box"><span class="number">' + data.mandate_count + '</span><span class="label">Total Mandates</span></div>'
          + '<div class="score-box"><span class="number">' + data.contributor_count + '</span><span class="label">Constituents</span></div>'
          + '<div class="score-box"><span class="number">' + act.this_week + '</span><span class="label">This Week</span>'
          + '<span class="trend ' + trendClass + '">' + trendArrow + ' vs last week (' + act.last_week + ')</span></div>';

        // Topics
        if (data.topics && data.topics.length) {
            document.getElementById('topicsSection').style.display = 'block';
            var h = '';
            data.topics.forEach(function(t) {
                h += '<div class="topic-row">'
                   + '<div class="topic-label"><span class="topic-name">' + escHtml(t.policy_topic) + '</span>'
                   + '<span class="topic-stats">' + t.count + ' (' + t.pct + '%)</span></div>'
                   + '<div class="topic-bar-bg"><div class="topic-bar-fill" style="width:' + t.pct + '%"></div></div>';
                if (t.citizen_voices && t.citizen_voices.length) {
                    h += '<div class="citizen-voices">"' + t.citizen_voices.map(escHtml).join('", "') + '"</div>';
                }
                h += '</div>';
            });
            document.getElementById('topicsBody').innerHTML = h;
        } else {
            document.getElementById('topicsSection').style.display = 'none';
        }

        // Mandates
        if (data.top_mandates && data.top_mandates.length) {
            document.getElementById('mandatesSection').style.display = 'block';
            var m = '';
            data.top_mandates.forEach(function(item) {
                m += '<div class="mandate-item">';
                if (item.policy_topic) {
                    m += '<span class="mandate-topic-badge">' + escHtml(item.policy_topic) + '</span>';
                }
                m += '<span class="mandate-content">' + escHtml(item.content) + '</span>';
                m += '<div class="mandate-meta">' + escHtml(item.created_at) + '</div>';
                m += '</div>';
            });
            document.getElementById('mandatesBody').innerHTML = m;
        } else {
            document.getElementById('mandatesSection').style.display = 'none';
        }

        // CSV link
        document.getElementById('csvExport').href =
            '/api/mandate-summary.php?scope=' + encodeURIComponent(scope)
            + '&scope_value=' + encodeURIComponent(scopeValue)
            + '&period=' + encodeURIComponent(currentPeriod)
            + '&format=csv';
    }

    // Geo scope tabs
    document.querySelectorAll('#geoTabs .period-tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            document.querySelectorAll('#geoTabs .period-tab').forEach(function(t) { t.classList.remove('active'); });
            tab.classList.add('active');
            scope = tab.dataset.scope;
            scopeValue = userGeo[scope] || '';
            var label = userGeoLabels[scope] || 'All';
            document.getElementById('scopeLabel').innerHTML = escHtml(scope.charAt(0).toUpperCase() + scope.slice(1)) + ' &mdash; ' + escHtml(label);
            load(currentPeriod);
        });
    });

    // Period tabs
    document.querySelectorAll('.period-tabs:not(#geoTabs) .period-tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            document.querySelectorAll('.period-tabs:not(#geoTabs) .period-tab').forEach(function(t) { t.classList.remove('active'); });
            tab.classList.add('active');
            load(tab.dataset.period);
        });
    });

    // Initial load
    load('all');
})();
</script>

<?php
require __DIR__ . '/includes/footer.php';
?>
