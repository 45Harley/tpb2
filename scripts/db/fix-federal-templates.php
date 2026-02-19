<?php
// Fix: Give federal groups unique template_ids so department mappings are distinct
// Problem 1: Defense & Intelligence share template_id=19 → same agencies shown for both
// Problem 2: Environment & Emergency have NULL template_id → no agencies shown

$c = require '/home/sandge5/tpb2.sandgems.net/config.php';
$p = new PDO('mysql:host='.$c['host'].';dbname=sandge5_tpb2', $c['username'], $c['password']);
$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Step 1: Add new template rows for federal-specific categories
$newTemplates = [
    ['Defense & Military',             '9711',  'national', 23],
    ['Intelligence & Homeland Security','9711', 'national', 24],
    ['Environment & Energy',           '9511,9631', 'national', 25],
    ['Emergency Management',           '9224',  'national', 26],
    ['Justice & Law Enforcement',      '9221,9223', 'national', 27],
    ['Labor & Social Services',        '9441',  'national', 28],
    ['Commerce & Regulation',          '9651',  'national', 29],
    ['Treasury & Finance',             '9311,9611', 'national', 30],
    ['Congress & Executive',           '9111,9121', 'national', 31],
    ['Public Lands & Conservation',    '9512',  'national', 32],
];

$ins = $p->prepare("INSERT INTO standard_group_templates (name, sic_codes, min_scope, sort_order) VALUES (?, ?, ?, ?)");
$check = $p->prepare("SELECT id FROM standard_group_templates WHERE name = ? AND min_scope = 'national'");

$templateMap = []; // name => new template id
foreach ($newTemplates as $t) {
    $check->execute([$t[0]]);
    if ($check->fetch()) {
        echo "  Template '{$t[0]}' already exists, skipping\n";
        // Get its ID
        $check->execute([$t[0]]);
        $row = $check->fetch();
        $templateMap[$t[0]] = (int)$row['id'];
        continue;
    }
    $ins->execute([$t[0], $t[1], $t[2], $t[3]]);
    $templateMap[$t[0]] = (int)$p->lastInsertId();
    echo "  Created template '{$t[0]}' (id={$templateMap[$t[0]]})\n";
}
echo "1. Created " . count($templateMap) . " federal-specific templates\n";

// Step 2: Update federal groups to use new template_ids
// Groups that already have unique templates (Courts=3, Education=4, etc.) stay as-is
// Groups that share or have NULL get updated
$updates = [
    'Defense & Military'             => $templateMap['Defense & Military'],
    'Intelligence & Homeland Security'=> $templateMap['Intelligence & Homeland Security'],
    'Environment & Energy'           => $templateMap['Environment & Energy'],
    'Emergency Management'           => $templateMap['Emergency Management'],
    'Justice & Law Enforcement'      => $templateMap['Justice & Law Enforcement'],
    'Labor & Social Services'        => $templateMap['Labor & Social Services'],
    'Commerce & Regulation'          => $templateMap['Commerce & Regulation'],
    'Treasury & Finance'             => $templateMap['Treasury & Finance'],
    'Congress & Executive'           => $templateMap['Congress & Executive'],
    'Public Lands & Conservation'    => $templateMap['Public Lands & Conservation'],
];

$upd = $p->prepare("UPDATE idea_groups SET template_id = ? WHERE name = ? AND scope = 'federal' AND is_standard = 1");
foreach ($updates as $name => $tplId) {
    $upd->execute([$tplId, $name]);
}
echo "2. Updated " . count($updates) . " federal groups with unique template_ids\n";

// Step 3: Delete old federal department mappings and re-seed with correct template_ids
$p->exec("DELETE FROM town_department_map WHERE state_id IS NULL AND town_id IS NULL");
echo "3. Cleared old federal department mappings\n";

// Step 4: Re-seed with correct template_ids
// Get all federal group template_ids
$fedGroups = [];
$r = $p->query("SELECT name, template_id FROM idea_groups WHERE scope = 'federal' AND is_standard = 1");
while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
    $fedGroups[$row['name']] = (int)$row['template_id'];
}

$agencies = [
    // Defense & Military
    ['Defense & Military', 'Department of Defense (DOD)', 'https://www.defense.gov'],
    ['Defense & Military', 'U.S. Army', 'https://www.army.mil'],
    ['Defense & Military', 'U.S. Navy', 'https://www.navy.mil'],
    ['Defense & Military', 'U.S. Air Force', 'https://www.af.mil'],
    ['Defense & Military', 'U.S. Marine Corps', 'https://www.marines.mil'],
    ['Defense & Military', 'U.S. Space Force', 'https://www.spaceforce.mil'],
    ['Defense & Military', 'U.S. Coast Guard', 'https://www.uscg.mil'],
    ['Defense & Military', 'National Guard Bureau', 'https://www.nationalguard.mil'],

    // Justice & Law Enforcement
    ['Justice & Law Enforcement', 'Department of Justice (DOJ)', 'https://www.justice.gov'],
    ['Justice & Law Enforcement', 'Federal Bureau of Investigation (FBI)', 'https://www.fbi.gov'],
    ['Justice & Law Enforcement', 'Bureau of Alcohol, Tobacco, Firearms and Explosives (ATF)', 'https://www.atf.gov'],
    ['Justice & Law Enforcement', 'Drug Enforcement Administration (DEA)', 'https://www.dea.gov'],
    ['Justice & Law Enforcement', 'U.S. Marshals Service', 'https://www.usmarshals.gov'],
    ['Justice & Law Enforcement', 'Federal Bureau of Prisons (BOP)', 'https://www.bop.gov'],

    // Federal Courts
    ['Federal Courts', 'Supreme Court of the United States', 'https://www.supremecourt.gov'],
    ['Federal Courts', 'U.S. Courts (Federal Judiciary)', 'https://www.uscourts.gov'],

    // Health & Human Services
    ['Health & Human Services', 'Department of Health and Human Services (HHS)', 'https://www.hhs.gov'],
    ['Health & Human Services', 'Centers for Disease Control and Prevention (CDC)', 'https://www.cdc.gov'],
    ['Health & Human Services', 'Food and Drug Administration (FDA)', 'https://www.fda.gov'],
    ['Health & Human Services', 'National Institutes of Health (NIH)', 'https://www.nih.gov'],
    ['Health & Human Services', 'Centers for Medicare & Medicaid Services (CMS)', 'https://www.cms.gov'],

    // Treasury & Finance
    ['Treasury & Finance', 'Department of the Treasury', 'https://www.treasury.gov'],
    ['Treasury & Finance', 'Internal Revenue Service (IRS)', 'https://www.irs.gov'],
    ['Treasury & Finance', 'Government Accountability Office (GAO)', 'https://www.gao.gov'],
    ['Treasury & Finance', 'Office of Management and Budget (OMB)', 'https://www.whitehouse.gov/omb'],
    ['Treasury & Finance', 'Federal Reserve System', 'https://www.federalreserve.gov'],
    ['Treasury & Finance', 'Congressional Budget Office (CBO)', 'https://www.cbo.gov'],

    // Education
    ['Education', 'Department of Education', 'https://www.ed.gov'],

    // Transportation
    ['Transportation', 'Department of Transportation (DOT)', 'https://www.transportation.gov'],
    ['Transportation', 'Federal Aviation Administration (FAA)', 'https://www.faa.gov'],
    ['Transportation', 'Federal Highway Administration (FHWA)', 'https://www.fhwa.dot.gov'],
    ['Transportation', 'National Highway Traffic Safety Administration (NHTSA)', 'https://www.nhtsa.gov'],
    ['Transportation', 'Amtrak', 'https://www.amtrak.com'],

    // Environment & Energy
    ['Environment & Energy', 'Environmental Protection Agency (EPA)', 'https://www.epa.gov'],
    ['Environment & Energy', 'Department of Energy (DOE)', 'https://www.energy.gov'],
    ['Environment & Energy', 'Nuclear Regulatory Commission (NRC)', 'https://www.nrc.gov'],
    ['Environment & Energy', 'Federal Energy Regulatory Commission (FERC)', 'https://www.ferc.gov'],
    ['Environment & Energy', 'Army Corps of Engineers', 'https://www.usace.army.mil'],

    // Public Lands & Conservation
    ['Public Lands & Conservation', 'National Park Service (NPS)', 'https://www.nps.gov'],
    ['Public Lands & Conservation', 'Bureau of Land Management (BLM)', 'https://www.blm.gov'],
    ['Public Lands & Conservation', 'U.S. Forest Service', 'https://www.fs.usda.gov'],
    ['Public Lands & Conservation', 'U.S. Fish and Wildlife Service', 'https://www.fws.gov'],

    // Foreign Affairs
    ['Foreign Affairs', 'Department of State', 'https://www.state.gov'],
    ['Foreign Affairs', 'U.S. Agency for International Development (USAID)', 'https://www.usaid.gov'],
    ['Foreign Affairs', 'Peace Corps', 'https://www.peacecorps.gov'],

    // Intelligence & Homeland Security
    ['Intelligence & Homeland Security', 'Department of Homeland Security (DHS)', 'https://www.dhs.gov'],
    ['Intelligence & Homeland Security', 'Central Intelligence Agency (CIA)', 'https://www.cia.gov'],
    ['Intelligence & Homeland Security', 'National Security Agency (NSA)', 'https://www.nsa.gov'],
    ['Intelligence & Homeland Security', 'Office of the Director of National Intelligence', 'https://www.dni.gov'],

    // Labor & Social Services
    ['Labor & Social Services', 'Department of Labor (DOL)', 'https://www.dol.gov'],
    ['Labor & Social Services', 'Social Security Administration (SSA)', 'https://www.ssa.gov'],
    ['Labor & Social Services', 'AmeriCorps', 'https://www.americorps.gov'],

    // Commerce & Regulation
    ['Commerce & Regulation', 'Department of Commerce', 'https://www.commerce.gov'],
    ['Commerce & Regulation', 'Federal Trade Commission (FTC)', 'https://www.ftc.gov'],
    ['Commerce & Regulation', 'Securities and Exchange Commission (SEC)', 'https://www.sec.gov'],
    ['Commerce & Regulation', 'Small Business Administration (SBA)', 'https://www.sba.gov'],
    ['Commerce & Regulation', 'Consumer Financial Protection Bureau (CFPB)', 'https://www.consumerfinance.gov'],
    ['Commerce & Regulation', 'Federal Communications Commission (FCC)', 'https://www.fcc.gov'],
    ['Commerce & Regulation', 'Bureau of Economic Analysis (BEA)', 'https://www.bea.gov'],
    ['Commerce & Regulation', 'Bureau of Labor Statistics (BLS)', 'https://www.bls.gov'],

    // Housing & Urban Development
    ['Housing & Urban Development', 'Department of Housing and Urban Development (HUD)', 'https://www.hud.gov'],

    // Veterans Affairs
    ['Veterans Affairs', 'Department of Veterans Affairs (VA)', 'https://www.va.gov'],

    // Agriculture & Food
    ['Agriculture & Food', 'Department of Agriculture (USDA)', 'https://www.usda.gov'],

    // Science & Technology
    ['Science & Technology', 'NASA', 'https://www.nasa.gov'],
    ['Science & Technology', 'National Science Foundation (NSF)', 'https://www.nsf.gov'],
    ['Science & Technology', 'NOAA', 'https://www.noaa.gov'],

    // Emergency Management
    ['Emergency Management', 'Federal Emergency Management Agency (FEMA)', 'https://www.fema.gov'],
    ['Emergency Management', 'U.S. Fire Administration (USFA)', 'https://www.usfa.fema.gov'],

    // Congress & Executive
    ['Congress & Executive', 'The White House', 'https://www.whitehouse.gov'],
    ['Congress & Executive', 'U.S. Senate', 'https://www.senate.gov'],
    ['Congress & Executive', 'U.S. House of Representatives', 'https://www.house.gov'],
    ['Congress & Executive', 'General Services Administration (GSA)', 'https://www.gsa.gov'],
    ['Congress & Executive', 'National Archives (NARA)', 'https://www.archives.gov'],
];

$ins = $p->prepare("INSERT INTO town_department_map (town_id, state_id, template_id, local_name, contact_url) VALUES (NULL, NULL, ?, ?, ?)");
$mapped = 0;
foreach ($agencies as $a) {
    $tplId = $fedGroups[$a[0]] ?? null;
    if (!$tplId) {
        echo "  WARNING: No group found for '{$a[0]}'\n";
        continue;
    }
    $ins->execute([$tplId, $a[1], $a[2]]);
    $mapped++;
}
echo "4. Mapped $mapped agencies with correct template_ids\n";

// Summary
echo "\n--- Summary ---\n";
$r = $p->query("SELECT ig.name, ig.template_id, COUNT(tdm.id) as dept_count
    FROM idea_groups ig
    LEFT JOIN town_department_map tdm ON tdm.template_id = ig.template_id AND tdm.state_id IS NULL AND tdm.town_id IS NULL
    WHERE ig.scope = 'federal' AND ig.is_standard = 1
    GROUP BY ig.id ORDER BY ig.id");
while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
    echo "  tpl={$row['template_id']}  {$row['name']}  ({$row['dept_count']} agencies)\n";
}
