<?php
$c = require __DIR__ . '/../../config.php';
$p = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
$p->exec("ALTER TABLE idea_group_members ADD COLUMN status VARCHAR(10) NOT NULL DEFAULT 'active' AFTER role");
echo "Added status column\n";
$r = $p->query('DESCRIBE idea_group_members');
while($row=$r->fetch(PDO::FETCH_ASSOC)) echo implode(' | ', $row).PHP_EOL;
