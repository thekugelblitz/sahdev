<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Use Replicate's unauthenticated models endpoint to check API schema
$url = "https://replicate.com/api/models/google/gemini-1.5-pro/versions";
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0");
$resp = curl_exec($ch);
curl_close($ch);

$data = json_decode($resp, true);
if (isset($data['results'][0]['openapi_schema']['components']['schemas']['Input']['properties'])) {
    $props = $data['results'][0]['openapi_schema']['components']['schemas']['Input']['properties'];
    foreach ($props as $key => $info) {
        echo "Parameter: $key Type: " . ($info['type'] ?? 'unknown') . "\n";
        if (isset($info['description'])) {
            echo "  Description: {$info['description']}\n";
        }
    }
} else {
    echo "Could not parse schema from $url\n";
    // Try the get model endpoint
    $url2 = "https://replicate.com/api/models/google/gemini-1.5-pro";
    $ch2 = curl_init($url2);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_USERAGENT, "Mozilla/5.0");
    $resp2 = curl_exec($ch2);
    curl_close($ch2);
    $data2 = json_decode($resp2, true);
    if (isset($data2['default_example']['input'])) {
        print_r(array_keys($data2['default_example']['input']));
    } else {
        echo trim(substr($resp2, 0, 500)) . "\n";
    }
}
