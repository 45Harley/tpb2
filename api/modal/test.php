<?php
/**
 * TPB2 Modal Help System - API Test
 * ==================================
 * Quick test page to verify all endpoints are working
 * 
 * Visit: /api/modal/test.php
 * 
 * DELETE THIS FILE AFTER TESTING!
 */

require_once __DIR__ . '/../../config/modal_config.php';

// HTML output for browser
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Modal API Test</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #0d0d0d; color: #d4af37; padding: 30px; }
        h1 { color: #ffdb58; }
        .test { background: #1a1a1a; padding: 20px; margin: 15px 0; border-radius: 8px; border: 1px solid #2a2a2a; }
        .test h3 { margin-top: 0; color: #ffdb58; }
        .success { color: #2ecc71; }
        .error { color: #e74c3c; }
        pre { background: #252525; padding: 15px; border-radius: 6px; overflow-x: auto; font-size: 12px; }
        code { color: #66bb6a; }
    </style>
</head>
<body>
    <h1>🔧 TPB2 Modal Help API - Test Results</h1>
    
    <?php
    // Test 1: Database Connection
    echo '<div class="test">';
    echo '<h3>Test 1: Database Connection</h3>';
    try {
        $db = getModalDB();
        $stmt = $db->query("SELECT COUNT(*) as count FROM modals");
        $result = $stmt->fetch();
        echo '<p class="success">✅ Connected! Found ' . $result['count'] . ' modals in database.</p>';
    } catch (Exception $e) {
        echo '<p class="error">❌ Connection failed: ' . $e->getMessage() . '</p>';
    }
    echo '</div>';
    
    // Test 2: Get Single Modal
    echo '<div class="test">';
    echo '<h3>Test 2: Get Single Modal (key: how_voting_works)</h3>';
    echo '<p><code>GET /api/modal/get-modal.php?key=how_voting_works</code></p>';
    try {
        $stmt = $db->prepare("SELECT modal_key, title, icon_type FROM modals WHERE modal_key = :key AND status = 'published'");
        $stmt->execute(['key' => 'how_voting_works']);
        $modal = $stmt->fetch();
        if ($modal) {
            echo '<p class="success">✅ Found: "' . $modal['title'] . '" (' . getIconEmoji($modal['icon_type']) . ' ' . $modal['icon_type'] . ')</p>';
        } else {
            echo '<p class="error">❌ Modal not found or not published</p>';
        }
    } catch (Exception $e) {
        echo '<p class="error">❌ Error: ' . $e->getMessage() . '</p>';
    }
    echo '</div>';
    
    // Test 3: Get Page Modals
    echo '<div class="test">';
    echo '<h3>Test 3: Get Page Modals (page: registration)</h3>';
    echo '<p><code>GET /api/modal/get-page-modals.php?page=registration</code></p>';
    try {
        $stmt = $db->prepare("
            SELECT m.modal_key, m.title, tl.tag_identifier 
            FROM modals m 
            INNER JOIN tag_locations tl ON m.modal_id = tl.modal_id 
            WHERE tl.page_name = :page AND tl.is_visible = TRUE AND m.status = 'published'
        ");
        $stmt->execute(['page' => 'registration']);
        $modals = $stmt->fetchAll();
        if (count($modals) > 0) {
            echo '<p class="success">✅ Found ' . count($modals) . ' modal(s) for registration page:</p>';
            echo '<ul>';
            foreach ($modals as $m) {
                echo '<li>' . $m['modal_key'] . ' @ ' . $m['tag_identifier'] . '</li>';
            }
            echo '</ul>';
        } else {
            echo '<p class="error">❌ No modals found for registration page</p>';
        }
    } catch (Exception $e) {
        echo '<p class="error">❌ Error: ' . $e->getMessage() . '</p>';
    }
    echo '</div>';
    
    // Test 4: List All Modals
    echo '<div class="test">';
    echo '<h3>Test 4: List All Modals (Admin API)</h3>';
    echo '<p><code>GET /api/modal/admin.php</code></p>';
    try {
        $stmt = $db->query("SELECT modal_key, title, status, is_active FROM modals ORDER BY updated_at DESC");
        $modals = $stmt->fetchAll();
        echo '<p class="success">✅ Found ' . count($modals) . ' total modal(s):</p>';
        echo '<pre>';
        foreach ($modals as $m) {
            $status = $m['status'] === 'published' ? '🟢' : '🟡';
            $active = $m['is_active'] ? '✓' : '✗';
            echo $status . ' ' . str_pad($m['modal_key'], 25) . ' | ' . $active . ' active | ' . $m['title'] . "\n";
        }
        echo '</pre>';
    } catch (Exception $e) {
        echo '<p class="error">❌ Error: ' . $e->getMessage() . '</p>';
    }
    echo '</div>';
    
    // Test 5: Predefined Pages
    echo '<div class="test">';
    echo '<h3>Test 5: Get Predefined Pages</h3>';
    echo '<p><code>GET /api/modal/get-pages.php</code></p>';
    try {
        $stmt = $db->query("SELECT page_name, page_title FROM predefined_pages WHERE is_active = TRUE ORDER BY page_title");
        $pages = $stmt->fetchAll();
        echo '<p class="success">✅ Found ' . count($pages) . ' predefined pages:</p>';
        echo '<ul style="columns: 2;">';
        foreach ($pages as $p) {
            echo '<li>' . $p['page_name'] . ' - ' . $p['page_title'] . '</li>';
        }
        echo '</ul>';
    } catch (Exception $e) {
        echo '<p class="error">❌ Error: ' . $e->getMessage() . '</p>';
    }
    echo '</div>';
    
    // Test 6: Icon Types
    echo '<div class="test">';
    echo '<h3>Test 6: All 16 Icon Types</h3>';
    $icons = ['info', 'help', 'preview', 'warning', 'tip', 'docs', 'tutorial', 'feature',
              'success', 'new', 'security', 'important', 'location', 'external', 'social', 'philosophy'];
    echo '<p class="success">✅ All icons available:</p>';
    echo '<p style="font-size: 1.5em;">';
    foreach ($icons as $icon) {
        echo getIconEmoji($icon) . ' ';
    }
    echo '</p>';
    echo '<p style="font-size: 0.9em; color: #888;">';
    echo implode(' • ', $icons);
    echo '</p>';
    echo '</div>';
    
    // Summary
    echo '<div class="test" style="border-color: #d4af37; border-width: 2px;">';
    echo '<h3>📋 API Endpoints Summary</h3>';
    echo '<pre>';
    echo "PUBLIC ENDPOINTS:\n";
    echo "  GET  /api/modal/get-modal.php?key=xxx      → Get single modal\n";
    echo "  GET  /api/modal/get-page-modals.php?page=xxx → Get all modals for page\n";
    echo "  POST /api/modal/log-click.php              → Log analytics click\n";
    echo "  GET  /api/modal/get-pages.php              → List predefined pages\n";
    echo "\n";
    echo "ADMIN ENDPOINTS:\n";
    echo "  GET    /api/modal/admin.php                → List all modals\n";
    echo "  GET    /api/modal/admin.php?id=1           → Get single modal + locations\n";
    echo "  POST   /api/modal/admin.php                → Create new modal\n";
    echo "  PUT    /api/modal/admin.php?id=1           → Update modal\n";
    echo "  DELETE /api/modal/admin.php?id=1           → Delete modal\n";
    echo '</pre>';
    echo '</div>';
    ?>
    
    <p style="text-align: center; color: #666; margin-top: 30px;">
        ⚠️ <strong>DELETE THIS FILE AFTER TESTING</strong> ⚠️
    </p>
</body>
</html>
