<?php

echo "=== TESTING WIDGET & EMBED TAKEOVER ENHANCEMENTS ===\n\n";

$hooksCode = file_get_contents(__DIR__ . '/../hooks.php');
$embedCode = file_get_contents(__DIR__ . '/../embed.js');
$modEmbedCode = file_get_contents(__DIR__ . '/../modules/addons/sahdev/embed.js');

// 1. Console URL in hooks.php
$hasCleanConsoleUrl = strpos($hooksCode, "var consoleUrl = 'addonmodules.php?module=sahdev&action=live_console';") !== false;
echo "1. Relative clean consoleUrl in admin notification toast: " . ($hasCleanConsoleUrl ? "PASS" : "FAIL") . "\n";

// 2. Accept button prevents default and sets targetUrl
$hasPreventDefault = strpos($hooksCode, "if (e && e.preventDefault) e.preventDefault();") !== false;
$hasTargetNav = strpos($hooksCode, "window.location.href = targetUrl;") !== false;
echo "2. Accept button click listener has preventDefault & direct navigation: " . (($hasPreventDefault && $hasTargetNav) ? "PASS" : "FAIL") . "\n";

// 3. No adminBase prepended to consoleUrl
$hasDoubledConsoleUrl = strpos($hooksCode, "var consoleUrl = adminBase + 'addonmodules.php?module=sahdev&action=live_console';") !== false;
echo "3. Doubled adminBase removed from consoleUrl: " . (!$hasDoubledConsoleUrl ? "PASS" : "FAIL") . "\n";

// 4. Scoped CSS button & SVG isolation reset
$hasButtonReset = strpos($hooksCode, "min-width: 0 !important;\n    min-height: 0 !important;") !== false;
$hasSvgReset = strpos($hooksCode, "#sdv-client-chat-window svg {\n    box-sizing: content-box !important;\n    flex-shrink: 0 !important;\n}") !== false;
$hasSendBtnReset = strpos($hooksCode, "min-width: 38px !important;\n    min-height: 38px !important;\n    padding: 0 !important;\n    border-radius: 50% !important;") !== false;
echo "4. Scoped CSS button, SVG, and send button isolation: " . (($hasButtonReset && $hasSvgReset && $hasSendBtnReset) ? "PASS" : "FAIL") . "\n";

// 5. embed.js URL resolution
$hasRootEmbedStrip = strpos($embedCode, "/\\/(modules\\/addons\\/sahdev\\/)?embed\\.js.*$/i") !== false;
$hasModEmbedStrip = strpos($modEmbedCode, "/\\/(modules\\/addons\\/sahdev\\/)?embed\\.js.*$/i") !== false;
echo "5. embed.js handles both root /embed.js and module path: " . (($hasRootEmbedStrip && $hasModEmbedStrip) ? "PASS" : "FAIL") . "\n";

// 6. Staff takeover state in sdvClearLimitState
$hasStaffConnectedBar = strpos($hooksCode, "Staff Connected: <strong style=\"color:#15803d;\">") !== false;
$hasSendEnabled = strpos($hooksCode, "sendBtn.style.cursor = 'pointer';\n            sendBtn.style.pointerEvents = 'auto';") !== false;
echo "6. Staff takeover active bar & send button state in widget: " . (($hasStaffConnectedBar && $hasSendEnabled) ? "PASS" : "FAIL") . "\n";

// 7. Inline event fallbacks on input and send
$hasInputEnter = strpos($hooksCode, "onkeydown=\"if(event.key==='Enter'){event.preventDefault();window.sdvSendMessage&&window.sdvSendMessage();}\"") !== false;
$hasSendClick = strpos($hooksCode, "onclick=\"window.sdvSendMessage&&window.sdvSendMessage();\"") !== false;
echo "7. Inline Enter & Send button event fallbacks: " . (($hasInputEnter && $hasSendClick) ? "PASS" : "FAIL") . "\n";

// 8. Mobile responsiveness for message action buttons
$hasMobileOpacity = strpos($hooksCode, ".sdv-msg-actions {\n        opacity: 0.85 !important;\n    }") !== false;
echo "8. Mobile message actions visibility: " . ($hasMobileOpacity ? "PASS" : "FAIL") . "\n";

if ($hasCleanConsoleUrl && $hasPreventDefault && $hasTargetNav && !$hasDoubledConsoleUrl && $hasButtonReset && $hasSvgReset && $hasSendBtnReset && $hasRootEmbedStrip && $hasModEmbedStrip && $hasStaffConnectedBar && $hasSendEnabled && $hasInputEnter && $hasSendClick && $hasMobileOpacity) {
    echo "\n>>> ALL WIDGET & EMBED TESTS PASSED SUCCESSFULLY! <<<\n";
} else {
    echo "\n>>> SOME TESTS FAILED! <<<\n";
    exit(1);
}
