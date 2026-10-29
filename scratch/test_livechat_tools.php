<?php

require_once __DIR__ . '/../lib/ChatService.php';
require_once __DIR__ . '/../lib/LiveChatToolService.php';

use Sahdev\Lib\ChatService;
use Sahdev\Lib\LiveChatToolService;

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function runTest(string $name, bool $condition, string $detail = '') {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$name}\n";
    } else {
        $failedTests++;
        echo "  [FAIL] {$name}" . ($detail ? " -> {$detail}" : '') . "\n";
    }
}

echo "================================================================================\n";
echo "           SAHDEV AI LIVE CHAT TOOL CALLING VERIFICATION SUITE\n";
echo "================================================================================\n\n";

// TEST GROUP 1: Pre-Router Direct Answer Routing (Non-Tool Queries)
echo "--- TEST GROUP 1: Pre-Router Non-Tool Direct Routing ---\n";

$nonToolQueries = [
    'Hi there!',
    'Hello',
    'good morning',
    'Thanks a lot, that was helpful',
    'Got it, thanks!',
    'ok',
    'who are you and what can you do?',
    'How can I pay my unpaid invoice?',
    'Can I pay with PayPal or Credit Card?',
    'What web hosting plans do you offer?',
    'Where do I find my cPanel login details in the client area?',
];

foreach ($nonToolQueries as $q) {
    $route = LiveChatToolService::evaluatePreRoute($q, 0, null);
    runTest(
        "Direct routing for '{$q}' (no tools exposed)",
        $route['needs_tools'] === false && empty($route['tools']),
        "Expected needs_tools=false, got " . json_encode($route)
    );
}

// TEST GROUP 2: Diagnostic Query Recognition & Category Routing
echo "\n--- TEST GROUP 2: Diagnostic Tool Category Routing ---\n";

$diagnosticQueries = [
    [
        'query'         => 'Can you check the DNS records for mydomain.com?',
        'expected_cat'  => LiveChatToolService::CATEGORY_DNS,
        'expected_tool' => 'toolsapi_dns_lookup',
        'expected_tgt'  => 'mydomain.com',
    ],
    [
        'query'         => 'Is example.org DNS propagated worldwide yet?',
        'expected_cat'  => LiveChatToolService::CATEGORY_DNS,
        'expected_tool' => 'toolsapi_dns_propagation',
        'expected_tgt'  => 'example.org',
    ],
    [
        'query'         => 'What is the SSL certificate status of google.com?',
        'expected_cat'  => LiveChatToolService::CATEGORY_SSL,
        'expected_tool' => 'toolsapi_ssl_inspect',
        'expected_tgt'  => 'google.com',
    ],
    [
        'query'         => 'When does hostingspell.com expire? Check WHOIS',
        'expected_cat'  => LiveChatToolService::CATEGORY_WHOIS,
        'expected_tool' => 'toolsapi_whois_query',
        'expected_tgt'  => 'hostingspell.com',
    ],
    [
        'query'         => 'Ping 8.8.8.8 to see if there is packet loss',
        'expected_cat'  => LiveChatToolService::CATEGORY_NETWORK,
        'expected_tool' => 'toolsapi_ping',
        'expected_tgt'  => '8.8.8.8',
    ],
    [
        'query'         => 'Check the page speed score for https://wordpress.org',
        'expected_cat'  => LiveChatToolService::CATEGORY_WEB,
        'expected_tool' => 'toolsapi_pagespeed',
        'expected_tgt'  => 'wordpress.org',
    ],
    [
        'query'         => 'Check email security SPF and DMARC for company.co.in',
        'expected_cat'  => LiveChatToolService::CATEGORY_SECURITY,
        'expected_tool' => 'toolsapi_emailsec',
        'expected_tgt'  => 'company.co.in',
    ],
];

foreach ($diagnosticQueries as $item) {
    $route = LiveChatToolService::evaluatePreRoute($item['query'], 0, null);
    runTest(
        "Diagnostic routing for: '{$item['query']}'",
        $route['needs_tools'] === true && in_array($item['expected_cat'], $route['categories'], true),
        "Expected category {$item['expected_cat']}, got " . json_encode($route['categories'] ?? [])
    );

    // Verify targeted tool is exposed
    $toolNames = array_column(array_column($route['tools'] ?? [], 'function'), 'name');
    runTest(
        "Exposes tool '{$item['expected_tool']}' for '{$item['query']}'",
        in_array($item['expected_tool'], $toolNames, true),
        "Available tools: " . implode(', ', $toolNames)
    );

    // Verify target entity resolution
    runTest(
        "Target extracted '{$item['expected_tgt']}'",
        !empty($route['target']) && (strpos($route['target'], $item['expected_tgt']) !== false || $route['target'] === $item['expected_tgt']),
        "Resolved target: " . ($route['target'] ?? 'null')
    );
}

// TEST GROUP 3: Category Segmentation & Prompt Token Protection
echo "\n--- TEST GROUP 3: Category Isolation & Token Protection ---\n";

$registry = LiveChatToolService::getToolRegistry();
$totalRegistryCount = count($registry);
$dnsTools = LiveChatToolService::getToolsForCategories([LiveChatToolService::CATEGORY_DNS]);
$dnsToolCount = count($dnsTools);

runTest(
    "OpenAPI Registry correctly loaded all endpoints",
    $totalRegistryCount >= 20,
    "Loaded tools: {$totalRegistryCount}"
);

runTest(
    "Category filter exposes strictly relevant tools (DNS: {$dnsToolCount} tools vs Total: {$totalRegistryCount})",
    $dnsToolCount > 0 && $dnsToolCount < 6,
    "DNS tools count: {$dnsToolCount}"
);

// Verify no sensitive offensive penetration testing tools are in client registry
$sensitiveTools = ['toolsapi_dirbust', 'toolsapi_xss', 'toolsapi_sqli', 'toolsapi_portscan', 'toolsapi_pwned'];
$hasSensitive = false;
foreach ($sensitiveTools as $st) {
    if (isset($registry[$st])) {
        $hasSensitive = true;
        break;
    }
}
runTest(
    "Sensitive offensive pentest tools (portscan, xss, sqli, dirbust) excluded from client chat surface",
    !$hasSensitive,
    "Sensitive tool found in registry!"
);

// TEST GROUP 4: Strict SSRF & Target Security Validation
echo "\n--- TEST GROUP 4: SSRF & Target Security Validation ---\n";

$ssrfTargets = [
    // Private IPs / Loopback
    '127.0.0.1'       => false,
    'localhost'       => false,
    '::1'             => false,
    '0.0.0.0'         => false,
    '10.0.0.1'        => false,
    '10.254.1.5'      => false,
    '172.16.0.1'      => false,
    '172.31.255.254'  => false,
    '192.168.1.1'     => false,
    '192.168.0.100'   => false,
    '169.254.169.254' => false, // AWS/Cloud Metadata IP
    'router.local'    => false,
    'internal.lan'    => false,
    'ftp://domain.com'=> false, // Invalid protocol

    // Valid Public Targets
    '8.8.8.8'         => true,
    '1.1.1.1'         => true,
    'example.com'     => true,
    'cloudflare.com'  => true,
    'https://google.com' => true,
];

foreach ($ssrfTargets as $target => $expectedValid) {
    $sec = LiveChatToolService::validateTargetSecurity($target);
    $verdict = $sec['valid'] ? 'ALLOWED' : 'BLOCKED';
    $expectedVerdict = $expectedValid ? 'ALLOWED' : 'BLOCKED';
    runTest(
        "Target '{$target}' -> {$verdict} (Expected: {$expectedVerdict})",
        $sec['valid'] === $expectedValid,
        "Error: " . ($sec['error'] ?? 'none')
    );
}

// TEST GROUP 5: Conversational Entity Extraction & Follow-Up Context
echo "\n--- TEST GROUP 5: Entity Extraction & Context Tracking ---\n";

$entityTests = [
    'My site is https://mytestsite.com/blog and server IP is 151.101.1.69' => [
        'domains' => ['mytestsite.com'],
        'ips'     => ['151.101.1.69'],
        'urls'    => ['https://mytestsite.com/blog'],
    ],
    'Please check DNS for apexdomain.co.uk' => [
        'domains' => ['apexdomain.co.uk'],
    ],
    'What about 104.21.45.18?' => [
        'ips' => ['104.21.45.18'],
    ],
];

foreach ($entityTests as $str => $expectedEntities) {
    $extracted = LiveChatToolService::extractEntities($str);
    if (!empty($expectedEntities['domains'])) {
        runTest(
            "Extract domain from '{$str}'",
            !empty($extracted['domains']) && $extracted['domains'][0] === $expectedEntities['domains'][0],
            "Extracted: " . json_encode($extracted['domains'])
        );
    }
    if (!empty($expectedEntities['ips'])) {
        runTest(
            "Extract IP from '{$str}'",
            !empty($extracted['ips']) && $extracted['ips'][0] === $expectedEntities['ips'][0],
            "Extracted: " . json_encode($extracted['ips'])
        );
    }
}

// TEST GROUP 6: Output Normalization & LLM Token Compaction
echo "\n--- TEST GROUP 6: Output Normalization & LLM Compaction ---\n";

// 1. DNS Normalization
$rawDns = json_encode([
    'domain'  => 'example.com',
    'records' => [
        ['type' => 'A', 'value' => '93.184.216.34', 'ttl' => 300],
        ['type' => 'AAAA', 'value' => '2606:2800:220:1:248:1893:25c8:1946', 'ttl' => 300],
        ['type' => 'MX', 'value' => 'mail.example.com', 'ttl' => 300],
    ],
]);
$dnsSummary = LiveChatToolService::normalizeOutput('toolsapi_dns_lookup', '/dns/{domain}', $rawDns, 'example.com');
runTest(
    "DNS output normalization formats records concisely",
    strpos($dnsSummary, '93.184.216.34') !== false && strpos($dnsSummary, 'MX:') !== false,
    $dnsSummary
);
runTest(
    "DNS summary is compact (< 250 characters)",
    mb_strlen($dnsSummary) < 250,
    "Length: " . mb_strlen($dnsSummary)
);

// 2. SSL Normalization
$rawSsl = json_encode([
    'target'         => 'example.com',
    'is_valid'       => true,
    'days_remaining' => 45,
    'issuer'         => ['commonName' => "Let's Encrypt Authority X3"],
    'valid_to'       => '2026-12-15',
]);
$sslSummary = LiveChatToolService::normalizeOutput('toolsapi_ssl_inspect', '/ssl/{target}', $rawSsl, 'example.com');
runTest(
    "SSL output normalization displays validity and days remaining",
    strpos($sslSummary, 'VALID') !== false && strpos($sslSummary, '45') !== false,
    $sslSummary
);

// 3. WHOIS Normalization
$rawWhois = json_encode([
    'domain'          => 'example.com',
    'registrar'       => 'MarkMonitor Inc.',
    'creation_date'   => '1995-08-14',
    'expiration_date' => '2027-08-13',
    'status'          => 'clientTransferProhibited',
    'nameservers'     => ['a.iana-servers.net', 'b.iana-servers.net'],
]);
$whoisSummary = LiveChatToolService::normalizeOutput('toolsapi_whois_query', '/whois/{domain}', $rawWhois, 'example.com');
runTest(
    "WHOIS output normalization displays registrar, expiry, and nameservers",
    strpos($whoisSummary, 'MarkMonitor') !== false && strpos($whoisSummary, '2027-08-13') !== false,
    $whoisSummary
);

// 4. Ping Normalization
$rawPing = json_encode([
    'host'       => '8.8.8.8',
    'packet_loss'=> '0%',
    'avg_rtt'    => 12.4,
]);
$pingSummary = LiveChatToolService::normalizeOutput('toolsapi_ping', '/ping/{host}', $rawPing, '8.8.8.8');
runTest(
    "Ping output normalization displays packet loss and RTT",
    strpos($pingSummary, 'HOST REACHABLE') !== false && strpos($pingSummary, '12.4') !== false,
    $pingSummary
);

// 5. PageSpeed Normalization
$rawPageSpeed = json_encode([
    'url'               => 'https://example.com',
    'performance_score' => 96,
    'strategy'          => 'mobile',
    'first_contentful_paint' => '0.8s',
    'largest_contentful_paint' => '1.2s',
]);
$pageSpeedSummary = LiveChatToolService::normalizeOutput('toolsapi_pagespeed', '/pagespeed/{url}', $rawPageSpeed, 'example.com');
runTest(
    "PageSpeed output normalization formats score and Web Vitals",
    strpos($pageSpeedSummary, '96/100') !== false && strpos($pageSpeedSummary, '0.8s') !== false,
    $pageSpeedSummary
);

// TEST GROUP 7: Execution Budget Limits
echo "\n--- TEST GROUP 7: Server-Side Execution Budget Controls ---\n";

runTest(
    "Max tool executions per turn is capped between 1 and 5 (default: 2)",
    LiveChatToolService::getMaxExecutionsPerTurn() >= 1 && LiveChatToolService::getMaxExecutionsPerTurn() <= 5,
    "Cap value: " . LiveChatToolService::getMaxExecutionsPerTurn()
);

runTest(
    "Default tool cache TTL is reasonable (between 60s and 86400s)",
    LiveChatToolService::getDefaultCacheTtl() >= 60 && LiveChatToolService::getDefaultCacheTtl() <= 86400,
    "TTL value: " . LiveChatToolService::getDefaultCacheTtl()
);

// TEST GROUP 8: Tool Call Validation & Fallback Safety
echo "\n--- TEST GROUP 8: Tool Call Argument Validation & SSRF Guard Execution ---\n";

// 1. Unregistered tool rejection
$unreg = LiveChatToolService::executeToolCall('invalid_tool_name', [], 0, null);
runTest(
    "Rejects unregistered or disallowed tool call",
    $unreg['success'] === false && $unreg['error'] === 'unregistered_tool',
    json_encode($unreg)
);

// 2. SSRF Loopback execution rejection
$loopbackExec = LiveChatToolService::executeToolCall('toolsapi_dns_lookup', ['domain' => '127.0.0.1'], 0, null);
runTest(
    "Blocks loopback execution via SSRF guard in executeToolCall",
    $loopbackExec['success'] === false && $loopbackExec['error'] === 'ssrf_blocked',
    json_encode($loopbackExec)
);

// 3. SSRF Private IP execution rejection
$privateExec = LiveChatToolService::executeToolCall('toolsapi_ping', ['host' => '192.168.1.1'], 0, null);
runTest(
    "Blocks RFC-1918 private IP execution in executeToolCall",
    $privateExec['success'] === false && $privateExec['error'] === 'ssrf_blocked',
    json_encode($privateExec)
);

// 4. Missing target parameter rejection
$noTargetExec = LiveChatToolService::executeToolCall('toolsapi_whois_query', [], 0, null);
runTest(
    "Rejects tool call when target cannot be resolved",
    $noTargetExec['success'] === false && $noTargetExec['error'] === 'missing_target',
    json_encode($noTargetExec)
);

echo "\n================================================================================\n";
echo "                      VERIFICATION RESULTS SUMMARY\n";
echo "================================================================================\n";
echo "Total Tests Executed : {$totalTests}\n";
echo "Passed Tests         : {$passedTests}\n";
echo "Failed Tests         : {$failedTests}\n";

if ($failedTests === 0) {
    echo "\n>>> SUCCESS: ALL LIVE CHAT TOOL CALLING TESTS PASSED PERFECTLY! <<<\n";
    exit(0);
} else {
    echo "\n>>> FAILURE: {$failedTests} tests failed. <<<\n";
    exit(1);
}
