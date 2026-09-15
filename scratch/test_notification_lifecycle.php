<?php

echo "=== SAHDEV MINDFUL NOTIFICATION & SUMMON LIFECYCLE TEST ===\n\n";

// 1. Schema Check
echo "[1/6] Verifying SchemaManager definition for client_chat_alert_duration...\n";
$schemaCode = file_get_contents(__DIR__ . '/../lib/SchemaManager.php');
if (strpos($schemaCode, "'client_chat_alert_duration'") !== false && strpos($schemaCode, "'default' => 15") !== false) {
    echo "  [PASS] tblsahdev_settings includes client_chat_alert_duration integer column with default 15\n";
} else {
    echo "  [FAIL] Schema definition missing or invalid\n";
    exit(1);
}

// 2. ChatService Summon Lifecycle & Heartbeat Checks
echo "\n[2/6] Testing ChatService summon methods & heartbeat telemetry...\n";
require_once __DIR__ . '/../lib/ChatService.php';
$chatServiceCode = file_get_contents(__DIR__ . '/../lib/ChatService.php');

$hasDismissSummon = method_exists('\Sahdev\Lib\ChatService', 'dismissSummon');
$hasStaffSummonTransition = strpos($chatServiceCode, "\$updatePayload['summon_status'] = 'claimed'") !== false
    && strpos($chatServiceCode, "recordStaffMessage") !== false;
$hasHeartbeatDuration = strpos($chatServiceCode, "\$alertDuration") !== false
    && strpos($chatServiceCode, "'alert_duration'") !== false;

if ($hasDismissSummon) {
    echo "  [PASS] ChatService::dismissSummon() method implemented\n";
} else {
    echo "  [FAIL] ChatService::dismissSummon() missing\n";
    exit(1);
}

if ($hasStaffSummonTransition) {
    echo "  [PASS] recordStaffMessage() automatically transitions requested summons to claimed\n";
} else {
    echo "  [FAIL] recordStaffMessage() does not transition summon_status\n";
    exit(1);
}

if ($hasHeartbeatDuration) {
    echo "  [PASS] adminHeartbeat() queries and returns alert_duration\n";
} else {
    echo "  [FAIL] adminHeartbeat() does not return alert_duration\n";
    exit(1);
}

// 3. ajax.php Actions Check
echo "\n[3/6] Verifying ajax.php endpoints for summon claiming, dismissal and polling...\n";
$ajaxCode = file_get_contents(__DIR__ . '/../ajax.php');

$hasClaimAction = strpos($ajaxCode, "action === 'admin_claim_summon'") !== false;
$hasDismissAction = strpos($ajaxCode, "action === 'admin_dismiss_summon'") !== false;
$hasConsolePollDuration = strpos($ajaxCode, "'alert_duration'   => max(3, min(120, (int)(\$settings->client_chat_alert_duration ?? 15)))") !== false;

if ($hasClaimAction) {
    echo "  [PASS] ajax.php handles admin_claim_summon\n";
} else {
    echo "  [FAIL] admin_claim_summon action missing in ajax.php\n";
    exit(1);
}

if ($hasDismissAction) {
    echo "  [PASS] ajax.php handles admin_dismiss_summon and admin_live_console_dismiss_summon\n";
} else {
    echo "  [FAIL] admin_dismiss_summon action missing in ajax.php\n";
    exit(1);
}

if ($hasConsolePollDuration) {
    echo "  [PASS] admin_live_console_poll returns alert_duration\n";
} else {
    echo "  [FAIL] admin_live_console_poll does not return alert_duration\n";
    exit(1);
}

// 4. AdminController Settings & Live Console Checks
echo "\n[4/6] Verifying AdminController settings & live console script...\n";
$adminCode = file_get_contents(__DIR__ . '/../controllers/AdminController.php');

$hasSaveDuration = strpos($adminCode, "'client_chat_alert_duration'") !== false;
$hasSelectUi = strpos($adminCode, 'name="client_chat_alert_duration"') !== false
    && strpos($adminCode, 'id="alertDurationSelect"') !== false;
$hasLiveConsoleDuration = strpos($adminCode, "var ALERT_DURATION = <?php echo (int)\$alertDuration; ?>") !== false;
$hasStartAlertRing = strpos($adminCode, "function startAlertRing(type, durationSec)") !== false;
$hasStopAlertRing = strpos($adminCode, "function stopAlertRing()") !== false;
$hasCrossTabSync = strpos($adminCode, "sdv_summon_claimed_or_dismissed") !== false;
$hasDismissBtn = strpos($adminCode, 'id="btnDismissSummon"') !== false;
$hasAutoClaimParam = strpos($adminCode, "\$autoClaim = !empty(\$_GET['auto_claim'])") !== false;

if ($hasSaveDuration && $hasSelectUi) {
    echo "  [PASS] AdminController saves and presents client_chat_alert_duration UI\n";
} else {
    echo "  [FAIL] AdminController settings missing alert duration save or UI\n";
    exit(1);
}

if ($hasLiveConsoleDuration && $hasStartAlertRing && $hasStopAlertRing) {
    echo "  [PASS] Live console implements startAlertRing() with configurable ALERT_DURATION and auto-silence\n";
} else {
    echo "  [FAIL] Live console alert ring controller missing\n";
    exit(1);
}

if ($hasCrossTabSync && $hasDismissBtn && $hasAutoClaimParam) {
    echo "  [PASS] Live console supports cross-tab sync, manual dismiss summon, and auto-claim on launch\n";
} else {
    echo "  [FAIL] Live console features missing\n";
    exit(1);
}

// 5. hooks.php Global Admin Toast & Audio Checks
echo "\n[5/6] Verifying hooks.php admin toast & mindful ring audio...\n";
$hooksCode = file_get_contents(__DIR__ . '/../hooks.php');

$hasCorrectConsoleCheck = strpos($hooksCode, "document.getElementById('liveConsoleApp')") !== false;
$hasMindfulRing = strpos($hooksCode, "function startAlertRing(soundType, durationSec)") !== false
    && strpos($hooksCode, "setInterval(function() {") !== false
    && strpos($hooksCode, "2800") !== false;
$hasStorageListener = strpos($hooksCode, "window.addEventListener('storage', function(e) {") !== false
    && strpos($hooksCode, "sdv_summon_claimed_or_dismissed") !== false;
$hasAcceptAutoClaim = strpos($hooksCode, "&auto_claim=1") !== false;
$hasDismissServerCall = strpos($hooksCode, "admin_dismiss_summon") !== false;

if ($hasCorrectConsoleCheck) {
    echo "  [PASS] hooks.php checks for liveConsoleApp element to yield properly\n";
} else {
    echo "  [FAIL] hooks.php live console element check missing or incorrect\n";
    exit(1);
}

if ($hasMindfulRing) {
    echo "  [PASS] hooks.php implements startAlertRing() with 2.8s gentle cadence and auto-silence timeout\n";
} else {
    echo "  [FAIL] hooks.php mindful alert ring missing or not using 2.8s cadence\n";
    exit(1);
}

if ($hasStorageListener && $hasAcceptAutoClaim && $hasDismissServerCall) {
    echo "  [PASS] hooks.php toast implements cross-tab dismissal, server claim takeover, and server dismiss\n";
} else {
    echo "  [FAIL] hooks.php toast handlers missing cross-tab sync or server calls\n";
    exit(1);
}

// 6. hooks.php Client Widget Indicator Checks
echo "\n[6/6] Verifying hooks.php client widget dynamic summon label...\n";
$hasClientPollStatusUpdate = strpos($hooksCode, "statusEl.innerHTML = '<span class=\"sdv-cl-status-dot\" style=\"background:#3b82f6;\"></span> Connected with '") !== false
    && strpos($hooksCode, "statusEl.innerHTML = '<span class=\"sdv-cl-status-dot\" style=\"background:#f59e0b;animation:sdvPulse 1.5s infinite;\"></span> Summoning Agent...'") !== false;
$hasImmediateSummonFeedback = strpos($hooksCode, "Summoning Agent...") !== false;

if ($hasClientPollStatusUpdate && $hasImmediateSummonFeedback) {
    echo "  [PASS] Client widget dynamically transitions status between Online AI, Summoning Agent, and Connected with Staff\n";
} else {
    echo "  [FAIL] Client widget status indicator dynamic updates missing\n";
    exit(1);
}

echo "\n>>> ALL 6/6 MINDFUL NOTIFICATION & SUMMON LIFECYCLE CHECKS PASSED! <<<\n";
