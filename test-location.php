<?php
/**
 * Test page for Location Lookup Module
 * 
 * Tests:
 * 1. Zip code lookup via API
 * 2. Town search via API  
 * 3. Full location modal flow
 * 
 * @package TPB
 * @since 2025-12-22
 */

$config = require 'config.php';

// Get states for the STATES array
try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $stmt = $pdo->query("SELECT state_id, state_name, abbreviation FROM states ORDER BY state_name");
    $states = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Location Lookup Test - TPB</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 2rem;
            background: #f5f5f5;
        }
        h1 { color: #1a1a2e; }
        h2 { 
            color: #333; 
            border-bottom: 2px solid #4a90a4;
            padding-bottom: 0.5rem;
            margin-top: 2rem;
        }
        .test-section {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        input, button {
            padding: 0.75rem 1rem;
            font-size: 1rem;
            border-radius: 8px;
        }
        input {
            border: 1px solid #ccc;
            width: 200px;
        }
        button {
            background: #4a90a4;
            color: white;
            border: none;
            cursor: pointer;
            margin-left: 0.5rem;
        }
        button:hover { background: #3a7a94; }
        .result {
            margin-top: 1rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
            font-family: monospace;
            white-space: pre-wrap;
            font-size: 0.9rem;
        }
        .result.success { border-left: 4px solid #28a745; }
        .result.error { border-left: 4px solid #dc3545; }
        .btn-primary {
            background: #4a90a4;
            color: white;
            border: none;
            cursor: pointer;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-size: 1rem;
        }
        .btn-primary:hover { background: #3a7a94; }
        .btn-secondary {
            background: #6c757d;
            color: white;
            border: none;
            cursor: pointer;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-size: 1rem;
        }
        .btn-text {
            background: transparent;
            color: #666;
            border: 1px solid #ddd;
            cursor: pointer;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-size: 1rem;
        }
        .location-confirm-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        .location-confirm-modal {
            background: white;
            padding: 2rem;
            border-radius: 16px;
            width: 90%;
            max-width: 450px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.2);
        }
        .log-entry { 
            padding: 0.5rem 0; 
            border-bottom: 1px solid #eee;
        }
        .log-entry:last-child { border-bottom: none; }
        .timestamp { color: #888; font-size: 0.85rem; }
    </style>
</head>
<body>
    <h1>🧪 Location Lookup Test</h1>
    <p>Testing the new database-driven location system (no Nominatim for lookups)</p>
    
    <h2>1. Zip Code Lookup (API Test)</h2>
    <div class="test-section">
        <input type="text" id="testZip" placeholder="Enter zip code" maxlength="5">
        <button onclick="testZipLookup()">Test Lookup</button>
        <div id="zipResult" class="result" style="display: none;"></div>
    </div>
    
    <h2>2. Town Search (API Test)</h2>
    <div class="test-section">
        <input type="text" id="testTownQuery" placeholder="Enter town name">
        <button onclick="testTownSearch()">Test Search</button>
        <div id="townResult" class="result" style="display: none;"></div>
    </div>
    
    <h2>3. Full Location Modal</h2>
    <div class="test-section">
        <p>Test the complete location selection flow (zip code or town search):</p>
        <button class="btn-primary" onclick="openLocationModal()">Open Location Modal</button>
        <div id="modalResult" class="result" style="display: none;"></div>
    </div>
    
    <h2>4. API Call Log</h2>
    <div class="test-section">
        <div id="apiLog" style="max-height: 300px; overflow-y: auto;">
            <p style="color: #888;">API calls will appear here...</p>
        </div>
    </div>

    <script>
        // Configuration
        const API_BASE = 'api';
        
        // States from PHP
        const STATES = <?= json_encode($states) ?>;
        
        // API logging
        function logApi(method, endpoint, data, response) {
            const log = document.getElementById('apiLog');
            const entry = document.createElement('div');
            entry.className = 'log-entry';
            entry.innerHTML = `
                <div class="timestamp">${new Date().toLocaleTimeString()}</div>
                <div><strong>${method}</strong> ${endpoint}</div>
                <div style="color: #666; font-size: 0.85rem;">Request: ${JSON.stringify(data)}</div>
                <div style="color: ${response.status === 'success' ? '#28a745' : '#dc3545'}; font-size: 0.85rem;">
                    Response: ${JSON.stringify(response).substring(0, 200)}${JSON.stringify(response).length > 200 ? '...' : ''}
                </div>
            `;
            log.insertBefore(entry, log.firstChild);
        }
        
        // Test zip lookup
        async function testZipLookup() {
            const zip = document.getElementById('testZip').value.trim();
            const resultDiv = document.getElementById('zipResult');
            
            if (zip.length !== 5) {
                resultDiv.textContent = 'Please enter a 5-digit zip code';
                resultDiv.className = 'result error';
                resultDiv.style.display = 'block';
                return;
            }
            
            try {
                const response = await fetch(`${API_BASE}/zip-lookup.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'lookup_zip', zip_code: zip })
                });
                
                const result = await response.json();
                logApi('POST', '/api/zip-lookup.php', { action: 'lookup_zip', zip_code: zip }, result);
                
                resultDiv.textContent = JSON.stringify(result, null, 2);
                resultDiv.className = `result ${result.status === 'success' ? 'success' : 'error'}`;
                resultDiv.style.display = 'block';
            } catch (err) {
                resultDiv.textContent = 'Error: ' + err.message;
                resultDiv.className = 'result error';
                resultDiv.style.display = 'block';
            }
        }
        
        // Test town search
        async function testTownSearch() {
            const query = document.getElementById('testTownQuery').value.trim();
            const resultDiv = document.getElementById('townResult');
            
            if (query.length < 2) {
                resultDiv.textContent = 'Please enter at least 2 characters';
                resultDiv.className = 'result error';
                resultDiv.style.display = 'block';
                return;
            }
            
            try {
                const response = await fetch(`${API_BASE}/zip-lookup.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'search_towns', query: query, limit: 10 })
                });
                
                const result = await response.json();
                logApi('POST', '/api/zip-lookup.php', { action: 'search_towns', query: query }, result);
                
                resultDiv.textContent = JSON.stringify(result, null, 2);
                resultDiv.className = `result ${result.status === 'success' ? 'success' : 'error'}`;
                resultDiv.style.display = 'block';
            } catch (err) {
                resultDiv.textContent = 'Error: ' + err.message;
                resultDiv.className = 'result error';
                resultDiv.style.display = 'block';
            }
        }
        
        // Open location modal
        function openLocationModal() {
            TPBLocation.showZipEntryModal({
                onSaved: function(locationData) {
                    const resultDiv = document.getElementById('modalResult');
                    resultDiv.textContent = 'Location saved: ' + JSON.stringify(locationData, null, 2);
                    resultDiv.className = 'result success';
                    resultDiv.style.display = 'block';
                },
                onSkip: function() {
                    const resultDiv = document.getElementById('modalResult');
                    resultDiv.textContent = 'User skipped location selection';
                    resultDiv.className = 'result';
                    resultDiv.style.display = 'block';
                }
            });
        }
    </script>
    
    <!-- Load the location module -->
    <script src="assets/location-module.js"></script>
</body>
</html>
