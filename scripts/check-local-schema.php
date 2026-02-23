<?php
$c = require __DIR__ . '/../config.php';
$p = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);

echo "=== idea_log columns ===\n";
$r = $p->query('SHOW COLUMNS FROM idea_log');
while($row=$r->fetch(PDO::FETCH_ASSOC)) echo $row['Field'].' | '.$row['Type'].' | '.$row['Null'].' | '.$row['Key'].PHP_EOL;

echo "\n=== idea_log indexes ===\n";
$r = $p->query('SHOW INDEX FROM idea_log');
while($row=$r->fetch(PDO::FETCH_ASSOC)) echo $row['Key_name'].' | '.$row['Column_name'].PHP_EOL;

echo "\n=== idea_links columns ===\n";
$r = $p->query('SHOW COLUMNS FROM idea_links');
while($row=$r->fetch(PDO::FETCH_ASSOC)) echo $row['Field'].' | '.$row['Type'].' | '.$row['Null'].' | '.$row['Key'].PHP_EOL;

echo "\n=== tables list ===\n";
$r = $p->query('SHOW TABLES');
while($row=$r->fetch(PDO::FETCH_NUM)) echo $row[0].PHP_EOL;
