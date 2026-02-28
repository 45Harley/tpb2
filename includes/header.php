<?php
/**
 * TPB Shared Header
 * =================
 * Include at top of all pages after PHP logic.
 * 
 * Required variables:
 *   $pageTitle - string, page title
 * 
 * Optional variables:
 *   $pageStyles      - string, additional CSS
 *   $ogTitle         - string, Open Graph title (defaults to $pageTitle)
 *   $ogDescription   - string, Open Graph description
 *   $ogImage         - string, Open Graph image URL
 */

$pageTitle = isset($pageTitle) ? $pageTitle : 'The People\'s Branch';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
<?php
    $ogTitle = isset($ogTitle) ? $ogTitle : $pageTitle;
    $ogDesc  = isset($ogDescription) ? $ogDescription : 'The People\'s Branch — No Kings. Only Citizens.';
    $_baseUrl = isset($c) ? ($c['base_url'] ?? 'https://4tpb.org') : 'https://4tpb.org';
    $ogImage = isset($ogImage) ? $ogImage : ($_baseUrl . '/0media/PeoplesBranch.png');
    $ogUrl   = isset($ogUrl) ? $ogUrl : ((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '4tpb.org') . ($_SERVER['REQUEST_URI'] ?? '/'));
?>
    <meta property="og:title" content="<?= htmlspecialchars($ogTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($ogDesc) ?>">
    <meta property="og:image" content="<?= htmlspecialchars($ogImage) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($ogUrl) ?>">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($ogTitle) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($ogDesc) ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($ogImage) ?>">
<?php if (!empty($headLinks)) echo $headLinks; ?>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0a0a0f;
            color: #e0e0e0;
            min-height: 100vh;
        }
        
        /* Navigation */
        .top-nav {
            background: #1a1a2e;
            border-bottom: 1px solid #333;
            padding: 0 1rem;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            height: 60px;
        }
        .nav-brand {
            font-size: 1.25rem;
            font-weight: bold;
            color: #d4af37;
            text-decoration: none;
            margin-right: 2rem;
        }
        .nav-brand:hover {
            color: #e4bf47;
        }
        .nav-links {
            display: flex;
            gap: 0.5rem;
        }
        .nav-links a {
            color: #888;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            transition: all 0.2s;
        }
        .nav-links a:hover {
            color: #e0e0e0;
            background: #2a2a3e;
        }
        .nav-links a.active {
            color: #d4af37;
            background: #2a2a3e;
        }
        .nav-status {
            margin-left: auto;
            text-align: right;
            font-size: 0.9rem;
        }
        .nav-status .level { color: #d4af37; font-weight: 500; }
        .nav-status .points { color: #888; }
        .nav-status .next { color: #666; font-size: 0.8rem; }
        .nav-status .logout-link { 
            color: #666; 
            font-size: 0.8rem; 
            text-decoration: none;
            margin-top: 0.25rem;
        }
        .nav-status .logout-link:hover { color: #e74c3c; }
        
        /* Common elements */
        .main {
            max-width: 800px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }
        .main.narrow {
            max-width: 600px;
        }
        
        h1 {
            color: #d4af37;
            margin-bottom: 0.5rem;
        }
        .subtitle {
            color: #888;
            margin-bottom: 2rem;
        }
        
        /* Cards */
        .card {
            background: #1a1a2e;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .card h2 {
            color: #e0e0e0;
            font-size: 1.1rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        /* Form elements */
        .form-group {
            margin-bottom: 1rem;
        }
        .form-group label {
            display: block;
            color: #888;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        .form-group label .check { color: #4caf50; }
        .form-row {
            display: flex;
            gap: 0.5rem;
        }
        input, select, textarea {
            width: 100%;
            padding: 0.75rem;
            font-size: 1rem;
            background: #0a0a0f;
            border: 1px solid #333;
            border-radius: 8px;
            color: #e0e0e0;
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #d4af37;
        }
        input:disabled, select:disabled, textarea:disabled {
            background: #0a0a0f;
            color: #666;
        }
        textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        /* Buttons */
        .btn {
            padding: 0.75rem 1.25rem;
            font-size: 1rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-primary {
            background: #d4af37;
            color: #000;
        }
        .btn-primary:hover { background: #e4bf47; }
        .btn-secondary {
            background: #333;
            color: #e0e0e0;
        }
        .btn-secondary:hover { background: #444; }
        .btn-text {
            background: transparent;
            color: #888;
            border: 1px solid #333;
        }
        .btn-text:hover { color: #e0e0e0; border-color: #555; }
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Alerts */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        .alert-info {
            background: #1a2a3a;
            color: #4a90a4;
        }
        .alert-warning {
            background: #3a3a1a;
            color: #d4af37;
        }
        .alert-success {
            background: #1a3a1a;
            color: #4caf50;
        }
        .alert-error {
            background: #3a1a1a;
            color: #e63946;
        }
        .alert a {
            color: inherit;
        }
        
        /* Status messages */
        .status-msg {
            padding: 0.75rem;
            border-radius: 8px;
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }
        .status-msg.success { background: #1a3a1a; color: #4caf50; }
        .status-msg.error { background: #3a1a1a; color: #e63946; }
        .status-msg.info { background: #1a2a3a; color: #4a90a4; }
        
        /* Footer */
        .footer {
            text-align: center;
            padding: 2rem;
            color: #666;
            font-size: 0.85rem;
        }
        .footer a { color: #d4af37; }
        
        /* Checkbox groups */
        .checkbox-group {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            padding: 0.5rem 0.75rem;
            background: #0a0a0f;
            border: 1px solid #333;
            border-radius: 6px;
            font-size: 0.9rem;
            transition: all 0.2s;
        }
        .checkbox-item:hover {
            border-color: #555;
        }
        .checkbox-item input[type="checkbox"] {
            width: auto;
            margin: 0;
        }
        .checkbox-item input[type="checkbox"]:checked + span {
            color: #d4af37;
        }
        
        /* Dictate button */
        .dictate-btn {
            position: absolute;
            right: 10px;
            top: 10px;
            background: #2a2a3e;
            border: 1px solid #444;
            border-radius: 6px;
            padding: 0.5rem;
            font-size: 1.2rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .dictate-btn:hover {
            background: #3a3a4e;
            border-color: #d4af37;
        }
        .dictate-btn.recording {
            background: #5a2a2a;
            border-color: #e63946;
            animation: pulse 1s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #666;
        }
        .empty-state .icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        
        /* Responsive */
        @media (max-width: 600px) {
            .nav-container {
                flex-wrap: wrap;
                height: auto;
                padding: 0.5rem 0;
            }
            .nav-links {
                order: 3;
                width: 100%;
                justify-content: center;
                margin-top: 0.5rem;
            }
            .nav-status {
                font-size: 0.8rem;
            }
            .form-row {
                flex-direction: column;
            }
            .checkbox-group {
                flex-direction: column;
            }
        }
<?php if (isset($pageStyles)): ?>
        
        /* Page-specific styles */
        <?= $pageStyles ?>
<?php endif; ?>
    </style>
</head>
<body>
