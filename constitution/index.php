<?php
/**
 * The Constitution - The People's Branch
 * "Don't memorize the Constitution - live it"
 */

// Database connection
$config = require __DIR__ . '/../config.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die("Database connection failed");
}

// Get user data
require_once __DIR__ . '/../includes/get-user.php';
$dbUser = getUser($pdo);

// Fetch all sections
$stmt = $pdo->query("SELECT * FROM constitution_sections WHERE is_active = 1 ORDER BY display_order");
$sections = $stmt->fetchAll();

// Group by type
$preamble = array_filter($sections, fn($s) => $s['section_type'] === 'preamble');
$articles = array_filter($sections, fn($s) => $s['section_type'] === 'article');
$amendments = array_filter($sections, fn($s) => $s['section_type'] === 'amendment');

// Nav variables via helper
$navVars = getNavVarsForUser($dbUser);
extract($navVars);

// Page config
$pageTitle = 'The Constitution | The People\'s Branch';
$currentPage = 'government';
$pageStyles = '
/* TPB Framework */
.tpb-framework {
    background: #1a1a2e;
    border: 1px solid #2a2a3e;
    border-left: 4px solid #d4af37;
    border-radius: 8px;
    padding: 30px 35px;
    margin-bottom: 40px;
}
.tpb-framework p {
    color: #a0a0a0;
    font-size: 1.05rem;
    line-height: 1.7;
    margin-bottom: 18px;
    font-family: Georgia, serif;
}
.tpb-framework p:last-child {
    margin-bottom: 0;
}
.framework-intro {
    font-size: 1.1rem;
    color: #e0e0e0 !important;
}
.framework-lens {
    color: #d4af37 !important;
    font-size: 1.2rem;
    font-style: italic;
    text-align: center;
    padding: 15px 0;
}
.framework-physics {
    color: #ffdb58 !important;
    font-style: italic;
}
.framework-cta {
    text-align: center;
    padding-top: 10px;
    color: #e0e0e0 !important;
    font-size: 1.1rem;
}

/* Table of Contents */
.toc {
    background: #1a1a2e;
    border: 1px solid #2a2a3e;
    border-radius: 8px;
    padding: 25px 30px;
    margin-bottom: 40px;
}
.toc h2 {
    color: #d4af37;
    font-size: 1.3rem;
    margin-bottom: 20px;
    font-weight: normal;
}
.toc-section {
    margin-bottom: 15px;
}
.toc-section h3 {
    color: #e0e0e0;
    font-size: 1rem;
    margin-bottom: 8px;
    font-weight: normal;
}
.toc-links {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.toc-links a {
    color: #d4af37;
    text-decoration: none;
    font-size: 0.9rem;
    padding: 4px 10px;
    background: #0d0d15;
    border-radius: 4px;
    transition: background 0.2s;
}
.toc-links a:hover {
    background: #2a2a3e;
}

/* Constitution Sections */
.constitution-section {
    background: #1a1a2e;
    border: 1px solid #2a2a3e;
    border-radius: 10px;
    margin-bottom: 25px;
    overflow: hidden;
}
.section-header {
    background: #0d0d15;
    padding: 18px 25px;
    border-bottom: 1px solid #2a2a3e;
}
.section-header h2 {
    color: #ffdb58;
    font-size: 1.3rem;
    font-weight: normal;
    margin: 0;
}
.section-header .official-title {
    color: #666;
    font-size: 0.9rem;
    margin-top: 5px;
}
.section-body {
    padding: 25px;
}
.original-text {
    color: #e0e0e0;
    font-size: 1.05rem;
    line-height: 1.8;
    padding: 20px;
    background: #0d0d15;
    border-left: 3px solid #b8960c;
    border-radius: 4px;
    margin-bottom: 20px;
    font-family: Georgia, serif;
}
.layer2 {
    border-top: 1px solid #2a2a3e;
    padding-top: 20px;
}
.layer2 h4 {
    color: #d4af37;
    font-size: 1rem;
    margin: 20px 0 10px 0;
    font-weight: normal;
}
.layer2 h4:first-child {
    margin-top: 0;
}
.layer2 p {
    color: #a0a0a0;
    font-size: 1rem;
    line-height: 1.6;
}
.tpb-connection {
    background: rgba(212, 175, 55, 0.1);
    border: 1px solid #b8960c;
    border-radius: 6px;
    padding: 15px 20px;
    margin-top: 20px;
}
.tpb-connection h4 {
    margin-top: 0;
}
.no-layer2 {
    color: #666;
    font-style: italic;
    font-size: 0.95rem;
}

@media (max-width: 700px) {
    .tpb-framework {
        padding: 20px;
    }
    .section-body {
        padding: 15px;
    }
    .original-text {
        padding: 15px;
    }
}
';

// Include header and nav
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/nav.php';
?>

<main class="main" style="max-width: 900px;">
    <h1>The Constitution</h1>
    <p class="subtitle">Don't memorize the Constitution — live it.</p>

    <?php include __DIR__ . '/../includes/tpb-framework.php'; ?>

    <!-- Table of Contents -->
    <div class="toc">
        <h2>Contents</h2>
        
        <?php if (!empty($preamble)): ?>
        <div class="toc-section">
            <h3>Preamble</h3>
            <div class="toc-links">
                <?php foreach ($preamble as $s): ?>
                <a href="#section-<?= $s['section_id'] ?>"><?= htmlspecialchars($s['short_title'] ?: 'Preamble') ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($articles)): ?>
        <div class="toc-section">
            <h3>Articles</h3>
            <div class="toc-links">
                <?php foreach ($articles as $s): ?>
                <a href="#section-<?= $s['section_id'] ?>">Article <?= $s['section_number'] ?><?= $s['clause_number'] ? '.' . $s['clause_number'] : '' ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($amendments)): ?>
        <div class="toc-section">
            <h3>Amendments</h3>
            <div class="toc-links">
                <?php foreach ($amendments as $s): ?>
                <a href="#section-<?= $s['section_id'] ?>"><?= $s['section_number'] ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Constitution Content -->
    <?php foreach ($sections as $s): ?>
    <?php
    $hasLayer2 = !empty($s['plain_language']) || !empty($s['why_it_matters']) || 
                 !empty($s['how_to_exercise']) || !empty($s['when_violated']) || 
                 !empty($s['tpb_connection']);
    ?>
    <div class="constitution-section" id="section-<?= $s['section_id'] ?>">
        <div class="section-header">
            <h2>
                <?php if ($s['section_type'] === 'preamble'): ?>
                    Preamble
                <?php elseif ($s['section_type'] === 'article'): ?>
                    Article <?= $s['section_number'] ?><?= $s['clause_number'] ? ', Section ' . $s['clause_number'] : '' ?>
                <?php else: ?>
                    Amendment <?= $s['section_number'] ?>
                <?php endif; ?>
            </h2>
            <?php if (!empty($s['official_title'])): ?>
            <div class="official-title"><?= htmlspecialchars($s['official_title']) ?></div>
            <?php endif; ?>
        </div>
        <div class="section-body">
            <div class="original-text"><?= nl2br(htmlspecialchars($s['original_text'])) ?></div>

            <?php if ($hasLayer2): ?>
            <div class="layer2">
                <?php if (!empty($s['plain_language'])): ?>
                <h4>📖 Plain Language</h4>
                <p><?= nl2br(htmlspecialchars($s['plain_language'])) ?></p>
                <?php endif; ?>

                <?php if (!empty($s['why_it_matters'])): ?>
                <h4>💡 Why It Matters</h4>
                <p><?= nl2br(htmlspecialchars($s['why_it_matters'])) ?></p>
                <?php endif; ?>

                <?php if (!empty($s['how_to_exercise'])): ?>
                <h4>✊ How to Exercise</h4>
                <p><?= nl2br(htmlspecialchars($s['how_to_exercise'])) ?></p>
                <?php endif; ?>

                <?php if (!empty($s['when_violated'])): ?>
                <h4>⚠️ When Violated</h4>
                <p><?= nl2br(htmlspecialchars($s['when_violated'])) ?></p>
                <?php endif; ?>

                <?php if (!empty($s['tpb_connection'])): ?>
                <div class="tpb-connection">
                    <h4>🏛️ TPB Connection</h4>
                    <p><?= nl2br(htmlspecialchars($s['tpb_connection'])) ?></p>
                </div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <p class="no-layer2">Living interpretation coming soon...</p>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
