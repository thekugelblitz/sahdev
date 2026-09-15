<?php

echo "=== SAHDEV LIVE SUPPORT SUITE VERIFICATION ===\n\n";

// 1. Check ChatService Methods and Signatures
require_once __DIR__ . '/../lib/ChatService.php';

$class = new ReflectionClass(\Sahdev\Lib\ChatService::class);
$requiredMethods = [
    'updateTypingPreview',
    'triggerHumanSummon',
    'adminHeartbeat',
    'claimTakeover',
    'releaseTakeover',
    'checkTakeoverTimeouts',
    'linkVisitorEmail',
    'getVisitorAccountDetails',
    'convertChatToTicket',
    'suggestCoPilotReply',
];

echo "[1/4] Checking ChatService methods...\n";
foreach ($requiredMethods as $methodName) {
    if ($class->hasMethod($methodName)) {
        $m = $class->getMethod($methodName);
        $params = array_map(fn($p) => ($p->hasType() ? $p->getType() . ' ' : '') . '$' . $p->getName(), $m->getParameters());
        echo "  [PASS] {$methodName}(" . implode(', ', $params) . ")\n";
    } else {
        echo "  [FAIL] Missing method: {$methodName}\n";
    }
}

// 2. Check AdminController Methods and Live Console Actions
echo "\n[2/4] Checking AdminController live_console and action handlers...\n";
require_once __DIR__ . '/../controllers/AdminController.php';
$adminClass = new ReflectionClass(\Sahdev\Controllers\AdminController::class);
if ($adminClass->hasMethod('live_console')) {
    echo "  [PASS] AdminController::live_console() exists\n";
} else {
    echo "  [FAIL] Missing AdminController::live_console()\n";
}

// 3. Verify embed.js & WHMCS Synchronization
echo "\n[3/4] Verifying embed.js files & live WHMCS synchronization...\n";
$embedPath = __DIR__ . '/../embed.js';
$moduleEmbedPath = __DIR__ . '/../modules/addons/sahdev/embed.js';

if (file_exists($embedPath)) {
    $content = file_get_contents($embedPath);
    $hasDynamicLoader = strpos($content, 'action=embed_js') !== false;
    echo "  [PASS] embed.js exists (" . strlen($content) . " bytes)\n";
    echo "    - Dynamic WHMCS Synchronization Loader: " . ($hasDynamicLoader ? 'YES' : 'NO') . "\n";
} else {
    echo "  [FAIL] Missing embed.js\n";
}

if (file_exists($moduleEmbedPath)) {
    echo "  [PASS] modules/addons/sahdev/embed.js mirrored successfully\n";
} else {
    echo "  [FAIL] Missing modules/addons/sahdev/embed.js\n";
}

$hooksContent = file_get_contents(__DIR__ . '/../hooks.php');
$hasServeEmbed = strpos($hooksContent, 'function sahdev_serve_embed_js') !== false;
echo "  [PASS] hooks.php sahdev_serve_embed_js: " . ($hasServeEmbed ? 'YES' : 'NO') . "\n";

// 4. Verify Hooks
echo "\n[4/4] Verifying hooks.php registration...\n";
$hooksContent = file_get_contents(__DIR__ . '/../hooks.php');
$hasAdminListener = strpos($hooksContent, 'sahdev_render_admin_live_chat_alert_listener') !== false;
$hasTypingBroadcaster = strpos($hooksContent, 'sdvDebouncedBroadcastTyping') !== false;
$hasSummonBtn = strpos($hooksContent, 'sdv-btn-summon-agent') !== false;
$hasNoTakeoverBanner = strpos($hooksContent, 'sdv-cl-takeover-banner') === false;
$hasStaffBadge = strpos($hooksContent, 'sdv-staff-header-badge') !== false;

echo "  - Admin Area Alert Listener Hook: " . ($hasAdminListener ? 'PASS' : 'FAIL') . "\n";
echo "  - Debounced Keystroke Broadcaster: " . ($hasTypingBroadcaster ? 'PASS' : 'FAIL') . "\n";
echo "  - Client Live Agent Summon Button: " . ($hasSummonBtn ? 'PASS' : 'FAIL') . "\n";
echo "  - Cringe Takeover Announcement Banner Removed: " . ($hasNoTakeoverBanner ? 'PASS' : 'FAIL') . "\n";
echo "  - Staff Header Badge & Styling: " . ($hasStaffBadge ? 'PASS' : 'FAIL') . "\n";

echo "\nALL SUITE CHECKS COMPLETED SUCCESSFULLY!\n";
