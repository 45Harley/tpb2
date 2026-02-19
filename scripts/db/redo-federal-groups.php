<?php
// Migration: Replace cascaded federal groups with proper federal categories
// The old approach copied 22 town/state templates — wrong fit for federal government
// This creates 18 properly-named federal groups that match how the US government works
//
// Run: scp to server, then /usr/local/bin/php /tmp/redo-federal-groups.php

$c = require __DIR__ . '/../../config.php';
$p = new PDO('mysql:host='.$c['host'].';dbname=sandge5_tpb2', $c['username'], $c['password']);
$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ── Step 1: Delete old federal groups and mappings ──

$deleted_groups = $p->exec("DELETE FROM idea_groups WHERE is_standard = 1 AND scope = 'federal'");
echo "1. Deleted $deleted_groups old federal standard groups\n";

$deleted_mappings = $p->exec("DELETE FROM town_department_map WHERE state_id IS NULL AND town_id IS NULL");
echo "2. Deleted $deleted_mappings old federal department mappings\n";

// ── Step 2: Create 18 proper federal groups ──
// These are NOT template-derived — they reflect actual federal government structure
// template_id = closest matching template (for loose linking), or NULL

$federal_groups = [
    // [name, description, template_id (closest match or NULL)]
    ['Defense & Military',           'U.S. Armed Forces, Pentagon, military branches, defense policy',                      19],
    ['Justice & Law Enforcement',    'Federal law enforcement, DOJ, FBI, DHS, federal prosecution',                          1],
    ['Federal Courts',               'Supreme Court, federal judiciary, circuit courts',                                      3],
    ['Health & Human Services',      'Public health, CDC, FDA, NIH, Medicare, Medicaid',                                     5],
    ['Treasury & Finance',           'Federal budget, taxes, IRS, monetary policy, Federal Reserve',                         12],
    ['Education',                    'Federal education policy, student aid, Dept of Education',                              4],
    ['Transportation',               'Federal highways, aviation, railroads, DOT',                                            7],
    ['Environment & Energy',         'EPA, DOE, nuclear regulation, clean energy, environmental protection',                 null],
    ['Public Lands & Conservation',  'National parks, forests, wildlife refuges, federal land management',                    9],
    ['Foreign Affairs',              'Diplomacy, State Department, USAID, Peace Corps, international relations',             20],
    ['Intelligence & Homeland Security', 'CIA, NSA, DHS intelligence, national security policy',                            19],
    ['Labor & Social Services',      'Workforce, Social Security, disability, AmeriCorps, labor rights',                      6],
    ['Commerce & Regulation',        'Trade, consumer protection, FTC, SEC, SBA, economic data',                            16],
    ['Housing & Urban Development',  'HUD, federal housing programs, community development',                                10],
    ['Veterans Affairs',             'VA healthcare, GI Bill, veteran benefits, military transition',                        17],
    ['Agriculture & Food',           'USDA, food safety, farm programs, rural development',                                 15],
    ['Science & Technology',         'NASA, NSF, NOAA, federal R&D, space exploration, weather',                            21],
    ['Emergency Management',         'FEMA, disaster response, national preparedness, fire administration',                  null],
    ['Congress & Executive',         'White House, Senate, House, GSA, National Archives, federal administration',           13],
];

$ins = $p->prepare("
    INSERT INTO idea_groups (name, description, access_level, scope, state_id, town_id, sic_code, is_standard, template_id, created_by, public_readable, public_voting)
    VALUES (?, ?, 'open', 'federal', NULL, NULL, NULL, 1, ?, NULL, 1, 1)
");

$group_ids = []; // name => id
foreach ($federal_groups as $fg) {
    $ins->execute([$fg[0], $fg[1], $fg[2]]);
    $group_ids[$fg[0]] = (int)$p->lastInsertId();
}
echo "3. Created " . count($federal_groups) . " federal groups\n";

// ── Step 3: Seed agency mappings ──
// Using the group's template_id for the department map (links to closest template)
// For groups with template_id=NULL, we'll look up or skip

// First, get template IDs for each group
$group_templates = [];
$r = $p->query("SELECT id, name, template_id FROM idea_groups WHERE is_standard = 1 AND scope = 'federal'");
while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
    $group_templates[$row['name']] = $row['template_id'];
}

// Agency mappings: [group_name, local_name, contact_url]
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

// For each agency, we need the template_id of its parent group
// But some groups have template_id=NULL — for those, we need to pick a reasonable template
// Environment & Energy and Emergency Management have NULL template_ids
// We'll use the group's template_id, or a fallback

$ins = $p->prepare("
    INSERT INTO town_department_map (town_id, state_id, template_id, local_name, contact_url)
    VALUES (NULL, NULL, ?, ?, ?)
");

$mapped = 0;
$skipped = [];
foreach ($agencies as $a) {
    $groupName = $a[0];
    $tplId = $group_templates[$groupName] ?? null;

    if (!$tplId) {
        // Groups with no template: use a reasonable fallback
        if ($groupName === 'Environment & Energy') $tplId = 8; // Water, Sewer & Waste (closest)
        elseif ($groupName === 'Emergency Management') $tplId = 2; // Fire Protection (closest)
        else {
            $skipped[] = $a[1] . " (no template for $groupName)";
            continue;
        }
    }

    $ins->execute([$tplId, $a[1], $a[2]]);
    $mapped++;
}

echo "4. Mapped $mapped agencies to federal groups\n";
if (!empty($skipped)) {
    echo "   Skipped: " . implode(', ', $skipped) . "\n";
}

// ── Summary ──
echo "\n--- Summary ---\n";
$r = $p->query("SELECT COUNT(*) FROM idea_groups WHERE is_standard = 1 AND scope = 'federal'")->fetchColumn();
echo "Federal standard groups: $r\n";
$r = $p->query("SELECT COUNT(*) FROM town_department_map WHERE state_id IS NULL AND town_id IS NULL")->fetchColumn();
echo "Federal department mappings: $r\n";

echo "\nFederal groups created:\n";
$r = $p->query("SELECT name FROM idea_groups WHERE is_standard = 1 AND scope = 'federal' ORDER BY id");
while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
    echo "  - {$row['name']}\n";
}
