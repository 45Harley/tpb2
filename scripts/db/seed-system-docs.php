<?php
// Seed missing docs into system_documentation table
// Run: scp to server, then /opt/cpanel/ea-php84/root/usr/bin/php /tmp/seed-system-docs.php

// Use absolute path when running from /tmp on server; __DIR__ for local
$configPath = file_exists(__DIR__ . '/../../config.php')
    ? __DIR__ . '/../../config.php'
    : '/home/sandge5/tpb2.sandgems.net/config.php';
$c = require $configPath;
$p = new PDO('mysql:host='.$c['host'].';dbname=sandge5_tpb2', $c['username'], $c['password']);
$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Get existing doc_keys so we skip them
$existing = $p->query("SELECT doc_key FROM system_documentation")->fetchAll(PDO::FETCH_COLUMN);
$existingSet = array_flip($existing);

$docs = [
    // Core docs
    ['platform-overview', 'TPB Platform Overview', 'docs/platform-overview.md', 'citizen,volunteer', 'platform,features,civic,overview'],
    ['infrastructure', 'TPB Infrastructure & Technology Stack', 'docs/infrastructure.md', 'developer,admin', 'infrastructure,deployment,stack,architecture'],
    ['dev-setup-guide', 'TPB2 Dev Setup Guide', 'docs/dev-setup-guide.md', 'developer', 'setup,development,environment,xampp'],
    ['media-management', 'Media File Management', 'docs/media-management.md', 'developer,admin', 'media,files,deployment,git'],

    // Talk docs
    ['talk-architecture', '/talk Architecture', 'docs/talk-architecture.md', 'developer,clerk:brainstorm', 'talk,architecture,system,design'],
    ['talk-access-model', 'Talk Access Model', 'docs/talk-access-model.md', 'developer,clerk:brainstorm', 'talk,access,permissions,groups'],
    ['talk-walkthrough', '/talk Visual Walkthrough', 'docs/talk-walkthrough.md', 'volunteer,citizen,clerk:brainstorm', 'talk,walkthrough,guide,ui'],
    ['talk-test-harness', 'Talk Test Harness', 'docs/talk-test-harness.md', 'developer', 'testing,talk,automation,harness'],
    ['talk-philosophical-grounding', 'Philosophical Grounding: Why /talk Works', 'docs/talk-philosophical-grounding.md', 'volunteer,clerk:brainstorm', 'philosophy,talk,ethics,foundation'],
    ['talk-phase3-seeds', '/talk Phase 3 Seeds', 'docs/talk-phase3-seeds.md', 'developer', 'talk,phase3,brainstorm,archived'],
    ['talk-csps-article', 'The Rays Converge: Building Civic Infrastructure', 'docs/talk-csps-article-draft.md', 'volunteer,clerk:state-builder', 'philosophy,mission,benefits,article'],

    // Builder kits — state
    ['state-builder-overview', 'TPB State Builder Kit', 'docs/state-builder/README.md', 'volunteer,clerk:state-builder', 'state-builder,volunteer,overview'],
    ['state-builder-orientation', 'State Builder Volunteer Orientation', 'docs/state-builder/VOLUNTEER-ORIENTATION.md', 'volunteer,clerk:state-builder', 'volunteer,orientation,ethics'],
    ['state-builder-ethics', 'TPB Ethical Foundation', 'docs/state-builder/ETHICS-FOUNDATION.md', 'volunteer,clerk:state-builder', 'ethics,philosophy,golden-rule'],
    ['state-builder-ai-guide', 'State Builder AI Session Guide', 'docs/state-builder/STATE-BUILDER-AI-GUIDE.md', 'clerk:state-builder', 'state-builder,ai-guide,context'],
    ['state-builder-checklist', 'State Builder Quality Checklist', 'docs/state-builder/state-build-checklist.md', 'volunteer,clerk:state-builder', 'state-builder,quality,checklist'],

    // Builder kits — town
    ['town-builder-ai-guide', 'Town Builder AI Session Guide', 'docs/town-builder/TOWN-BUILDER-AI-GUIDE.md', 'clerk:town-builder', 'town-builder,ai-guide,context'],
    ['town-builder-template', 'Town Page Template Guide', 'docs/town-builder/TOWN-TEMPLATE.md', 'volunteer,clerk:town-builder', 'town-builder,template,guide'],

    // Plans
    ['plan-talk-phase1-design', '/talk Phase 1 Design', 'docs/plans/2026-02-12-talk-phase1-design.md', 'developer', 'talk,phase1,design,database'],
    ['plan-talk-phase1-impl', '/talk Phase 1 Implementation', 'docs/plans/2026-02-12-talk-phase1-impl.md', 'developer', 'talk,phase1,implementation,plan'],
    ['plan-talk-phase2-design', '/talk Phase 2 Design: AI Brainstorm Clerk', 'docs/plans/2026-02-12-talk-phase2-design.md', 'developer,clerk:brainstorm', 'talk,phase2,design,ai'],
    ['plan-talk-phase2-impl', '/talk Phase 2 Implementation', 'docs/plans/2026-02-12-talk-phase2-impl.md', 'developer,clerk:brainstorm', 'talk,phase2,implementation,ai'],
    ['plan-talk-voting-design', 'Talk Voting + Bot Detection Design', 'docs/plans/2026-02-16-talk-voting-botdetect-design.md', 'developer', 'talk,voting,bot,design'],
    ['plan-talk-voting-impl', 'Talk Voting + Bot Detection Implementation', 'docs/plans/2026-02-16-talk-voting-botdetect-impl.md', 'developer', 'talk,voting,bot,implementation'],
    ['plan-imagine-stories', 'Imagine Stories — Group Invite Funnel', 'docs/plans/2026-02-18-imagine-stories.md', 'volunteer,clerk:brainstorm', 'marketing,stories,invites,funnel'],
    ['plan-standard-groups', 'Standard Groups: Scoped + Department Mapping', 'docs/plans/2026-02-19-standard-groups-scoped.md', 'developer,admin', 'talk,groups,templates,scope'],

    // Other
    ['volunteer-task-workflow', 'TPB Volunteer & Task Workflow', 'docs/TPB-Volunteer-Task-Workflow.md', 'volunteer,admin', 'volunteer,workflow,tasks,mentorship'],
    ['collect-threats', 'Collect Threats Process', 'docs/collect-threats-process.md', 'developer,clerk:gatherer', 'election,threats,research,database'],
];

$ins = $p->prepare("
    INSERT INTO system_documentation (doc_key, doc_title, doc_path, roles, tags, doc_content)
    VALUES (?, ?, ?, ?, ?, '')
");

$inserted = 0;
$skipped = 0;
foreach ($docs as $d) {
    if (isset($existingSet[$d[0]])) {
        echo "  SKIP: {$d[0]} (already exists)\n";
        $skipped++;
        continue;
    }
    $ins->execute($d);
    echo "  ADD:  {$d[0]} -> {$d[2]}\n";
    $inserted++;
}

echo "\n--- Summary ---\n";
echo "Inserted: $inserted\n";
echo "Skipped:  $skipped\n";
$total = $p->query("SELECT COUNT(*) FROM system_documentation")->fetchColumn();
echo "Total docs in system_documentation: $total\n";
