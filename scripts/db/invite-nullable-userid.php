<?php
$c = require __DIR__ . '/../../config.php';
$p = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);

// Drop FK constraint on user_id (name may vary)
$r = $p->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = '{$c['database']}' AND TABLE_NAME = 'group_invites' AND COLUMN_NAME = 'user_id' AND REFERENCED_TABLE_NAME IS NOT NULL");
$fk = $r->fetch();
if ($fk) {
    $p->exec("ALTER TABLE group_invites DROP FOREIGN KEY " . $fk['CONSTRAINT_NAME']);
    echo "Dropped FK: " . $fk['CONSTRAINT_NAME'] . "\n";
}

// Make user_id nullable
$p->exec("ALTER TABLE group_invites MODIFY COLUMN user_id INT NULL");
echo "Made user_id nullable\n";

// Re-add FK (now allows NULL)
$p->exec("ALTER TABLE group_invites ADD CONSTRAINT fk_invite_user FOREIGN KEY (user_id) REFERENCES users(user_id)");
echo "Re-added FK (nullable)\n";

// Verify
$r = $p->query('DESCRIBE group_invites');
while($row=$r->fetch(PDO::FETCH_ASSOC)) echo implode(' | ', $row).PHP_EOL;
