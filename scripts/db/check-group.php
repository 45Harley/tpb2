<?php
$c = require __DIR__ . '/../../config.php';
$p = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
$r = $p->query("SELECT g.id as group_id, g.name, m.user_id, m.role, m.status, u.username, u.email, u.first_name, u.last_name FROM idea_group_members m JOIN users u ON m.user_id = u.user_id JOIN idea_groups g ON m.group_id = g.id WHERE g.name LIKE '%compound%' ORDER BY m.role DESC, m.user_id");
echo "group_id | group_name | user_id | role | status | username | email | first | last\n";
echo str_repeat('-', 100) . "\n";
while($row=$r->fetch(PDO::FETCH_ASSOC)) echo implode(' | ', $row).PHP_EOL;
