<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Map State Toggle Test</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            background: #1a1a1a;
            color: #e0e0e0;
            padding: 20px;
        }
        h1 { color: #d4af37; margin-bottom: 20px; }
        
        .container {
            display: block;
        }
        
        .map-container {
            width: 100%;
            background: #0a0a0a;
            border: 1px solid #333;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 20px;
            overflow: visible;
        }
        
        .map-container svg {
            width: 100%;
            height: auto;
            display: block;
        }
        
        /* Default state styling */
        .map-container svg .state path,
        .map-container svg .state circle {
            fill: #2a2a2a !important;
            stroke: #ffffff !important;
            stroke-width: 1 !important;
            cursor: pointer;
            transition: fill 0.2s;
        }
        
        .map-container svg .state path:hover,
        .map-container svg .state circle:hover {
            fill: #3a3a3a !important;
        }
        
        /* Gold active state - applied via JS */
        .map-container svg .state path.active-gold,
        .map-container svg .state circle.active-gold {
            fill: #d4af37 !important;
            stroke: #d4af37 !important;
            stroke-width: 2px !important;
        }
        
        .map-container svg .state path.active-gold:hover,
        .map-container svg .state circle.active-gold:hover {
            fill: #f4cf57 !important;
            stroke: #f4cf57 !important;
        }
        
        /* Hide border pointer events */
        .map-container svg .borders path {
            pointer-events: none !important;
        }
        
        .controls {
            width: 300px;
            background: #0a0a0a;
            border: 1px solid #333;
            border-radius: 8px;
            padding: 15px;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .controls h2 {
            color: #d4af37;
            margin-bottom: 10px;
            font-size: 1.1em;
        }
        
        .state-toggle {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 5px 0;
            border-bottom: 1px solid #222;
        }
        
        .state-toggle input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .state-toggle label {
            cursor: pointer;
            flex: 1;
        }
        
        .state-toggle .abbr {
            color: #888;
            font-size: 0.85em;
            width: 30px;
        }
        
        .quick-buttons {
            margin-bottom: 15px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .quick-buttons button {
            background: #333;
            color: #e0e0e0;
            border: 1px solid #555;
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
        }
        
        .quick-buttons button:hover {
            background: #444;
        }
        
        .info {
            margin-top: 15px;
            padding: 10px;
            background: #1a1a1a;
            border-radius: 4px;
            font-size: 0.9em;
        }
        
        .info strong {
            color: #d4af37;
        }
    </style>
</head>
<body>
    <h1>Map State Toggle Test</h1>
    
    <div class="container">
        <div class="map-container">
            <div id="mapHolder">Loading map...</div>
        </div>
        
        <div class="controls">
            <div class="quick-buttons">
                <button onclick="clearAll()">Clear All</button>
                <button onclick="selectAll()">Select All</button>
                <button onclick="toggleOKCT()">OK + CT Only</button>
                <button onclick="moveActiveToEnd()">Move Active to End</button>
            </div>
            
            <h2>Toggle States</h2>
            <div id="stateList"></div>
            
            <div class="info">
                <p><strong>Active:</strong> <span id="activeCount">0</span> states</p>
                <p><strong>Last clicked:</strong> <span id="lastClicked">-</span></p>
            </div>
        </div>
    </div>
    
    <script>
    const states = {
        'al': 'Alabama', 'ak': 'Alaska', 'az': 'Arizona', 'ar': 'Arkansas', 'ca': 'California',
        'co': 'Colorado', 'ct': 'Connecticut', 'de': 'Delaware', 'fl': 'Florida', 'ga': 'Georgia',
        'hi': 'Hawaii', 'id': 'Idaho', 'il': 'Illinois', 'in': 'Indiana', 'ia': 'Iowa',
        'ks': 'Kansas', 'ky': 'Kentucky', 'la': 'Louisiana', 'me': 'Maine', 'md': 'Maryland',
        'ma': 'Massachusetts', 'mi': 'Michigan', 'mn': 'Minnesota', 'ms': 'Mississippi', 'mo': 'Missouri',
        'mt': 'Montana', 'ne': 'Nebraska', 'nv': 'Nevada', 'nh': 'New Hampshire', 'nj': 'New Jersey',
        'nm': 'New Mexico', 'ny': 'New York', 'nc': 'North Carolina', 'nd': 'North Dakota', 'oh': 'Ohio',
        'ok': 'Oklahoma', 'or': 'Oregon', 'pa': 'Pennsylvania', 'ri': 'Rhode Island', 'sc': 'South Carolina',
        'sd': 'South Dakota', 'tn': 'Tennessee', 'tx': 'Texas', 'ut': 'Utah', 'vt': 'Vermont',
        'va': 'Virginia', 'wa': 'Washington', 'wv': 'West Virginia', 'wi': 'Wisconsin', 'wy': 'Wyoming',
        'dc': 'District of Columbia'
    };
    
    let activeStates = new Set();
    
    // Build state list
    const stateList = document.getElementById('stateList');
    Object.entries(states).sort((a, b) => a[1].localeCompare(b[1])).forEach(([abbr, name]) => {
        const div = document.createElement('div');
        div.className = 'state-toggle';
        div.innerHTML = `
            <input type="checkbox" id="chk_${abbr}" onchange="toggleState('${abbr}')">
            <span class="abbr">${abbr.toUpperCase()}</span>
            <label for="chk_${abbr}">${name}</label>
        `;
        stateList.appendChild(div);
    });
    
    // Load map
    fetch('usa-map.svg')
        .then(r => r.text())
        .then(svg => {
            document.getElementById('mapHolder').innerHTML = svg;
            
            // Add click handlers to states
            document.querySelectorAll('.state path, .state circle').forEach(el => {
                const stateClass = Array.from(el.classList).find(c => states[c]);
                if (stateClass) {
                    el.addEventListener('click', () => {
                        document.getElementById('chk_' + stateClass).click();
                    });
                }
            });
        });
    
    function toggleState(abbr) {
        const checkbox = document.getElementById('chk_' + abbr);
        const path = document.querySelector(`.state .${abbr}`);
        
        if (checkbox.checked) {
            activeStates.add(abbr);
            if (path) path.classList.add('active-gold');
        } else {
            activeStates.delete(abbr);
            if (path) path.classList.remove('active-gold');
        }
        
        updateInfo(abbr);
    }
    
    function updateInfo(lastAbbr) {
        document.getElementById('activeCount').textContent = activeStates.size;
        if (lastAbbr) {
            document.getElementById('lastClicked').textContent = 
                lastAbbr.toUpperCase() + ' (' + states[lastAbbr] + ')';
        }
    }
    
    function clearAll() {
        activeStates.forEach(abbr => {
            document.getElementById('chk_' + abbr).checked = false;
            const path = document.querySelector(`.state .${abbr}`);
            if (path) path.classList.remove('active-gold');
        });
        activeStates.clear();
        updateInfo();
    }
    
    function selectAll() {
        Object.keys(states).forEach(abbr => {
            document.getElementById('chk_' + abbr).checked = true;
            const path = document.querySelector(`.state .${abbr}`);
            if (path) path.classList.add('active-gold');
            activeStates.add(abbr);
        });
        updateInfo();
    }
    
    function toggleOKCT() {
        clearAll();
        ['ok', 'ct'].forEach(abbr => {
            document.getElementById('chk_' + abbr).checked = true;
            toggleState(abbr);
        });
    }
    
    function moveActiveToEnd() {
        const stateGroup = document.querySelector('.state');
        if (!stateGroup) return;
        
        activeStates.forEach(abbr => {
            const path = stateGroup.querySelector('.' + abbr);
            if (path) {
                stateGroup.appendChild(path);
                console.log('Moved', abbr, 'to end');
            }
        });
        
        alert('Moved ' + activeStates.size + ' active states to end of SVG');
    }
    </script>
</body>
</html>
