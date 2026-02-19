<?php
// Seed department mappings for Putnam (town), CT (state), and USA (national)
// Run: /usr/local/bin/php /path/to/seed-all-departments.php

$c = require __DIR__ . '/../../config.php';
$p = new PDO('mysql:host='.$c['host'].';dbname=sandge5_tpb2', $c['username'], $c['password']);
$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 1. Schema: add state_id column, make town_id nullable, rename conceptually to support all scopes
$cols = $p->query("SHOW COLUMNS FROM town_department_map LIKE 'state_id'")->fetchAll();
if (empty($cols)) {
    $p->exec("ALTER TABLE town_department_map ADD COLUMN state_id INT DEFAULT NULL AFTER town_id");
    $p->exec("ALTER TABLE town_department_map MODIFY town_id INT DEFAULT NULL");
    $p->exec("ALTER TABLE town_department_map ADD INDEX idx_state (state_id)");
    // Drop old unique key and add new one that includes state_id
    try {
        $p->exec("ALTER TABLE town_department_map DROP INDEX uq_town_template_name");
    } catch (Exception $e) { /* might not exist */ }
    $p->exec("ALTER TABLE town_department_map ADD UNIQUE KEY uq_scope_template_name (town_id, state_id, template_id, local_name)");
    echo "1. Added state_id column to town_department_map\n";
} else {
    echo "1. state_id column already exists, skipping\n";
}

// Look up template IDs
$tpl = [];
$r = $p->query("SELECT id, name FROM standard_group_templates");
while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
    $tpl[$row['name']] = $row['id'];
}

$ins = $p->prepare("INSERT IGNORE INTO town_department_map (town_id, state_id, template_id, local_name, contact_url) VALUES (?, ?, ?, ?, ?)");

// 2. CT State agencies (state_id=7, town_id=NULL)
$ctCount = $p->query("SELECT COUNT(*) FROM town_department_map WHERE state_id = 7 AND town_id IS NULL")->fetchColumn();
if ($ctCount == 0) {
    $ct = [
        // Police & Public Safety
        [$tpl['Police & Public Safety'], 'Department of Emergency Services and Public Protection (DESPP)', 'https://portal.ct.gov/despp'],
        [$tpl['Police & Public Safety'], 'Connecticut State Police', 'https://portal.ct.gov/despp/division-of-state-police/home'],
        [$tpl['Police & Public Safety'], 'Division of Emergency Management and Homeland Security', 'https://portal.ct.gov/demhs'],
        [$tpl['Police & Public Safety'], 'Military Department (National Guard)', 'https://portal.ct.gov/mil'],
        // Fire Protection
        [$tpl['Fire Protection'], 'Office of State Fire Marshal', 'https://portal.ct.gov/das/office-of-state-fire-marshal/office-of-state-fire-marshal'],
        [$tpl['Fire Protection'], 'Division of Fire Services Administration', 'https://portal.ct.gov/despp/services/division-of-fire-services-administration'],
        // Courts & Legal
        [$tpl['Courts & Legal'], 'Connecticut Judicial Branch', 'https://jud.ct.gov/'],
        [$tpl['Courts & Legal'], 'Office of the Attorney General', 'https://portal.ct.gov/ag'],
        [$tpl['Courts & Legal'], 'Division of Criminal Justice', 'https://portal.ct.gov/dcj'],
        [$tpl['Courts & Legal'], 'Division of Public Defender Services', 'https://portal.ct.gov/ocpd'],
        [$tpl['Courts & Legal'], 'Commission on Human Rights and Opportunities', 'https://portal.ct.gov/chro'],
        [$tpl['Courts & Legal'], 'Freedom of Information Commission', 'https://portal.ct.gov/foi'],
        // Schools & Education
        [$tpl['Schools & Education'], 'State Department of Education', 'https://portal.ct.gov/sde'],
        [$tpl['Schools & Education'], 'Office of Early Childhood', 'https://portal.ct.gov/oec'],
        [$tpl['Schools & Education'], 'Office of Higher Education', 'https://portal.ct.gov/ohe'],
        // Public Health
        [$tpl['Public Health'], 'Department of Public Health', 'https://portal.ct.gov/dph'],
        [$tpl['Public Health'], 'Office of Health Strategy', 'https://portal.ct.gov/ohs'],
        [$tpl['Public Health'], 'Department of Mental Health and Addiction Services', 'https://portal.ct.gov/dmhas'],
        // Social Services
        [$tpl['Social Services'], 'Department of Social Services', 'https://portal.ct.gov/dss'],
        [$tpl['Social Services'], 'Department of Children and Families', 'https://portal.ct.gov/dcf'],
        [$tpl['Social Services'], 'Department of Aging and Disability Services', 'https://portal.ct.gov/ads'],
        [$tpl['Social Services'], 'Department of Developmental Services', 'https://portal.ct.gov/dds'],
        // Roads & Transportation
        [$tpl['Roads & Transportation'], 'Department of Transportation', 'https://portal.ct.gov/dot'],
        [$tpl['Roads & Transportation'], 'Department of Motor Vehicles', 'https://portal.ct.gov/dmv'],
        // Water, Sewer & Waste
        [$tpl['Water, Sewer & Waste'], 'Dept. of Energy and Environmental Protection (DEEP)', 'https://portal.ct.gov/deep'],
        // Parks, Land & Conservation
        [$tpl['Parks, Land & Conservation'], 'DEEP — State Parks & Forestry', 'https://portal.ct.gov/deep'],
        [$tpl['Parks, Land & Conservation'], 'Council on Environmental Quality', 'https://portal.ct.gov/ceq'],
        // Housing
        [$tpl['Housing'], 'Department of Housing', 'https://portal.ct.gov/doh'],
        [$tpl['Housing'], 'Dept. of Economic and Community Development', 'https://portal.ct.gov/decd'],
        // Zoning & Planning
        [$tpl['Zoning & Planning'], 'Office of Policy and Management — Planning', 'https://portal.ct.gov/opm'],
        [$tpl['Zoning & Planning'], 'Connecticut Siting Council', 'https://portal.ct.gov/csc'],
        // Budget & Taxes
        [$tpl['Budget & Taxes'], 'Office of Policy and Management — Budget', 'https://portal.ct.gov/opm'],
        [$tpl['Budget & Taxes'], 'Department of Revenue Services', 'https://portal.ct.gov/drs'],
        [$tpl['Budget & Taxes'], 'Office of the State Comptroller', 'https://osc.ct.gov/'],
        [$tpl['Budget & Taxes'], 'Office of the State Treasurer', 'https://portal.ct.gov/ott'],
        // General Government
        [$tpl['General Government'], 'Office of the Governor', 'https://portal.ct.gov/governor'],
        [$tpl['General Government'], 'Office of the Secretary of the State', 'https://portal.ct.gov/sots'],
        [$tpl['General Government'], 'Department of Administrative Services', 'https://portal.ct.gov/das'],
        [$tpl['General Government'], 'State Elections Enforcement Commission', 'https://portal.ct.gov/seec'],
        // Utilities Regulation
        [$tpl['Utilities Regulation'], 'Public Utilities Regulatory Authority (PURA)', 'https://portal.ct.gov/pura'],
        [$tpl['Utilities Regulation'], 'Office of Consumer Counsel', 'https://portal.ct.gov/occ'],
        // Agriculture
        [$tpl['Agriculture'], 'Department of Agriculture', 'https://portal.ct.gov/doag'],
        [$tpl['Agriculture'], 'CT Agricultural Experiment Station', 'https://portal.ct.gov/caes'],
        // Commercial Licensing
        [$tpl['Commercial Licensing'], 'Department of Consumer Protection', 'https://portal.ct.gov/dcp'],
        [$tpl['Commercial Licensing'], 'Department of Banking', 'https://portal.ct.gov/dob'],
        [$tpl['Commercial Licensing'], 'Connecticut Insurance Department', 'https://portal.ct.gov/cid'],
        [$tpl['Commercial Licensing'], 'Department of Labor', 'https://portal.ct.gov/dol'],
        // Veterans' Affairs
        [$tpl["Veterans' Affairs"], 'Department of Veterans Affairs', 'https://portal.ct.gov/dva'],
        // Corrections
        [$tpl['Corrections'], 'Department of Correction', 'https://portal.ct.gov/doc'],
        [$tpl['Corrections'], 'Board of Pardons and Paroles', 'https://portal.ct.gov/bopp'],
    ];
    foreach ($ct as $dept) {
        $ins->execute([null, 7, $dept[0], $dept[1], $dept[2]]);
    }
    echo "2. Seeded " . count($ct) . " CT state agency mappings\n";
} else {
    echo "2. CT mappings already exist ($ctCount rows), skipping\n";
}

// 3. Create national standard groups first
require __DIR__ . '/../../talk/api.php';
$result = handleAutoCreateStandardGroups($p, ['scope' => 'federal'], null);
echo "3. National standard groups: created={$result['created']}\n";

// 4. Federal agencies (state_id=NULL, town_id=NULL)
$fedCount = $p->query("SELECT COUNT(*) FROM town_department_map WHERE state_id IS NULL AND town_id IS NULL")->fetchColumn();
if ($fedCount == 0) {
    $fed = [
        // Police & Public Safety
        [$tpl['Police & Public Safety'], 'Department of Justice (DOJ)', 'https://www.justice.gov'],
        [$tpl['Police & Public Safety'], 'Federal Bureau of Investigation (FBI)', 'https://www.fbi.gov'],
        [$tpl['Police & Public Safety'], 'Department of Homeland Security (DHS)', 'https://www.dhs.gov'],
        [$tpl['Police & Public Safety'], 'Bureau of Alcohol, Tobacco, Firearms and Explosives (ATF)', 'https://www.atf.gov'],
        // Fire Protection
        [$tpl['Fire Protection'], 'Federal Emergency Management Agency (FEMA)', 'https://www.fema.gov'],
        [$tpl['Fire Protection'], 'U.S. Fire Administration (USFA)', 'https://www.usfa.fema.gov'],
        // Courts & Legal
        [$tpl['Courts & Legal'], 'U.S. Courts (Federal Judiciary)', 'https://www.uscourts.gov'],
        [$tpl['Courts & Legal'], 'Supreme Court of the United States', 'https://www.supremecourt.gov'],
        // Schools & Education
        [$tpl['Schools & Education'], 'Department of Education', 'https://www.ed.gov'],
        // Public Health
        [$tpl['Public Health'], 'Department of Health and Human Services (HHS)', 'https://www.hhs.gov'],
        [$tpl['Public Health'], 'Centers for Disease Control and Prevention (CDC)', 'https://www.cdc.gov'],
        [$tpl['Public Health'], 'National Institutes of Health (NIH)', 'https://www.nih.gov'],
        [$tpl['Public Health'], 'Food and Drug Administration (FDA)', 'https://www.fda.gov'],
        // Social Services
        [$tpl['Social Services'], 'Social Security Administration (SSA)', 'https://www.ssa.gov'],
        [$tpl['Social Services'], 'Department of Labor (DOL)', 'https://www.dol.gov'],
        [$tpl['Social Services'], 'AmeriCorps', 'https://www.americorps.gov'],
        // Roads & Transportation
        [$tpl['Roads & Transportation'], 'Department of Transportation (DOT)', 'https://www.transportation.gov'],
        [$tpl['Roads & Transportation'], 'Federal Highway Administration (FHWA)', 'https://www.fhwa.dot.gov'],
        [$tpl['Roads & Transportation'], 'Federal Aviation Administration (FAA)', 'https://www.faa.gov'],
        // Water, Sewer & Waste
        [$tpl['Water, Sewer & Waste'], 'Environmental Protection Agency (EPA)', 'https://www.epa.gov'],
        [$tpl['Water, Sewer & Waste'], 'Army Corps of Engineers', 'https://www.usace.army.mil'],
        // Parks, Land & Conservation
        [$tpl['Parks, Land & Conservation'], 'National Park Service (NPS)', 'https://www.nps.gov'],
        [$tpl['Parks, Land & Conservation'], 'U.S. Fish and Wildlife Service', 'https://www.fws.gov'],
        [$tpl['Parks, Land & Conservation'], 'U.S. Forest Service', 'https://www.fs.usda.gov'],
        [$tpl['Parks, Land & Conservation'], 'Bureau of Land Management', 'https://www.blm.gov'],
        // Housing
        [$tpl['Housing'], 'Department of Housing and Urban Development (HUD)', 'https://www.hud.gov'],
        // Zoning & Planning
        [$tpl['Zoning & Planning'], 'HUD — Community Planning & Development', 'https://www.hud.gov'],
        // Budget & Taxes
        [$tpl['Budget & Taxes'], 'Department of the Treasury', 'https://www.treasury.gov'],
        [$tpl['Budget & Taxes'], 'Internal Revenue Service (IRS)', 'https://www.irs.gov'],
        [$tpl['Budget & Taxes'], 'Office of Management and Budget (OMB)', 'https://www.whitehouse.gov/omb'],
        [$tpl['Budget & Taxes'], 'Government Accountability Office (GAO)', 'https://www.gao.gov'],
        // General Government
        [$tpl['General Government'], 'The White House', 'https://www.whitehouse.gov'],
        [$tpl['General Government'], 'U.S. Senate', 'https://www.senate.gov'],
        [$tpl['General Government'], 'U.S. House of Representatives', 'https://www.house.gov'],
        [$tpl['General Government'], 'General Services Administration (GSA)', 'https://www.gsa.gov'],
        [$tpl['General Government'], 'National Archives (NARA)', 'https://www.archives.gov'],
        // Utilities Regulation
        [$tpl['Utilities Regulation'], 'Federal Energy Regulatory Commission (FERC)', 'https://www.ferc.gov'],
        [$tpl['Utilities Regulation'], 'Department of Energy (DOE)', 'https://www.energy.gov'],
        [$tpl['Utilities Regulation'], 'Federal Communications Commission (FCC)', 'https://www.fcc.gov'],
        [$tpl['Utilities Regulation'], 'Nuclear Regulatory Commission (NRC)', 'https://www.nrc.gov'],
        // Agriculture
        [$tpl['Agriculture'], 'Department of Agriculture (USDA)', 'https://www.usda.gov'],
        // Commercial Licensing
        [$tpl['Commercial Licensing'], 'Federal Trade Commission (FTC)', 'https://www.ftc.gov'],
        [$tpl['Commercial Licensing'], 'Securities and Exchange Commission (SEC)', 'https://www.sec.gov'],
        [$tpl['Commercial Licensing'], 'Small Business Administration (SBA)', 'https://www.sba.gov'],
        [$tpl['Commercial Licensing'], 'Consumer Financial Protection Bureau (CFPB)', 'https://www.consumerfinance.gov'],
        // Veterans' Affairs
        [$tpl["Veterans' Affairs"], 'Department of Veterans Affairs (VA)', 'https://www.va.gov'],
        // Corrections
        [$tpl['Corrections'], 'Federal Bureau of Prisons (BOP)', 'https://www.bop.gov'],
        // National Security
        [$tpl['National Security'], 'Department of Defense (DOD)', 'https://www.defense.gov'],
        [$tpl['National Security'], 'Central Intelligence Agency (CIA)', 'https://www.cia.gov'],
        [$tpl['National Security'], 'National Security Agency (NSA)', 'https://www.nsa.gov'],
        // International Affairs
        [$tpl['International Affairs'], 'Department of State', 'https://www.state.gov'],
        [$tpl['International Affairs'], 'U.S. Agency for International Development (USAID)', 'https://www.usaid.gov'],
        [$tpl['International Affairs'], 'Peace Corps', 'https://www.peacecorps.gov'],
        // Space, Research & Technology
        [$tpl['Space, Research & Technology'], 'NASA', 'https://www.nasa.gov'],
        [$tpl['Space, Research & Technology'], 'National Science Foundation (NSF)', 'https://www.nsf.gov'],
        [$tpl['Space, Research & Technology'], 'NOAA', 'https://www.noaa.gov'],
        // Economic Programs
        [$tpl['Economic Programs'], 'Federal Reserve System', 'https://www.federalreserve.gov'],
        [$tpl['Economic Programs'], 'Department of Commerce', 'https://www.commerce.gov'],
        [$tpl['Economic Programs'], 'Bureau of Economic Analysis (BEA)', 'https://www.bea.gov'],
        [$tpl['Economic Programs'], 'Bureau of Labor Statistics (BLS)', 'https://www.bls.gov'],
    ];
    foreach ($fed as $dept) {
        $ins->execute([null, null, $dept[0], $dept[1], $dept[2]]);
    }
    echo "4. Seeded " . count($fed) . " federal agency mappings\n";
} else {
    echo "4. Federal mappings already exist ($fedCount rows), skipping\n";
}

// Summary
echo "\n--- Summary ---\n";
$r = $p->query("SELECT
    SUM(CASE WHEN town_id IS NOT NULL THEN 1 ELSE 0 END) as town_depts,
    SUM(CASE WHEN state_id IS NOT NULL AND town_id IS NULL THEN 1 ELSE 0 END) as state_depts,
    SUM(CASE WHEN state_id IS NULL AND town_id IS NULL THEN 1 ELSE 0 END) as federal_depts
FROM town_department_map")->fetch();
echo "Town (Putnam): {$r['town_depts']} departments\n";
echo "State (CT): {$r['state_depts']} agencies\n";
echo "National (USA): {$r['federal_depts']} agencies\n";
$r = $p->query("SELECT scope, COUNT(*) as cnt FROM idea_groups WHERE is_standard = 1 GROUP BY scope")->fetchAll();
foreach ($r as $row) echo "Standard groups ({$row['scope']}): {$row['cnt']}\n";
