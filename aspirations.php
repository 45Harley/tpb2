<?php
/**
 * Our Aspirations - The Democracy of Our Aspirations
 * Database-driven display - pulls from aspirations table
 */

// Database connection
$config = require 'config.php';

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
require_once __DIR__ . '/includes/get-user.php';
$dbUser = getUser($pdo);

// Fetch active aspirations
$stmt = $pdo->query("SELECT * FROM aspirations WHERE is_active = 1 ORDER BY display_order ASC");
$aspirations = $stmt->fetchAll();
$navVars = getNavVarsForUser($dbUser);
extract($navVars);

// Page config
$pageTitle = 'Our Aspirations | The People\'s Branch';
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

/* Aspiration Cards */
.aspiration-card {
    background: #1a1a2e;
    border: 1px solid #2a2a3e;
    border-radius: 10px;
    margin-bottom: 25px;
    overflow: hidden;
    transition: border-color 0.2s ease;
}
.aspiration-card:hover {
    border-color: #b8960c;
}
.card-header {
    background: #0d0d15;
    padding: 18px 25px;
    display: flex;
    align-items: center;
    gap: 15px;
    border-bottom: 1px solid #2a2a3e;
}
.card-number {
    background: #d4af37;
    color: #000;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 0.9rem;
    flex-shrink: 0;
}
.card-header h2 {
    font-size: 1.3rem;
    font-weight: normal;
    font-style: italic;
    color: #ffdb58;
    margin: 0;
}
.card-body {
    padding: 0;
}
.columns {
    display: grid;
    grid-template-columns: 1fr 1fr;
}
.cell {
    padding: 20px 25px;
}
.reality-cell {
    border-right: 1px solid #2a2a3e;
}
.cell-label {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    margin-bottom: 10px;
}
.reality-cell .cell-label {
    color: #e57373;
}
.solution-cell .cell-label {
    color: #81c784;
}
.cell-content {
    font-size: 1rem;
    color: #e0e0e0;
    line-height: 1.5;
}
.solution-cell .cell-content {
    color: #81c784;
    font-weight: 600;
}
.how-section {
    padding: 20px 25px;
    background: #0d0d15;
    border-top: 1px solid #2a2a3e;
}
.how-section .cell-label {
    color: #666;
}
.how-section .cell-content {
    color: #a0a0a0;
}
.learn-more {
    display: inline-block;
    margin-top: 15px;
    background: #d4af37;
    color: #000;
    padding: 8px 20px;
    border-radius: 4px;
    text-decoration: none;
    font-size: 0.85rem;
    font-weight: bold;
    transition: background 0.2s ease;
}
.learn-more:hover {
    background: #ffdb58;
}
.learn-more::after {
    content: " →";
}

@media (max-width: 700px) {
    .columns {
        grid-template-columns: 1fr;
    }
    .reality-cell {
        border-right: none;
        border-bottom: 1px solid #2a2a3e;
    }
    .card-header h2 {
        font-size: 1.1rem;
    }
    .tpb-framework {
        padding: 20px;
    }
}
';

// Include header and nav
require 'includes/header.php';
require 'includes/nav.php';
?>

<main class="main" style="max-width: 1000px;">
    <h1>Our Aspirations</h1>
    <p class="subtitle">What we believe. What we face. How we fix it.</p>

    <?php include 'includes/tpb-framework.php'; ?>

    <?php foreach ($aspirations as $index => $row): ?>
    <div class="aspiration-card">
        <div class="card-header">
            <span class="card-number"><?= $index + 1 ?></span>
            <h2>"<?= htmlspecialchars($row['aspiration']) ?>"</h2>
        </div>
        <div class="card-body">
            <div class="columns">
                <div class="cell reality-cell">
                    <div class="cell-label">The Reality</div>
                    <div class="cell-content"><?= htmlspecialchars($row['reality']) ?></div>
                </div>
                <div class="cell solution-cell">
                    <div class="cell-label">The Solution</div>
                    <div class="cell-content"><?= htmlspecialchars($row['solution']) ?></div>
                </div>
            </div>
            <?php if (!empty($row['how_it_works'])): ?>
            <div class="how-section">
                <div class="cell-label">How It Works</div>
                <div class="cell-content">
                    <?= htmlspecialchars($row['how_it_works']) ?>
                    <?php if (!empty($row['learn_more_url']) && !empty($row['learn_more_label'])): ?>
                    <br>
                    <?php 
                    $isExternal = strpos($row['learn_more_url'], 'http') === 0;
                    $target = $isExternal ? ' target="_blank"' : '';
                    ?>
                    <a href="<?= htmlspecialchars($row['learn_more_url']) ?>" class="learn-more"<?= $target ?>><?= htmlspecialchars($row['learn_more_label']) ?></a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</main>

<?php require 'includes/footer.php'; ?>
