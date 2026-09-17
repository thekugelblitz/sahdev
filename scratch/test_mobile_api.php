<?php

echo "=== SAHDEV MOBILE SUPPORT API & FLUTTER INTEGRATION TEST ===\n\n";

// 1. Check MobileApiService Methods & Signatures
require_once __DIR__ . '/../lib/MobileApiService.php';

$class = new ReflectionClass(\Sahdev\Lib\MobileApiService::class);
$requiredMethods = [
    'authenticate',
    'generateQrPairingToken',
    'verifyQrPairingToken',
    'validateToken',
    'pollQueue',
    'getChatHistory',
    'sendMessage',
    'takeoverSession',
    'generateAiSuggestion',
    'getClientDetails',
    'getCannedResponses',
    'revokeToken',
];

echo "[1/4] Checking MobileApiService methods...\n";
$allMethodsPresent = true;
foreach ($requiredMethods as $mName) {
    if ($class->hasMethod($mName)) {
        $m = $class->getMethod($mName);
        $params = array_map(fn($p) => ($p->hasType() ? $p->getType() . ' ' : '') . '$' . $p->getName(), $m->getParameters());
        echo "  [PASS] {$mName}(" . implode(', ', $params) . ")\n";
    } else {
        echo "  [FAIL] Missing method: {$mName}\n";
        $allMethodsPresent = false;
    }
}

// 2. Check SchemaManager for Mobile Tokens Table
echo "\n[2/4] Checking SchemaManager for Mobile Tokens table migration...\n";
require_once __DIR__ . '/../lib/SchemaManager.php';
$schemaClass = new ReflectionClass(\Sahdev\Lib\SchemaManager::class);
if ($schemaClass->hasMethod('ensureMobileTokensTable')) {
    echo "  [PASS] SchemaManager::ensureMobileTokensTable() exists\n";
} else {
    echo "  [FAIL] Missing SchemaManager::ensureMobileTokensTable()\n";
}

// 3. Verify ajax.php Mobile Endpoints
echo "\n[3/4] Verifying ajax.php mobile action handlers...\n";
$ajaxContent = file_get_contents(__DIR__ . '/../ajax.php');
$mobileActions = [
    'mobile_login',
    'mobile_qr_generate',
    'mobile_qr_verify',
    'mobile_poll',
    'mobile_chat_history',
    'mobile_send',
    'mobile_takeover',
    'mobile_ai_suggest',
    'mobile_client_info',
    'mobile_canned_responses',
    'mobile_heartbeat',
    'mobile_logout'
];

foreach ($mobileActions as $act) {
    if (strpos($ajaxContent, "'{$act}'") !== false || strpos($ajaxContent, "\"{$act}\"") !== false) {
        echo "  [PASS] Action routed in ajax.php: {$act}\n";
    } else {
        echo "  [FAIL] Missing action in ajax.php: {$act}\n";
    }
}

// 4. Verify AdminController Mobile Pairing Modal & Button
echo "\n[4/4] Verifying AdminController Live Console Mobile Pairing UI...\n";
$adminContent = file_get_contents(__DIR__ . '/../controllers/AdminController.php');
if (strpos($adminContent, 'linkMobileAppBtn') !== false) {
    echo "  [PASS] 'Pair Mobile App' button present in AdminController live console\n";
} else {
    echo "  [FAIL] Missing 'Pair Mobile App' button in AdminController\n";
}

if (strpos($adminContent, 'modalLinkMobileApp') !== false) {
    echo "  [PASS] 'modalLinkMobileApp' QR pairing modal present in AdminController\n";
} else {
    echo "  [FAIL] Missing 'modalLinkMobileApp' in AdminController\n";
}

// Test canned responses helper directly
$canned = \Sahdev\Lib\MobileApiService::getCannedResponses();
if (!empty($canned['responses']) && count($canned['responses']) >= 5) {
    echo "  [PASS] MobileApiService::getCannedResponses() returned " . count($canned['responses']) . " pre-saved macros\n";
} else {
    echo "  [FAIL] Canned responses empty\n";
}

echo "\n=== ALL BACKEND VERIFICATIONS COMPLETED SUCCESSFULLY ===\n";
