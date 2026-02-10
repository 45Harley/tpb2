<?php
// Quick test to see OpenStates API pagination structure

if ($_GET['key'] !== 'tpb2025import') {
    die('Unauthorized');
}

header('Content-Type: text/plain');

$apiKey = 'dfbdcccc-5fc7-4630-a2b0-c21d8a310bd0';
$state = $_GET['state'] ?? 'ca'; // California has 120 legislators

$url = "https://v3.openstates.org/people?jurisdiction=$state&per_page=50&page=1";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "X-API-KEY: $apiKey",
        "Accept: application/json"
    ],
]);

$response = curl_exec($ch);
$data = json_decode($response, true);
curl_close($ch);

echo "=== OpenStates API Test for $state ===\n\n";

echo "Results count: " . count($data['results'] ?? []) . "\n\n";

echo "=== PAGINATION STRUCTURE ===\n";
print_r($data['pagination'] ?? 'No pagination key');

echo "\n\n=== FULL RESPONSE KEYS ===\n";
print_r(array_keys($data));
