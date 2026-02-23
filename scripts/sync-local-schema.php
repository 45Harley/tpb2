<?php
$c = require __DIR__ . '/../config.php';
$p = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$migrations = [
    // 1. Add missing indexes on idea_log
    "ALTER TABLE idea_log ADD INDEX idx_idea_log_group_id (group_id)" => "idea_log: group_id index",
    "ALTER TABLE idea_log ADD INDEX idx_deleted_at (deleted_at)" => "idea_log: deleted_at index",

    // 2. Fix edit_count to NOT NULL DEFAULT 0
    "ALTER TABLE idea_log MODIFY edit_count INT(11) NOT NULL DEFAULT 0" => "idea_log: edit_count NOT NULL",

    // 3. Add FK for group_id (if idea_groups table exists)
    "ALTER TABLE idea_log ADD CONSTRAINT fk_idea_log_group FOREIGN KEY (group_id) REFERENCES idea_groups(id) ON DELETE SET NULL" => "idea_log: group_id FK",

    // 4. Create admin_actions table
    "CREATE TABLE IF NOT EXISTS admin_actions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_user_id INT NOT NULL,
        action_type VARCHAR(50) NOT NULL,
        target_user_id INT NULL,
        details TEXT NULL,
        ip_address VARCHAR(45) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_admin_user (admin_user_id),
        INDEX idx_action_type (action_type),
        INDEX idx_created (created_at)
    )" => "admin_actions table",

    // 5. Create civic_daily_stats table
    "CREATE TABLE IF NOT EXISTS civic_daily_stats (
        id INT AUTO_INCREMENT PRIMARY KEY,
        stat_date DATE NOT NULL,
        metric VARCHAR(50) NOT NULL,
        value INT NOT NULL DEFAULT 0,
        details JSON NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_date_metric (stat_date, metric)
    )" => "civic_daily_stats table",

    // 6. Create civic_skill_interests table
    "CREATE TABLE IF NOT EXISTS civic_skill_interests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        skill_set_id INT NOT NULL,
        interest_level TINYINT DEFAULT 3,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_skill (user_id, skill_set_id),
        INDEX idx_skill (skill_set_id)
    )" => "civic_skill_interests table",

    // 7. Create group_invites table
    "CREATE TABLE IF NOT EXISTS group_invites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_id INT NOT NULL,
        invited_by INT NOT NULL,
        invited_user_id INT NULL,
        invite_code VARCHAR(64) NULL,
        status ENUM('pending','accepted','declined','expired') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expires_at TIMESTAMP NULL,
        INDEX idx_group (group_id),
        INDEX idx_invited_user (invited_user_id),
        INDEX idx_code (invite_code)
    )" => "group_invites table",
];

$ok = 0;
$skip = 0;
$fail = 0;

foreach ($migrations as $sql => $label) {
    try {
        $p->exec($sql);
        echo "OK: $label\n";
        $ok++;
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'already exists') !== false) {
            echo "SKIP (already exists): $label\n";
            $skip++;
        } else {
            echo "FAIL: $label — " . $e->getMessage() . "\n";
            $fail++;
        }
    }
}

echo "\nDone: $ok applied, $skip skipped, $fail failed\n";
