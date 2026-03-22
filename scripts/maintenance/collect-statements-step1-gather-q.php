<?php
/**
 * Statement gather logic for Q pipeline
 * Returns prompt data array for a given official_id.
 */

function gatherStatementPrompt($pdo, $officialId) {
    $officials = [
        326 => ['name' => 'President Donald Trump', 'short_name' => 'Trump', 'title' => 'President',
            'sources_text' => "1. Truth Social\n2. WhiteHouse.gov\n3. C-SPAN\n4. Factbase\nAlso: AP, Reuters, The Hill, CNN, Fox News, NBC, CNBC",
            'source_types' => 'Truth Social | Press Conference | Interview | WH Statement | Rally',
            'search_instruction' => 'Check Truth Social, press conferences, interviews, White House statements, and media.',
            'extra_rules' => "- Retweets/reposts are NOT statements unless he adds commentary\n"],
        374 => ['name' => 'U.S. Senator Richard Blumenthal (D-CT)', 'short_name' => 'Blumenthal', 'title' => 'U.S. Senator',
            'sources_text' => "1. blumenthal.senate.gov/newsroom/press/\n2. Twitter/X @SenBlumenthal\n3. Senate committees\n4. C-SPAN\nAlso: CT Mirror, Hartford Courant, The Hill, CNN, AP",
            'source_types' => 'Press Release | Twitter/X | Committee Hearing | Floor Speech | Interview',
            'search_instruction' => 'Check senate.gov press releases, Twitter/X, committee hearings, floor speeches, CT media.',
            'extra_rules' => ''],
        441 => ['name' => 'U.S. Senator Christopher Murphy (D-CT)', 'short_name' => 'Murphy', 'title' => 'U.S. Senator',
            'sources_text' => "1. murphy.senate.gov/newsroom/press-releases\n2. Twitter/X @ChrisMurphyCT\n3. chrismurphy.substack.com\n4. Senate committees\n5. C-SPAN\nAlso: CT Mirror, Hartford Courant, The Hill, CNN, NPR, AP",
            'source_types' => 'Press Release | Twitter/X | Newsletter | Committee Hearing | Floor Speech | Interview',
            'search_instruction' => 'Check senate.gov, Twitter/X, Substack, committee hearings, floor speeches, media.',
            'extra_rules' => ''],
        390 => ['name' => 'U.S. Representative Joe Courtney (D-CT-2)', 'short_name' => 'Courtney', 'title' => 'U.S. Representative',
            'sources_text' => "1. courtney.house.gov/news\n2. Twitter/X @RepJoeCourtney\n3. House committees\n4. C-SPAN\nAlso: CT Mirror, Hartford Courant, The Day, Norwich Bulletin, AP, The Hill",
            'source_types' => 'Press Release | Twitter/X | Committee Hearing | Floor Speech | Interview',
            'search_instruction' => 'Check house.gov, Twitter/X, committee hearings, floor speeches, CT media.',
            'extra_rules' => ''],
    ];

    if (!isset($officials[$officialId])) return null;
    $official = $officials[$officialId];

    require_once dirname(__DIR__) . '/../includes/site-settings.php';

    $settingKey = "statement_collect_last_success_{$officialId}";
    $lastSuccess = getSiteSetting($pdo, $settingKey, '');
    if ($lastSuccess) {
        $lookbackDays = max(1, (int)((time() - strtotime($lastSuccess)) / 86400));
    } else {
        $lookbackDays = 2;
    }
    if ($lookbackDays > 7) $lookbackDays = 7;

    $today = date('Y-m-d');
    $windowStart = date('Y-m-d', strtotime("-{$lookbackDays} day"));

    $stmt = $pdo->prepare("SELECT id, statement_date, LEFT(content, 120) AS content_preview, source, source_url FROM rep_statements WHERE official_id = ? ORDER BY statement_date DESC LIMIT 60");
    $stmt->execute([$officialId]);
    $recent = $stmt->fetchAll();

    $dedupLines = [];
    foreach ($recent as $s) $dedupLines[] = "#{$s['id']} ({$s['statement_date']}) [{$s['source']}] {$s['content_preview']}";

    $policyTopics = 'Economy & Jobs, Healthcare, Education, Environment & Climate, Immigration, National Security, Criminal Justice, Housing, Infrastructure, Social Services, Tax Policy, Civil Rights, Technology & Privacy, Foreign Policy, Agriculture, Government Reform';

    $systemPrompt = "You are a civic researcher for The People's Branch (TPB).\n\nYour job: Search for NEW public statements by {$official['name']} from {$windowStart} to {$today} that are NOT already in our database.\n\n## What Counts as a Statement\nPublic words by this official that:\n- State a position on policy\n- Make a promise or announcement\n- Claim results or credit\n- React to events, court rulings, or criticism\n- Introduce or co-sponsor legislation\n\n## Sources to Search\n{$official['sources_text']}\n\n## Existing Statements (DO NOT DUPLICATE)\n" . implode("\n", $dedupLines) . "\n\n## Deduplication Rules\n- Skip if same content or same source URL\n- Multiple statements on different topics from same day are SEPARATE\n{$official['extra_rules']}\n## Tense: future | present | past\n\n## Policy Topics: {$policyTopics}\n\n## Scoring\nSeverity (0-1000): How harmful. Most 0-70.\nBenefit (0-1000): How much positive impact. Most 0-70.\n\n## Official ID: {$officialId} = {$official['short_name']}\n\n## Output\nReturn ONLY valid JSON:\n{\"statements\":[{\"statement_date\":\"YYYY-MM-DD\",\"content\":\"quote\",\"summary\":\"one sentence\",\"source\":\"{$official['source_types']}\",\"source_url\":\"url\",\"policy_topic\":\"topic\",\"tense\":\"tense\",\"official_id\":{$officialId},\"severity_score\":0,\"benefit_score\":0}],\"search_summary\":\"what you searched\"}";

    return [
        'system_prompt' => $systemPrompt,
        'user_message' => "Search for public statements by {$official['name']} from {$windowStart} to {$today}. {$official['search_instruction']} Return structured JSON.",
        'official_id' => $officialId,
        'official_name' => $official['short_name']
    ];
}
