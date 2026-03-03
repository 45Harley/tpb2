<?php
/**
 * House Impeachment Vote Tracker
 * ===============================
 * Displays how every House member voted on Trump impeachment.
 * Data loaded from elections/data/impeachment-votes.json.
 * Filterable by state, party, and vote position.
 */

$config = require dirname(__DIR__) . '/config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

require_once dirname(__DIR__) . '/includes/get-user.php';
$dbUser = getUser($pdo);
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'elections';
$pageTitle = 'House Impeachment Vote — The People\'s Branch';

// Load vote data
$voteData = json_decode(file_get_contents(__DIR__ . '/data/impeachment-votes.json'), true);
$votes = $voteData['votes'] ?? [];

// Build state list for filter
$states = [];
foreach ($votes as $v) {
    $st = explode('-', $v['district'])[0];
    if ($st && $st !== '' && !isset($states[$st])) $states[$st] = $st;
}
ksort($states);

// Tally
$totalSupport = 0; $totalOppose = 0; $totalPresent = 0; $totalVacant = 0;
foreach ($votes as $v) {
    if (str_starts_with($v['vote'], 'Supports')) $totalSupport++;
    elseif (str_starts_with($v['vote'], 'Opposes')) $totalOppose++;
    elseif (str_starts_with($v['vote'], 'Voted present')) $totalPresent++;
    elseif ($v['vote'] === '') $totalVacant++;
}

$pageStyles = <<<'CSS'
.iv-container { max-width: 1000px; margin: 0 auto; padding: 0.25rem 1rem 1rem; }

.view-links { display: flex; gap: 0.5rem; margin-bottom: 0.5rem; flex-wrap: wrap; }
.view-links a {
    padding: 0.5rem 1rem; border-radius: 20px; font-size: 0.85rem; font-weight: 600;
    text-decoration: none; border: 1px solid #444; color: #aaa; transition: all 0.2s;
}
.view-links a:hover { border-color: #d4af37; color: #d4af37; }
.view-links a.active { background: #d4af37; color: #000; border-color: #d4af37; }

.iv-hero {
    text-align: center; padding: 0.5rem 1rem 0.75rem;
    border-bottom: 2px solid #ff4444; margin-bottom: 1rem;
}
.iv-hero h1 { color: #ff4444; font-size: 1.8rem; text-transform: uppercase; letter-spacing: 2px; margin-bottom: 0.5rem; }
.iv-hero .subtitle { color: #aaa; font-size: 1rem; margin-bottom: 1rem; }

.iv-tally {
    display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; margin-bottom: 1rem;
}
.iv-tally-box {
    padding: 0.75rem 1.25rem; border-radius: 8px; text-align: center; min-width: 100px;
}
.iv-tally-box .num { font-size: 1.8rem; font-weight: 700; display: block; }
.iv-tally-box .lbl { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px; }
.iv-tally-box.support { background: rgba(46,204,113,0.15); border: 1px solid rgba(46,204,113,0.4); color: #2ecc71; }
.iv-tally-box.oppose { background: rgba(231,76,60,0.15); border: 1px solid rgba(231,76,60,0.4); color: #e74c3c; }
.iv-tally-box.present { background: rgba(241,196,15,0.15); border: 1px solid rgba(241,196,15,0.4); color: #f1c40f; }

/* Filters */
.iv-filters {
    display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1rem;
    align-items: center;
}
.iv-filters label { color: #aaa; font-size: 0.85rem; }
.iv-filters select, .iv-filters input {
    background: #1a1a2e; color: #e0e0e0; border: 1px solid #444;
    padding: 0.4rem 0.6rem; border-radius: 6px; font-size: 0.9rem;
}
.iv-filters input { flex: 1; min-width: 150px; }
.iv-filters select:focus, .iv-filters input:focus { border-color: #d4af37; outline: none; }

/* Table */
.iv-table-wrap { overflow-x: auto; border-radius: 8px; border: 1px solid #333; }
.iv-table {
    width: 100%; border-collapse: collapse; font-size: 0.9rem;
}
.iv-table th {
    background: #1a1a2e; color: #d4af37; padding: 0.6rem 0.75rem;
    text-align: left; font-weight: 600; position: sticky; top: 0; z-index: 1;
    border-bottom: 2px solid #d4af37; cursor: pointer; user-select: none;
    white-space: nowrap;
}
.iv-table th:hover { color: #f5c842; }
.iv-table th .sort-arrow { font-size: 0.7rem; margin-left: 4px; opacity: 0.5; }
.iv-table th.sorted .sort-arrow { opacity: 1; }
.iv-table td { padding: 0.5rem 0.75rem; border-bottom: 1px solid #222; }
.iv-table tr:hover td { background: rgba(212,175,55,0.05); }
.iv-table tr.hidden { display: none; }

/* Vote badges */
.vote-badge {
    display: inline-block; padding: 0.2rem 0.6rem; border-radius: 4px;
    font-size: 0.8rem; font-weight: 600; white-space: nowrap;
}
.vote-badge.support { background: rgba(46,204,113,0.2); color: #2ecc71; }
.vote-badge.oppose { background: rgba(231,76,60,0.2); color: #e74c3c; }
.vote-badge.present { background: rgba(241,196,15,0.2); color: #f1c40f; }
.vote-badge.vacant { background: rgba(128,128,128,0.2); color: #888; }

/* Party */
.party-d { color: #5dade2; }
.party-r { color: #e74c3c; }

.iv-count { color: #888; font-size: 0.85rem; margin-bottom: 0.5rem; }

.iv-footnotes { color: #666; font-size: 0.8rem; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #222; }
.iv-footnotes p { margin-bottom: 0.3rem; }


/* Rep popover */
.rep-popover {
    display: none; position: absolute; z-index: 100;
    background: #1a1a2e; border: 1px solid #d4af37; border-radius: 10px;
    padding: 1rem; min-width: 260px; max-width: 320px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.5);
    pointer-events: auto; cursor: default;
}
.rep-popover.show { display: block; }
.rep-popover.dragging { cursor: grabbing; user-select: none; }
.rep-popover .pop-close {
    position: absolute; top: 6px; right: 10px;
    background: none; border: none; color: #888; font-size: 1.2rem;
    cursor: pointer; padding: 2px 6px; border-radius: 4px; line-height: 1;
}
.rep-popover .pop-close:hover { color: #fff; background: rgba(255,255,255,0.1); }
.rep-popover .pop-drag-bar {
    position: absolute; top: 0; left: 0; right: 30px; height: 28px;
    cursor: grab; border-radius: 10px 0 0 0;
}
.rep-popover .pop-header {
    display: flex; gap: 0.75rem; align-items: center; margin-bottom: 0.75rem;
    padding-top: 0.25rem;
}
.rep-popover .pop-photo {
    width: 56px; height: 56px; border-radius: 50%; object-fit: cover;
    border: 2px solid #d4af37; flex-shrink: 0; background: #333;
}
.rep-popover .pop-name { color: #fff; font-size: 1rem; font-weight: 600; }
.rep-popover .pop-office { color: #888; font-size: 0.8rem; }
.rep-popover .pop-party-d { color: #5dade2; }
.rep-popover .pop-party-r { color: #e74c3c; }
.rep-popover .pop-links {
    display: flex; flex-direction: column; gap: 0.4rem;
}
.rep-popover .pop-link {
    display: flex; align-items: center; gap: 0.5rem;
    color: #ccc; text-decoration: none; font-size: 0.85rem;
    padding: 0.3rem 0.5rem; border-radius: 4px;
    transition: background 0.2s;
}
.rep-popover .pop-link:hover { background: rgba(212,175,55,0.15); color: #fff; }
.rep-popover .pop-link .pop-icon { width: 18px; text-align: center; flex-shrink: 0; }
.rep-popover .pop-detail {
    display: block; margin-top: 0.5rem; text-align: center;
    color: #d4af37; font-size: 0.8rem; text-decoration: none;
    padding: 0.3rem; border-top: 1px solid #333;
}
.rep-popover .pop-detail:hover { text-decoration: underline; }
.rep-popover .pop-loading { color: #888; font-size: 0.85rem; text-align: center; padding: 1rem 0; }

.iv-table tr { position: relative; cursor: pointer; }

@media (max-width: 600px) {
    .iv-hero h1 { font-size: 1.3rem; }
    .iv-tally-box .num { font-size: 1.3rem; }
    .iv-filters { flex-direction: column; }
    .rep-popover { min-width: 220px; max-width: 280px; }
}
CSS;

include dirname(__DIR__) . '/includes/header.php';
include dirname(__DIR__) . '/includes/nav.php';
?>

<div class="iv-container">
    <div class="view-links">
        <a href="/elections/">Elections</a>
        <a href="/elections/the-fight.php">The Fight</a>
        <a href="/elections/the-amendment.php">The War</a>
        <a href="/elections/threats.php">Threats</a>
        <a href="/elections/races.php">Races</a>
        <a href="/elections/impeachment-vote.php" class="active">Trump Impeachment Vote #1</a>
    </div>

    <div class="iv-hero">
        <h1>House Impeachment Vote</h1>
        <p class="subtitle">How your representative voted on Trump impeachment</p>
        <div class="iv-tally">
            <div class="iv-tally-box support"><span class="num" id="tallySupport"><?= $totalSupport ?></span><span class="lbl">Support</span></div>
            <div class="iv-tally-box oppose"><span class="num" id="tallyOppose"><?= $totalOppose ?></span><span class="lbl">Oppose</span></div>
            <div class="iv-tally-box present"><span class="num" id="tallyPresent"><?= $totalPresent ?></span><span class="lbl">Present</span></div>
        </div>
    </div>

    <div class="iv-filters">
        <label>Filter:</label>
        <select id="filterState">
            <option value="">All States</option>
            <?php foreach ($states as $st): ?>
            <option value="<?= $st ?>"><?= $st ?></option>
            <?php endforeach; ?>
        </select>
        <select id="filterParty">
            <option value="">All Parties</option>
            <option value="Democrat">Democrat</option>
            <option value="Republican">Republican</option>
        </select>
        <select id="filterVote">
            <option value="">All Votes</option>
            <option value="Supports">Supports</option>
            <option value="Opposes">Opposes</option>
            <option value="Present">Present</option>
        </select>
        <input type="text" id="filterName" placeholder="Search by name...">
    </div>

    <p class="iv-count"><span id="visibleCount"><?= count($votes) ?></span> of <?= count($votes) ?> members shown</p>

    <div class="iv-table-wrap">
        <table class="iv-table" id="voteTable">
            <thead>
                <tr>
                    <th data-col="name">Name <span class="sort-arrow">&#x25B2;</span></th>
                    <th data-col="district">District <span class="sort-arrow">&#x25B2;</span></th>
                    <th data-col="party">Party <span class="sort-arrow">&#x25B2;</span></th>
                    <th data-col="vote">Vote <span class="sort-arrow">&#x25B2;</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($votes as $v):
                    $voteClass = 'vacant';
                    if (str_starts_with($v['vote'], 'Supports')) $voteClass = 'support';
                    elseif (str_starts_with($v['vote'], 'Opposes')) $voteClass = 'oppose';
                    elseif (str_starts_with($v['vote'], 'Voted present')) $voteClass = 'present';
                    $partyClass = $v['party'] === 'Democrat' ? 'party-d' : ($v['party'] === 'Republican' ? 'party-r' : '');
                    $state = explode('-', $v['district'])[0];
                ?>
                <tr data-state="<?= $state ?>" data-party="<?= htmlspecialchars($v['party']) ?>" data-vote="<?= $voteClass ?>" data-district="<?= htmlspecialchars($v['district']) ?>">
                    <td><?= htmlspecialchars($v['first'] . ' ' . $v['last']) ?></td>
                    <td><?= htmlspecialchars($v['district']) ?></td>
                    <td class="<?= $partyClass ?>"><?= htmlspecialchars($v['party']) ?></td>
                    <td><span class="vote-badge <?= $voteClass ?>"><?= htmlspecialchars($v['vote']) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Rep contact popover (positioned dynamically by JS) -->
    <div class="rep-popover" id="repPopover"></div>

    <div class="iv-footnotes">
        <p>* Voted to abstain from the vote by voting "present."</p>
        <p>** Did not vote in the December 2025 vote, but their position is based on how they voted in the June 2025 vote and statements from their office.</p>
    </div>
</div>

<script>
(function() {
    var table = document.getElementById('voteTable');
    var rows = Array.from(table.querySelectorAll('tbody tr'));
    var filterState = document.getElementById('filterState');
    var filterParty = document.getElementById('filterParty');
    var filterVote = document.getElementById('filterVote');
    var filterName = document.getElementById('filterName');
    var countEl = document.getElementById('visibleCount');

    var tallySupport = document.getElementById('tallySupport');
    var tallyOppose = document.getElementById('tallyOppose');
    var tallyPresent = document.getElementById('tallyPresent');

    function applyFilters() {
        var st = filterState.value;
        var pa = filterParty.value;
        var vo = filterVote.value.toLowerCase();
        var nm = filterName.value.toLowerCase();
        var visible = 0, sup = 0, opp = 0, pre = 0;

        rows.forEach(function(row) {
            var show = true;
            if (st && row.dataset.state !== st) show = false;
            if (pa && row.dataset.party !== pa) show = false;
            if (vo && row.dataset.vote !== vo) show = false;
            if (nm && row.cells[0].textContent.toLowerCase().indexOf(nm) === -1) show = false;

            row.classList.toggle('hidden', !show);
            if (show) {
                visible++;
                if (row.dataset.vote === 'support') sup++;
                else if (row.dataset.vote === 'oppose') opp++;
                else if (row.dataset.vote === 'present') pre++;
            }
        });

        countEl.textContent = visible;
        tallySupport.textContent = sup;
        tallyOppose.textContent = opp;
        tallyPresent.textContent = pre;
    }

    filterState.addEventListener('change', applyFilters);
    filterParty.addEventListener('change', applyFilters);
    filterVote.addEventListener('change', applyFilters);
    filterName.addEventListener('input', applyFilters);

    // Column sorting
    var headers = table.querySelectorAll('th');
    var sortCol = null;
    var sortAsc = true;

    headers.forEach(function(th, idx) {
        th.addEventListener('click', function() {
            if (sortCol === idx) { sortAsc = !sortAsc; }
            else { sortCol = idx; sortAsc = true; }

            headers.forEach(function(h) { h.classList.remove('sorted'); });
            th.classList.add('sorted');
            th.querySelector('.sort-arrow').innerHTML = sortAsc ? '&#x25B2;' : '&#x25BC;';

            rows.sort(function(a, b) {
                var va = a.cells[idx].textContent.trim();
                var vb = b.cells[idx].textContent.trim();
                return sortAsc ? va.localeCompare(vb) : vb.localeCompare(va);
            });

            var tbody = table.querySelector('tbody');
            rows.forEach(function(r) { tbody.appendChild(r); });
        });
    });

    // ---- Rep contact popover on click, draggable, closable ----
    var popover = document.getElementById('repPopover');
    var popCache = {};
    var popTimer = null;
    var activeRow = null;
    var pinned = false;

    function showPopover(row) {
        var district = row.dataset.district;
        if (!district || district === '') return;

        activeRow = row;
        pinned = true;

        // Position popover near the row
        var rect = row.getBoundingClientRect();
        var scrollY = window.scrollY || document.documentElement.scrollTop;
        var scrollX = window.scrollX || document.documentElement.scrollLeft;
        popover.style.top = (rect.bottom + scrollY + 4) + 'px';
        popover.style.left = Math.min(rect.left + scrollX + 40, window.innerWidth - 340) + 'px';

        if (popCache[district]) {
            renderPopover(popCache[district]);
            return;
        }

        popover.innerHTML = '<button class="pop-close" title="Close">&times;</button><div class="pop-loading">Loading rep info...</div>';
        popover.classList.add('show');
        bindClose();

        fetch('/api/get-rep-by-district.php?district=' + encodeURIComponent(district))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                popCache[district] = data;
                if (activeRow === row) renderPopover(data);
            })
            .catch(function() {
                popover.innerHTML = '<button class="pop-close" title="Close">&times;</button><div class="pop-loading">Could not load rep info</div>';
                bindClose();
            });
    }

    function renderPopover(data) {
        var html = '<button class="pop-close" title="Close">&times;</button>';
        html += '<div class="pop-drag-bar"></div>';

        if (!data.found) {
            html += '<div class="pop-loading">Vacant seat</div>';
            popover.innerHTML = html;
            popover.classList.add('show');
            bindClose(); initDrag();
            return;
        }

        var partyClass = data.party === 'Democratic' ? 'pop-party-d' : (data.party === 'Republican' ? 'pop-party-r' : '');
        html += '<div class="pop-header">';
        if (data.photo) {
            html += '<img class="pop-photo" src="' + data.photo + '" alt="" onerror="this.style.display=\'none\'">';
        }
        html += '<div><div class="pop-name">' + data.name + '</div>';
        html += '<div class="pop-office ' + partyClass + '">' + data.office + '</div></div></div>';
        html += '<div class="pop-links">';
        if (data.phone) {
            html += '<a class="pop-link" href="tel:' + data.phone + '"><span class="pop-icon">&#x1F4DE;</span> ' + data.phone + '</a>';
        }
        if (data.website) {
            html += '<a class="pop-link" href="' + data.website + '" target="_blank"><span class="pop-icon">&#x1F310;</span> ' + data.website.replace('https://', '') + '</a>';
        }
        html += '</div>';
        if (data.detail_url) {
            html += '<a class="pop-detail" href="' + data.detail_url + '">View full profile &rarr;</a>';
        }

        popover.innerHTML = html;
        popover.classList.add('show');
        bindClose();
        initDrag();
    }

    function closePopover() {
        popover.classList.remove('show');
        activeRow = null;
        pinned = false;
    }

    function bindClose() {
        var btn = popover.querySelector('.pop-close');
        if (btn) btn.addEventListener('click', function(e) { e.stopPropagation(); closePopover(); });
    }

    // Drag support
    function initDrag() {
        var bar = popover.querySelector('.pop-drag-bar');
        if (!bar) return;
        var dragX, dragY, startLeft, startTop;

        bar.addEventListener('mousedown', function(e) {
            e.preventDefault();
            dragX = e.clientX;
            dragY = e.clientY;
            startLeft = popover.offsetLeft;
            startTop = popover.offsetTop;
            popover.classList.add('dragging');
            document.addEventListener('mousemove', onDrag);
            document.addEventListener('mouseup', stopDrag);
        });

        function onDrag(e) {
            popover.style.left = (startLeft + e.clientX - dragX) + 'px';
            popover.style.top = (startTop + e.clientY - dragY) + 'px';
        }
        function stopDrag() {
            popover.classList.remove('dragging');
            document.removeEventListener('mousemove', onDrag);
            document.removeEventListener('mouseup', stopDrag);
        }
    }

    // Click row to show popover (replaces hover)
    rows.forEach(function(row) {
        row.addEventListener('click', function(e) {
            // Don't intercept link clicks inside popover
            if (e.target.closest('.rep-popover')) return;
            showPopover(row);
        });
    });
})();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
</body>
</html>
