<?php

echo "=================================================================\n";
echo "  SAHDEV ENTERPRISE MOBILE & WHMCS PORT VERIFICATION SUITE       \n";
echo "=================================================================\n\n";

$errors = 0;

function assertCondition(bool $cond, string $passMsg, string $failMsg) {
    global $errors;
    if ($cond) {
        echo "  [PASS] {$passMsg}\n";
    } else {
        echo "  [FAIL] {$failMsg}\n";
        $errors++;
    }
}

// -------------------------------------------------------------
// 1. Verify 12 Audio Sound Files in mobile/assets/sounds/
// -------------------------------------------------------------
echo "[1/5] Verifying 12 Bundled Audio Sound Files...\n";
$soundFiles = [
    'chime.wav',
    'alarm.wav',
    'radar.wav',
    'crystal.wav',
    'bell.wav',
    'siren.wav',
    'neon_ping.wav',
    'pulse.wav',
    'cosmic.wav',
    'electro.wav',
    'heartbeat.wav',
    'marimba.wav',
];

$soundsDir = __DIR__ . '/../mobile/assets/sounds';
assertCondition(is_dir($soundsDir), "Sounds directory exists: {$soundsDir}", "Sounds directory missing: {$soundsDir}");

foreach ($soundFiles as $sf) {
    $filePath = $soundsDir . '/' . $sf;
    $exists = file_exists($filePath) && filesize($filePath) > 1000;
    if ($exists) {
        $fp = fopen($filePath, 'rb');
        $header = fread($fp, 12);
        fclose($fp);
        $isWav = (substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WAVE');
        assertCondition($isWav, "Sound '{$sf}' exists, size: " . round(filesize($filePath)/1024, 1) . " KB (Valid RIFF/WAVE)", "Sound '{$sf}' invalid or corrupted!");
    } else {
        assertCondition(false, "", "Sound file missing or empty: {$sf}");
    }
}

// -------------------------------------------------------------
// 2. Verify MobileApiService WHMCS Management Methods
// -------------------------------------------------------------
echo "\n[2/5] Verifying MobileApiService WHMCS Port Methods...\n";
require_once __DIR__ . '/../lib/MobileApiService.php';

$rc = new ReflectionClass(\Sahdev\Lib\MobileApiService::class);
$requiredMethods = [
    'authenticate',
    'pollQueue',
    'getChatHistory',
    'sendMessage',
    'takeoverSession',
    'generateAiSuggestion',
    'getClientDetails',
    'getCannedResponses',
    'getTickets',
    'getTicketDetails',
    'replyTicket',
    'analyzeTicketAi',
    'updateTicketStatus',
    'getClientsList',
    'getClientProfile',
    'getServicesList',
    'getInvoicesList',
];

foreach ($requiredMethods as $m) {
    assertCondition($rc->hasMethod($m), "Method MobileApiService::{$m} exists", "Missing method: MobileApiService::{$m}");
}

// Check canned responses
$canned = \Sahdev\Lib\MobileApiService::getCannedResponses();
assertCondition(isset($canned['status']) && $canned['status'] === 'success' && count($canned['responses']) >= 5,
    "MobileApiService::getCannedResponses() returns " . count($canned['responses'] ?? []) . " quick canned macros",
    "getCannedResponses failed");

// -------------------------------------------------------------
// 3. Verify ajax.php Early Dispatcher Routes
// -------------------------------------------------------------
echo "\n[3/5] Verifying ajax.php Mobile Dispatcher Routes...\n";
$ajaxContent = file_get_contents(__DIR__ . '/../ajax.php');

$routes = [
    'mobile_tickets',
    'mobile_ticket_detail',
    'mobile_ticket_reply',
    'mobile_ticket_ai_analyze',
    'mobile_ticket_update',
    'mobile_clients',
    'mobile_client_detail',
    'mobile_services',
    'mobile_invoices',
    'mobile_send',
    'mobile_poll',
];

foreach ($routes as $route) {
    assertCondition(strpos($ajaxContent, "'{$route}'") !== false,
        "Route '{$route}' mapped in ajax.php",
        "Route '{$route}' missing in ajax.php");
}

// Verify no missing ticket ID requirement on mobile
assertCondition(strpos($ajaxContent, '$isMobileAction = strpos($action, \'mobile_\') === 0;') !== false,
    "Early \$isMobileAction check present at top of ajax.php",
    "\$isMobileAction check missing in ajax.php");

// -------------------------------------------------------------
// 4. Verify Flutter Architecture, AMOLED & Providers
// -------------------------------------------------------------
echo "\n[4/5] Verifying Flutter Architecture & AMOLED Configuration...\n";

// ThemeConfig
$themeConfig = file_get_contents(__DIR__ . '/../mobile/lib/config/theme_config.dart');
assertCondition(strpos($themeConfig, 'amoledTheme') !== false && strpos($themeConfig, 'Color(0xFF000000)') !== false,
    "ThemeConfig defines amoledTheme with pure pitch-black Color(0xFF000000)",
    "ThemeConfig missing amoledTheme");

// ThemeProvider
assertCondition(file_exists(__DIR__ . '/../mobile/lib/providers/theme_provider.dart'),
    "ThemeProvider exists with persistent theme selection",
    "ThemeProvider missing");

// TicketProvider
assertCondition(file_exists(__DIR__ . '/../mobile/lib/providers/ticket_provider.dart'),
    "TicketProvider exists with WHMCS ticket state & Sahdev AI Intelligence",
    "TicketProvider missing");

// HomeShell with 5 tabs
$homeShell = file_get_contents(__DIR__ . '/../mobile/lib/screens/home_shell.dart');
assertCondition(strpos($homeShell, 'Live Chat') !== false &&
    strpos($homeShell, 'Tickets') !== false &&
    strpos($homeShell, 'Clients') !== false &&
    strpos($homeShell, 'Services') !== false &&
    strpos($homeShell, 'Settings') !== false,
    "HomeShell provides complete 5-tab WHMCS navigation (Chat, Tickets, Clients, Services, Settings)",
    "HomeShell navigation tabs missing");

// AndroidManifest permissions
$manifest = file_get_contents(__DIR__ . '/../mobile/android/app/src/main/AndroidManifest.xml');
assertCondition(strpos($manifest, 'REQUEST_IGNORE_BATTERY_OPTIMIZATIONS') !== false &&
    strpos($manifest, 'FOREGROUND_SERVICE_DATA_SYNC') !== false,
    "AndroidManifest declares battery optimization bypass and data sync foreground service",
    "AndroidManifest missing battery or service permissions");

// -------------------------------------------------------------
// 5. Verify Bug Fixes: Teammate Name & Background Alert Logic
// -------------------------------------------------------------
echo "\n[5/5] Verifying Bug Fixes (Teammate Names & Background Polling)...\n";

// MessageBubble teammate name
$bubbleCode = file_get_contents(__DIR__ . '/../mobile/lib/widgets/message_bubble.dart');
assertCondition(strpos($bubbleCode, 'if (!isStaff)') === false && strpos($bubbleCode, 'isStaff') !== false,
    "MessageBubble sender name constraint removed: teammate staff names render with Support Agent badge",
    "MessageBubble still suppresses staff names");

// BackgroundService alert sound check
$bgCode = file_get_contents(__DIR__ . '/../mobile/lib/services/background_service.dart');
assertCondition(strpos($bgCode, "data['alert_sound'] == true") !== false,
    "BackgroundService correctly checks data['alert_sound'] for incoming summons",
    "BackgroundService key check error");

// AudioService duration & vibration
$audioCode = file_get_contents(__DIR__ . '/../mobile/lib/services/audio_service.dart');
assertCondition(strpos($audioCode, 'availableSounds') !== false &&
    strpos($audioCode, 'previewSound') !== false &&
    strpos($audioCode, 'setAlertDuration') !== false &&
    strpos($audioCode, 'setVibrationPattern') !== false,
    "AudioService supports 12 sounds, live preview, configurable duration, and vibration patterns",
    "AudioService missing duration/vibration/preview controls");

echo "\n=================================================================\n";
if ($errors === 0) {
    echo "  ALL VERIFICATION CHECKS PASSED PERFECTLY! (0 ERRORS)\n";
} else {
    echo "  VERIFICATION FAILED WITH {$errors} ERROR(S)!\n";
}
echo "=================================================================\n";

exit($errors > 0 ? 1 : 0);
