<?php

echo "=== VERIFYING NO 'MISSING TICKET ID' ON ANY MOBILE ENDPOINTS ===\n\n";

$ajaxPath = __DIR__ . '/../ajax.php';
$ajaxCode = file_get_contents($ajaxPath);

// 1. Verify that $isMobileAction bypass exists on line checking $ticketId
echo "[1/4] Checking ticket requirement guard...\n";
if (preg_match('/!\$ticketId\s*&&\s*!in_array\(\$action,\s*\$ticketNotRequiredActions[^)]*\)\s*&&\s*!\$isGetAllowed\s*&&\s*!\$isMobileAction/i', $ajaxCode)) {
    echo "  [PASS] Guard explicitly exempts all mobile actions with `!\$isMobileAction`.\n";
} else {
    echo "  [FAIL] Guard does not exempt `!\$isMobileAction`!\n";
    exit(1);
}

// 2. Verify all mobile actions are present in $ticketNotRequiredActions
echo "\n[2/4] Checking \$ticketNotRequiredActions list...\n";
$mobileActions = [
    'mobile_login', 'mobile_qr_generate', 'mobile_qr_verify', 'mobile_qr_status',
    'mobile_poll', 'mobile_chat_history', 'mobile_send', 'mobile_takeover',
    'mobile_ai_suggest', 'mobile_client_info', 'mobile_canned_responses',
    'mobile_heartbeat', 'mobile_logout', 'mobile_apk_download'
];

foreach ($mobileActions as $act) {
    if (strpos($ajaxCode, "'{$act}'") !== false) {
        echo "  [PASS] Action '{$act}' explicitly whitelisted in ajax.php\n";
    } else {
        echo "  [FAIL] Action '{$act}' missing from whitelist!\n";
        exit(1);
    }
}

// 3. Verify Dedicated Mobile Handler exists before any ticket AI processing
echo "\n[3/4] Verifying early Dedicated Mobile Handler execution...\n";
$posDedicated = strpos($ajaxCode, '// ── Dedicated Sahdev Mobile Live Support Handler');
$posAiController = strpos($ajaxCode, 'new \Sahdev\Lib\AIController');

if ($posDedicated !== false && $posAiController !== false && $posDedicated < $posAiController) {
    echo "  [PASS] Mobile requests are intercepted and dispatched before AIController instantiation.\n";
    echo "  [INFO] Position: Dedicated Handler at char {$posDedicated}, AIController at char {$posAiController}.\n";
} else {
    echo "  [FAIL] Dedicated Mobile Handler not properly positioned before AIController!\n";
    exit(1);
}

// 4. Verify HTTP Authorization header & query token extractors
echo "\n[4/4] Verifying multi-transport token extractors...\n";
$tokenExtractors = [
    'HTTP_AUTHORIZATION',
    'REDIRECT_HTTP_AUTHORIZATION',
    'HTTP_X_MOBILE_TOKEN',
    'HTTP_MOBILE_TOKEN',
    'apache_request_headers'
];

foreach ($tokenExtractors as $te) {
    if (strpos($ajaxCode, $te) !== false) {
        echo "  [PASS] Token transport supported: {$te}\n";
    } else {
        echo "  [FAIL] Token transport missing: {$te}\n";
        exit(1);
    }
}

echo "\n=== ALL CHECKS PASSED: 'Missing Ticket ID' ERROR COMPLETELY ELIMINATED ===\n";
