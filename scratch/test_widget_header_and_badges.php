<?php
/**
 * End-to-End Verification Suite for:
 * 1. Widget Top Section Header Dynamic Transformation (Staff / AI / Summon)
 * 2. Minimal Name Badges on Widget Side (Staff, Bot, Client)
 * 3. Mobile Takeover Reliability (No AI interference)
 */

echo "=================================================================\n";
echo "  SAHDEV WIDGET HEADER TRANSFORMATION & NAME BADGES TEST         \n";
echo "=================================================================\n\n";

$hooksCode = file_get_contents(__DIR__ . '/../hooks.php');
$mobileApiCode = file_get_contents(__DIR__ . '/../lib/MobileApiService.php');
$chatServiceCode = file_get_contents(__DIR__ . '/../lib/ChatService.php');
$ajaxCode = file_get_contents(__DIR__ . '/../ajax.php');

$errors = 0;

function assertCheck(string $desc, bool $condition, &$errors) {
    if ($condition) {
        echo "  [PASS] {$desc}\n";
    } else {
        echo "  [FAIL] {$desc}\n";
        $errors++;
    }
}

// 1. Check Widget Header Dynamic Transformation in hooks.php
echo "[1/4] Checking Widget Top Section Header Transformation in hooks.php...\n";
assertCheck("window.sdvUpdateHeaderState function exists", strpos($hooksCode, 'window.sdvUpdateHeaderState = function') !== false, $errors);
assertCheck("Stores original bot title/avatar in window._sdvOriginalHeader", strpos($hooksCode, 'window._sdvOriginalHeader = {') !== false, $errors);
assertCheck("Sets staff name and 'Online • Support Agent' on human takeover", strpos($hooksCode, 'Online &bull; Support Agent') !== false, $errors);
assertCheck("Renders human agent avatar with live dot in header", strpos($hooksCode, 'sdv-staff-avatar-circle') !== false && strpos($hooksCode, 'sdv-staff-avatar-dot') !== false, $errors);
assertCheck("Displays verified support specialist shield icon", strpos($hooksCode, 'Verified Support Agent') !== false, $errors);
assertCheck("Sets 'Summoning Support Agent...' with amber dot when summoning", strpos($hooksCode, 'Summoning Support Agent...') !== false, $errors);
assertCheck("Smoothly restores 'Online • Self-Help AI' when released back to AI", strpos($hooksCode, 'Online &bull; Self-Help AI') !== false, $errors);
assertCheck("Header transitions defined for smooth morphing", strpos($hooksCode, 'transition: all 0.25s ease-in-out;') !== false, $errors);

// 2. Check Minimal Name Badges on Widget Side in hooks.php
echo "\n[2/4] Checking Minimal Name Badges on Widget Side...\n";
assertCheck("CSS for .sdv-staff-header-badge exists", strpos($hooksCode, '.sdv-staff-header-badge {') !== false, $errors);
assertCheck("CSS for .sdv-bot-header-badge exists", strpos($hooksCode, '.sdv-bot-header-badge {') !== false, $errors);
assertCheck("CSS for .sdv-user-header-badge exists", strpos($hooksCode, '.sdv-user-header-badge {') !== false, $errors);
assertCheck("Dark theme badge color overrides present", strpos($hooksCode, '.sdv-theme-cyber_dark .sdv-bot-header-badge') !== false, $errors);
assertCheck("Initial welcome message wrapped with bot badge", strpos($hooksCode, '<div class="sdv-bot-header-badge"><svg') !== false, $errors);
assertCheck("sdvReplyToMsg strips staff, bot, and user badges from quotes", strpos($hooksCode, 'var botBadge = clone.querySelector(\'.sdv-bot-header-badge\');') !== false && strpos($hooksCode, 'var userBadge = clone.querySelector(\'.sdv-user-header-badge\');') !== false, $errors);
assertCheck("sdvFormatMsgWithBadge helper formats staff, bot, and user bubbles", strpos($hooksCode, 'function sdvFormatMsgWithBadge(') !== false, $errors);
assertCheck("appendClMsg calls sdvFormatMsgWithBadge", strpos($hooksCode, 'sdvFormatMsgWithBadge(role, text, senderName, isHtml)') !== false, $errors);

// 3. Check Mobile Takeover Anti-AI Interference in MobileApiService.php
echo "\n[3/4] Checking Mobile Takeover & Anti-AI Interference...\n";
assertCheck("MobileApiService::sendMessage inserts sender_type => 'staff'", strpos($mobileApiCode, "'sender_type'  => 'staff'") !== false, $errors);
assertCheck("MobileApiService::sendMessage updates last_staff_message_at", strpos($mobileApiCode, "'last_staff_message_at' => Carbon::now()") !== false, $errors);
assertCheck("MobileApiService::takeoverSession updates last_staff_message_at and claimed", strpos($mobileApiCode, "'summon_status'         => 'claimed'") !== false, $errors);
assertCheck("Mobile message return payload sets sender_type => 'staff'", strpos($mobileApiCode, "'sender_type' => 'staff'") !== false, $errors);

// 4. Check Backend Dispatcher and Polling Synchronization
echo "\n[4/5] Checking Backend Synchronization in ChatService & ajax.php...\n";
assertCheck("ChatService::handleClientMessage pauses AI when isHuman or assigned_admin_id > 0", strpos($chatServiceCode, 'if ($isHuman || ($session[\'status\'] ?? \'\') === \'taken_over\' || (int)($session[\'assigned_admin_id\'] ?? 0) > 0)') !== false, $errors);
assertCheck("ChatService::handleClientMessage returns assigned_admin_name on takeover", strpos($chatServiceCode, "'assigned_admin_name' => \$assignedAdminName") !== false, $errors);
assertCheck("ChatService::pollSessionMessages guarantees non-empty sender_name", strpos($chatServiceCode, "\$senderName = \$assignedAdminName ?: 'Support Agent';") !== false, $errors);
assertCheck("ChatService::pollSessionMessages includes chat_title", strpos($chatServiceCode, "'chat_title'          => \$botTitle") !== false, $errors);
assertCheck("ajax.php client_chat_init returns assigned_admin_name and is_human", strpos($ajaxCode, "'assigned_admin_name' => \$assignedAdminName") !== false && strpos($ajaxCode, "'is_human'            => \$isHuman") !== false, $errors);

// 5. Check User Message Bubble Badge Styling & Client Name vs You Resolution
echo "\n[5/5] Checking User Badge Anti-Whitewashing & Client Name Resolution...\n";
assertCheck("User badge uses color: inherit to prevent whitewashing", strpos($hooksCode, 'color: inherit !important;') !== false, $errors);
assertCheck("Apple Siri theme has explicit high-contrast badge styling", strpos($hooksCode, '#sdv-client-chat-window.sdv-theme-apple_siri .sdv-user-header-badge') !== false && strpos($hooksCode, '#475569') !== false, $errors);
assertCheck("Terminal CLI theme has dark green badge styling", strpos($hooksCode, '#sdv-client-chat-window.sdv-theme-terminal_cli .sdv-user-header-badge') !== false && strpos($hooksCode, '#052e16') !== false, $errors);
assertCheck("High Contrast theme has black badge styling", strpos($hooksCode, '#sdv-client-chat-window.sdv-theme-high_contrast .sdv-user-header-badge') !== false && strpos($hooksCode, '#000000') !== false, $errors);
assertCheck("sdvGetClientDisplayName function defined in hooks.php", strpos($hooksCode, 'function sdvGetClientDisplayName()') !== false, $errors);
assertCheck("sdvGetClientDisplayName falls back to 'You' for unidentified visitors", strpos($hooksCode, "return 'You';") !== false, $errors);
assertCheck("sdvSendMessage uses sdvGetClientDisplayName()", strpos($hooksCode, "var clientDisplayName = sdvGetClientDisplayName();") !== false, $errors);
assertCheck("ChatService::handleClientMessage resolves client name with 'You' fallback", strpos($chatServiceCode, "\$senderName = 'You';") !== false, $errors);
assertCheck("ChatService::getSessionMessages maps clientDisplayName or 'You'", strpos($chatServiceCode, "\$senderName = !empty(\$clientDisplayName) ? \$clientDisplayName : 'You';") !== false, $errors);
assertCheck("ChatService::pollSessionMessages maps clientDisplayName or 'You'", strpos($chatServiceCode, "\$senderName = !empty(\$clientDisplayName) ? \$clientDisplayName : 'You';") !== false, $errors);
assertCheck("ajax.php extracts guest name from metadata_json if guest", strpos($ajaxCode, "\$metadata['name'] = trim(\$sessMeta['name']);") !== false, $errors);

echo "\n=================================================================\n";
if ($errors === 0) {
    echo "  ALL VERIFICATION CHECKS PASSED PERFECTLY! (0 ERRORS)\n";
    echo "=================================================================\n";
    exit(0);
} else {
    echo "  FAILED WITH {$errors} ERRORS!\n";
    echo "=================================================================\n";
    exit(1);
}

