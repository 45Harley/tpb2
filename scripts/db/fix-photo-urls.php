#!/usr/bin/env php
<?php
/**
 * Fix judge photo URLs
 * ====================
 * The portraits.free.law service uses slugs from the judge-pics repo
 * (lastname-firstname-birthinfo.jpeg) which differ from CourtListener
 * person slugs. This script fetches the actual filenames from GitHub
 * and matches them to judges in our DB.
 *
 * Usage: php fix-photo-urls.php [--dry-run]
 */

$dryRun = in_array('--dry-run', $argv);

$c = require dirname(__DIR__, 2) . '/config.php';
$pdo = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Fetching judge-pics file list from GitHub...\n";

// Get all filenames from the judge-pics repo
$ch = curl_init('https://api.github.com/repos/freelawproject/judge-pics/git/trees/main?recursive=1');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['User-Agent: TPB2-PhotoFixer'],
    CURLOPT_TIMEOUT => 30,
]);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    die("GitHub API returned $httpCode\n");
}

$tree = json_decode($resp, true);
if (!$tree || !isset($tree['tree'])) {
    die("Invalid GitHub API response\n");
}

// Extract portrait filenames (only from orig/ directory)
$portraits = [];
foreach ($tree['tree'] as $item) {
    if (preg_match('#^judge_pics/data/orig/(.+)\.jpeg$#', $item['path'], $m)) {
        $slug = $m[1];
        // Skip group photos
        if (str_starts_with($slug, 'group-')) continue;
        $portraits[] = $slug;
    }
}
echo count($portraits) . " portrait files found in repo\n";

// Build a lookup: normalize name parts → portrait slug
// Portrait slug format: lastname-firstname[-birthinfo].jpeg
// We'll index by "lastname-firstname" (first two hyphen-parts) for matching
$portraitsByPrefix = [];
foreach ($portraits as $slug) {
    $parts = explode('-', $slug);
    if (count($parts) >= 2) {
        $key = strtolower($parts[0] . '-' . $parts[1]);
        $portraitsByPrefix[$key][] = $slug;
    }
}

// Get all judges who have has_photo (cl_slug set + photo_url or no photo_url)
$judges = $pdo->query("
    SELECT official_id, full_name, cl_slug, date_of_birth, photo_url, court_type
    FROM elected_officials
    WHERE cl_slug IS NOT NULL AND cl_slug != ''
      AND is_current = 1
      AND court_type IS NOT NULL
    ORDER BY court_type, full_name
")->fetchAll(PDO::FETCH_ASSOC);

echo count($judges) . " judges with CL slugs in DB\n\n";

$updated = 0;
$notFound = 0;
$already = 0;
$baseUrl = 'https://portraits.free.law/v2/256/';

foreach ($judges as $j) {
    $oid = $j['official_id'];
    $name = $j['full_name'];
    $dob = $j['date_of_birth'];
    $dobYear = $dob ? substr($dob, 0, 4) : '';

    // Parse full_name into first/last for matching
    // Names like "Clarence Thomas", "Samuel A. Alito jr", "Amy Coney Barrett"
    $nameParts = preg_split('/\s+/', trim($name));

    // Last name is typically the last word (excluding jr/sr/ii/iii/iv)
    $suffixes = ['jr', 'jr.', 'sr', 'sr.', 'ii', 'iii', 'iv'];
    $lastName = '';
    $firstName = '';

    // Work backwards to find last name (skip suffixes)
    $filtered = [];
    foreach ($nameParts as $p) {
        if (!in_array(strtolower(str_replace('.', '', $p)), $suffixes)) {
            $filtered[] = $p;
        }
    }

    if (count($filtered) >= 2) {
        $firstName = strtolower($filtered[0]);
        $lastName = strtolower($filtered[count($filtered) - 1]);
    } else {
        continue;
    }

    $lookupKey = $lastName . '-' . $firstName;

    $candidates = $portraitsByPrefix[$lookupKey] ?? [];

    if (empty($candidates)) {
        // Try with middle name as part of first name (e.g., ketanji-brown → just ketanji)
        // Already handled above. Skip.
        $notFound++;
        continue;
    }

    // If multiple candidates, pick the one matching DOB
    $bestSlug = null;
    if (count($candidates) === 1) {
        $bestSlug = $candidates[0];
    } else {
        // Try to match by full DOB first, then birth year
        foreach ($candidates as $cand) {
            if ($dob && str_contains($cand, $dob)) {
                $bestSlug = $cand;
                break;
            }
        }
        if (!$bestSlug) {
            foreach ($candidates as $cand) {
                if ($dobYear && str_contains($cand, $dobYear)) {
                    $bestSlug = $cand;
                    break;
                }
            }
        }
        // Do NOT fallback blindly — if no DOB match, skip (could be wrong person)
        if (!$bestSlug) {
            $notFound++;
            continue;
        }
    }

    $newUrl = $baseUrl . $bestSlug . '.jpeg';
    $oldUrl = $j['photo_url'] ?? '';

    if ($newUrl === $oldUrl) {
        $already++;
        continue;
    }

    if ($dryRun) {
        echo "[DRY] $oid $name: $oldUrl → $newUrl\n";
    } else {
        $pdo->prepare("UPDATE elected_officials SET photo_url = ? WHERE official_id = ?")
            ->execute([$newUrl, $oid]);
    }
    $updated++;
}

echo "\nDone.\n";
echo "  Updated: $updated\n";
echo "  Already correct: $already\n";
echo "  No portrait found: $notFound\n";
