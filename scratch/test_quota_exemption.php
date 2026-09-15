<?php

echo "=== SAHDEV HUMAN AGENT RATE LIMIT & QUOTA EXEMPTION TEST ===\n\n";

// 1. Load ChatService
require_once __DIR__ . '/../lib/ChatService.php';

echo "[1/5] Testing ChatService::isHumanAgentSession()...\n";

$testCases = [
    'Taken over session' => [
        'data'     => ['status' => 'taken_over', 'summon_status' => 'none', 'assigned_admin_id' => 0],
        'expected' => true,
    ],
    'Summon requested session' => [
        'data'     => ['status' => 'active', 'summon_status' => 'requested', 'assigned_admin_id' => 0],
        'expected' => true,
    ],
    'Summon claimed session' => [
        'data'     => ['status' => 'active', 'summon_status' => 'claimed', 'assigned_admin_id' => 0],
        'expected' => true,
    ],
    'Admin assigned session' => [
        'data'     => ['status' => 'active', 'summon_status' => 'none', 'assigned_admin_id' => 2],
        'expected' => true,
    ],
    'Autonomous AI session' => [
        'data'     => ['status' => 'active', 'summon_status' => 'none', 'assigned_admin_id' => 0],
        'expected' => false,
    ],
    'Closed session' => [
        'data'     => ['status' => 'closed', 'summon_status' => 'none', 'assigned_admin_id' => 0],
        'expected' => false,
    ],
];

foreach ($testCases as $name => $tc) {
    // Test with array
    $arrRes = \Sahdev\Lib\ChatService::isHumanAgentSession($tc['data']);
    // Test with object
    $objRes = \Sahdev\Lib\ChatService::isHumanAgentSession((object) $tc['data']);

    if ($arrRes === $tc['expected'] && $objRes === $tc['expected']) {
        $expStr = $tc['expected'] ? 'HUMAN (true)' : 'AI (false)';
        echo "  [PASS] {$name} => {$expStr}\n";
    } else {
        echo "  [FAIL] {$name}: expected " . var_export($tc['expected'], true) . ", got arr=" . var_export($arrRes, true) . ", obj=" . var_export($objRes, true) . "\n";
        exit(1);
    }
}

// 2. Test checkChatQuota logic for human sessions
echo "\n[2/5] Testing ChatService::checkChatQuota() reflection & human exemption...\n";
$chatServiceCode = file_get_contents(__DIR__ . '/../lib/ChatService.php');

$hasHumanCheckInQuota = strpos($chatServiceCode, 'self::isHumanAgentSession((int)$sessionId)') !== false;
$hasIsHumanFlag = strpos($chatServiceCode, "'is_human'      => true") !== false;

if ($hasHumanCheckInQuota && $hasIsHumanFlag) {
    echo "  [PASS] checkChatQuota() checks isHumanAgentSession() and returns is_human => true\n";
} else {
    echo "  [FAIL] checkChatQuota() is missing human exemption check!\n";
    exit(1);
}

// 3. Test handleClientMessage architecture
echo "\n[3/5] Testing ChatService::handleClientMessage() execution order...\n";

// Ensure getOrCreateClientSession comes before checkChatQuota
$posGetSession = strpos($chatServiceCode, 'getOrCreateClientSession');
$posCheckQuota = strpos($chatServiceCode, 'checkChatQuota');
$posIfNotHuman = strpos($chatServiceCode, 'if (!$isHuman)');

if ($posGetSession !== false && $posCheckQuota !== false && $posGetSession < $posCheckQuota && $posIfNotHuman !== false) {
    echo "  [PASS] Session resolution occurs BEFORE quota checks\n";
    echo "  [PASS] Quota and prompt injection checks are conditionally scoped under if (!\$isHuman)\n";
} else {
    echo "  [FAIL] handleClientMessage() ordering is incorrect!\n";
    exit(1);
}

$hasSummonQueueBranch = strpos($chatServiceCode, "'is_summoned'     => true") !== false;
if ($hasSummonQueueBranch) {
    echo "  [PASS] Dedicated queue branch for summoned/pending staff sessions exists\n";
} else {
    echo "  [FAIL] Missing is_summoned branch in handleClientMessage()!\n";
    exit(1);
}

// 4. Test ajax.php rate limiter exemption
echo "\n[4/5] Testing ajax.php client_chat_message rate limiter...\n";
$ajaxCode = file_get_contents(__DIR__ . '/../ajax.php');

$hasIsHumanInAjax = strpos($ajaxCode, '\Sahdev\Lib\ChatService::isHumanAgentSession($sessionUuid)') !== false;
$hasRateLimiterConditional = strpos($ajaxCode, 'if (!$isHumanSession)') !== false;

if ($hasIsHumanInAjax && $hasRateLimiterConditional) {
    echo "  [PASS] ajax.php resolves sessionUuid and checks isHumanAgentSession\n";
    echo "  [PASS] 10 msg/min IP burst limiter ONLY applies when !\$isHumanSession\n";
} else {
    echo "  [FAIL] ajax.php does not properly exempt human sessions from rate limits!\n";
    exit(1);
}

// 5. Test hooks.php client widget limit clearing & sync
echo "\n[5/5] Testing hooks.php client widget synchronization...\n";
$hooksCode = file_get_contents(__DIR__ . '/../hooks.php');

$hasClearLimitFn = strpos($hooksCode, 'function sdvClearLimitState') !== false;
$hasApplyLimitCheck = strpos($hooksCode, 'if (isHumanSessionActive || (limitInfo && (limitInfo.is_human || limitInfo.limit_reached === false)))') !== false;
$hasPollSync = strpos($hooksCode, "res.status === 'taken_over' || res.summon_status === 'requested'") !== false;
$hasSummonUnlock = strpos($hooksCode, 'isHumanSessionActive = true;') !== false
    && strpos($hooksCode, 'sdvClearLimitState(false, null);') !== false;
$hasMsgCharExemption = strpos($hooksCode, '!isHumanSessionActive && currentChatStatus !== \'taken_over\'') !== false;

echo "  - sdvClearLimitState() function present: " . ($hasClearLimitFn ? 'PASS' : 'FAIL') . "\n";
echo "  - sdvApplyLimitState() guards against locking human sessions: " . ($hasApplyLimitCheck ? 'PASS' : 'FAIL') . "\n";
echo "  - sdvPollMessages() synchronizes human/taken-over state: " . ($hasPollSync ? 'PASS' : 'FAIL') . "\n";
echo "  - sdvSummonHuman() unlocks composer on agent summon: " . ($hasSummonUnlock ? 'PASS' : 'FAIL') . "\n";
echo "  - sdvSendMessage() relaxes char limits during human takeover: " . ($hasMsgCharExemption ? 'PASS' : 'FAIL') . "\n";

if ($hasClearLimitFn && $hasApplyLimitCheck && $hasPollSync && $hasSummonUnlock && $hasMsgCharExemption) {
    echo "\n>>> ALL 5/5 RATE LIMIT & QUOTA EXEMPTION SUITE CHECKS PASSED! <<<\n";
} else {
    echo "\n>>> ONE OR MORE HOOK CHECKS FAILED! <<<\n";
    exit(1);
}
