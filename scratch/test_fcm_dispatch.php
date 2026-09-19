<?php

/**
 * Verification Test Script: FirebasePushService, Schema, and MobileApiService FCM endpoints.
 */

// Simple mock for Capsule if WHMCS environment is not running
if (!class_exists('\WHMCS\Database\Capsule')) {
    // Check if we can load WHMCS or run mock
    $whmcsInit = __DIR__ . '/../init.php';
    if (file_exists($whmcsInit)) {
        require_once $whmcsInit;
    }
}

// 1. PHP Syntax Check
$phpFiles = [
    __DIR__ . '/../lib/SchemaManager.php',
    __DIR__ . '/../lib/FirebasePushService.php',
    __DIR__ . '/../lib/MobileApiService.php',
    __DIR__ . '/../lib/ChatService.php',
    __DIR__ . '/../hooks.php',
    __DIR__ . '/../ajax.php',
];

echo "=== 1. PHP Syntax Linter Check ===\n";
$allValid = true;
foreach ($phpFiles as $file) {
    $output = [];
    $returnVar = 0;
    exec('php -l ' . escapeshellarg($file), $output, $returnVar);
    if ($returnVar === 0) {
        echo "  [PASS] " . basename($file) . "\n";
    } else {
        echo "  [FAIL] " . basename($file) . ": " . implode("\n", $output) . "\n";
        $allValid = false;
    }
}

if (!$allValid) {
    echo "Syntax checks failed!\n";
    exit(1);
}

// 2. RS256 JWT Token Signing Verification
echo "\n=== 2. RS256 JWT Signature Generation & Verification ===\n";
// Generate an ephemeral RSA 2048 key pair to test JWT signing
$config = [
    "digest_alg" => "sha256",
    "private_key_bits" => 2048,
    "private_key_type" => OPENSSL_KEYTYPE_RSA,
];
$res = openssl_pkey_new($config);
if ($res) {
    openssl_pkey_export($res, $privKey);
    $pubKeyDetails = openssl_pkey_get_details($res);
    $pubKey = $pubKeyDetails["key"];

    $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode([
        'iss' => 'test@sahdev-test.iam.gserviceaccount.com',
        'sub' => 'test@sahdev-test.iam.gserviceaccount.com',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => time(),
        'exp' => time() + 3600,
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging'
    ])), '+/', '-_'), '=');
    $signingInput = $header . '.' . $payload;

    $signature = '';
    $signOk = openssl_sign($signingInput, $signature, $privKey, OPENSSL_ALGO_SHA256);
    $verifyOk = openssl_verify($signingInput, $signature, $pubKey, OPENSSL_ALGO_SHA256);

    if ($signOk && $verifyOk === 1) {
        echo "  [PASS] RS256 JWT signed and cryptographically verified using native OpenSSL.\n";
    } else {
        echo "  [FAIL] OpenSSL RS256 JWT signing failed.\n";
        exit(1);
    }
} else {
    echo "  [SKIP] OpenSSL key generation not available in CLI environment.\n";
}

// 3. Class loading and method existence checks
echo "\n=== 3. Class & Method Existence Checks ===\n";
require_once __DIR__ . '/../lib/SchemaManager.php';
require_once __DIR__ . '/../lib/FirebasePushService.php';
require_once __DIR__ . '/../lib/MobileApiService.php';

$serviceMethods = [
    'getSettings',
    'isConfigured',
    'getAccessToken',
    'sendToDevice',
    'sendToAdmin',
    'sendToAllStaff',
    'sendSummonAlert',
    'sendChatMessageAlert',
    'sendTicketAlert',
    'sendSystemAlert',
];

foreach ($serviceMethods as $m) {
    if (method_exists(\Sahdev\Lib\FirebasePushService::class, $m)) {
        echo "  [PASS] FirebasePushService::{$m}() exists\n";
    } else {
        echo "  [FAIL] Missing method FirebasePushService::{$m}()\n";
        exit(1);
    }
}

$mobileMethods = [
    'registerFcmToken',
    'unregisterFcmToken',
    'revokeToken',
];

foreach ($mobileMethods as $m) {
    if (method_exists(\Sahdev\Lib\MobileApiService::class, $m)) {
        echo "  [PASS] MobileApiService::{$m}() exists\n";
    } else {
        echo "  [FAIL] Missing method MobileApiService::{$m}()\n";
        exit(1);
    }
}

echo "\nAll verification checks passed successfully!\n";
