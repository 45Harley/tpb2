<?php
// Migration: Standard Group Templates + Town Department Map
// Run: /usr/local/bin/php /path/to/run-standard-templates.php

$c = require __DIR__ . '/../../config.php';
$p = new PDO('mysql:host='.$c['host'].';dbname=sandge5_tpb2', $c['username'], $c['password']);
$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 1. Create template table
$p->exec("
    CREATE TABLE IF NOT EXISTS standard_group_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        sic_codes VARCHAR(50) NOT NULL,
        min_scope ENUM('town','state','national') NOT NULL DEFAULT 'town',
        sort_order INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
echo "1. Created standard_group_templates\n";

// 2. Create department mapping table
$p->exec("
    CREATE TABLE IF NOT EXISTS town_department_map (
        id INT AUTO_INCREMENT PRIMARY KEY,
        town_id INT NOT NULL,
        template_id INT NOT NULL,
        local_name VARCHAR(200) NOT NULL,
        contact_url VARCHAR(500) DEFAULT NULL,
        UNIQUE KEY uq_town_template_name (town_id, template_id, local_name),
        KEY idx_town (town_id),
        KEY idx_template (template_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
echo "2. Created town_department_map\n";

// 3. Seed 22 templates (idempotent — skip if already populated)
$check = $p->query("SELECT COUNT(*) FROM standard_group_templates")->fetchColumn();
if ($check == 0) {
    $ins = $p->prepare("INSERT INTO standard_group_templates (name, sic_codes, min_scope, sort_order) VALUES (?, ?, ?, ?)");
    $templates = [
        // Town level (13)
        ['Police & Public Safety',     '9221,9229',            'town', 1],
        ['Fire Protection',            '9224',                 'town', 2],
        ['Courts & Legal',             '9211,9222',            'town', 3],
        ['Schools & Education',        '9411',                 'town', 4],
        ['Public Health',              '9431',                 'town', 5],
        ['Social Services',            '9441',                 'town', 6],
        ['Roads & Transportation',     '9621',                 'town', 7],
        ['Water, Sewer & Waste',       '9511',                 'town', 8],
        ['Parks, Land & Conservation', '9512',                 'town', 9],
        ['Housing',                    '9531',                 'town', 10],
        ['Zoning & Planning',          '9532',                 'town', 11],
        ['Budget & Taxes',             '9311',                 'town', 12],
        ['General Government',         '9111,9121,9131,9199',  'town', 13],
        // State adds (5)
        ['Utilities Regulation',       '9631',                 'state', 14],
        ['Agriculture',                '9641',                 'state', 15],
        ['Commercial Licensing',       '9651',                 'state', 16],
        ["Veterans' Affairs",          '9451',                 'state', 17],
        ['Corrections',                '9223',                 'state', 18],
        // National adds (4)
        ['National Security',          '9711',                 'national', 19],
        ['International Affairs',      '9721',                 'national', 20],
        ['Space, Research & Technology','9661',                 'national', 21],
        ['Economic Programs',          '9611',                 'national', 22],
    ];
    foreach ($templates as $t) {
        $ins->execute($t);
    }
    echo "3. Seeded " . count($templates) . " templates\n";
} else {
    echo "3. Templates already exist ($check rows), skipping\n";
}

// 4. Delete old flat standard groups (confirmed empty)
$deleted = $p->exec("DELETE FROM idea_groups WHERE is_standard = 1");
echo "4. Deleted $deleted old standard groups\n";

// 5. Add template_id column to idea_groups (if not exists)
$cols = $p->query("SHOW COLUMNS FROM idea_groups LIKE 'template_id'")->fetchAll();
if (empty($cols)) {
    $p->exec("ALTER TABLE idea_groups ADD COLUMN template_id INT DEFAULT NULL AFTER is_standard");
    $p->exec("ALTER TABLE idea_groups ADD INDEX idx_template_id (template_id)");
    echo "5. Added template_id column to idea_groups\n";
} else {
    echo "5. template_id column already exists, skipping\n";
}

// 6. Seed Putnam department mappings (town_id = 119)
$check = $p->query("SELECT COUNT(*) FROM town_department_map WHERE town_id = 119")->fetchColumn();
if ($check == 0) {
    // Look up template IDs
    $tpl = [];
    $r = $p->query("SELECT id, name FROM standard_group_templates");
    while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
        $tpl[$row['name']] = $row['id'];
    }

    $ins = $p->prepare("INSERT INTO town_department_map (town_id, template_id, local_name, contact_url) VALUES (?, ?, ?, ?)");
    $putnam = [
        [$tpl['General Government'],         'Mayor & Board of Selectmen',   'https://www.putnamct.us/mayor'],
        [$tpl['General Government'],         'Town Meeting',                 null],
        [$tpl['General Government'],         'Putnam Arts Council',          null],
        [$tpl['Budget & Taxes'],             'Board of Finance',             null],
        [$tpl['Budget & Taxes'],             'Treasurer',                    null],
        [$tpl['Budget & Taxes'],             'Tax Collector',                null],
        [$tpl['Budget & Taxes'],             'Assessor',                     null],
        [$tpl['Budget & Taxes'],             'Board of Tax Review',          null],
        [$tpl['Budget & Taxes'],             'Pension Committee',            null],
        [$tpl['Schools & Education'],        'Board of Education',           null],
        [$tpl['Schools & Education'],        'Library Board of Trustees',    null],
        [$tpl['Zoning & Planning'],          'Planning & Zoning Commission', null],
        [$tpl['Police & Public Safety'],     'Putnam Police Department',     'https://www.putnampolice.com/'],
        [$tpl['Fire Protection'],            'Fire Department',              null],
        [$tpl['Parks, Land & Conservation'], 'Recreation Commission',        null],
        [$tpl['Parks, Land & Conservation'], 'Trails Committee',             null],
        [$tpl['Housing'],                    'Redevelopment Agency',          null],
        [$tpl["Veterans' Affairs"],          'Veterans Advisory Committee',   null],
    ];
    foreach ($putnam as $dept) {
        $ins->execute([119, $dept[0], $dept[1], $dept[2]]);
    }
    echo "6. Seeded " . count($putnam) . " Putnam department mappings\n";
} else {
    echo "6. Putnam mappings already exist ($check rows), skipping\n";
}

// Summary
echo "\n--- Summary ---\n";
$r = $p->query("SELECT COUNT(*) FROM standard_group_templates")->fetchColumn();
echo "Templates: $r\n";
$r = $p->query("SELECT COUNT(*) FROM town_department_map WHERE town_id = 119")->fetchColumn();
echo "Putnam departments: $r\n";
$r = $p->query("SELECT COUNT(*) FROM idea_groups WHERE is_standard = 1")->fetchColumn();
echo "Standard groups in idea_groups: $r (will be recreated by auto_create)\n";
