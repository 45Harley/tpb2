<?php
$c = require dirname(__DIR__, 2) . '/config.php';
$p = new PDO('mysql:host='.$c['host'].';dbname=sandge5_tpb2', $c['username'], $c['password']);
$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$r = $p->query("SELECT tag_id, tag_key FROM threat_tags");
$tags = [];
while($row = $r->fetch(PDO::FETCH_ASSOC)) $tags[$row['tag_key']] = $row['tag_id'];

$map = [
    275 => ['military', 'rule_of_law', 'corruption'],
    276 => ['rule_of_law', 'economic'],
    277 => ['rule_of_law', 'civil_rights', 'corruption'],
    278 => ['rule_of_law', 'immigration', 'corruption'],
];

$stmt = $p->prepare('INSERT IGNORE INTO threat_tag_map (threat_id, tag_id) VALUES (?, ?)');
$count = 0;
foreach ($map as $tid => $tagKeys) {
    foreach ($tagKeys as $key) {
        if (isset($tags[$key])) {
            $stmt->execute([$tid, $tags[$key]]);
            $count++;
        } else {
            echo "Warning: tag '$key' not found\n";
        }
    }
}
echo "Tagged $count entries\n";
