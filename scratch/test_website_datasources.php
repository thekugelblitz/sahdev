<?php

require_once __DIR__ . '/../lib/WebsiteDataSourcesService.php';

use Sahdev\Lib\WebsiteDataSourcesService;

echo "=== TEST 1: Parsing and Formatting Real JSON Summary ===\n";

// Sample test object mimicking https://hostingspell.com/ai-summary
$sampleJson = json_encode([
    'name' => 'HostingSpell',
    'url' => 'https://hostingspell.com',
    'company' => [
        'legal_name' => 'HostingSpell LLP',
        'founded_year' => 2015,
        'years_of_expertise' => 10,
        'tagline' => 'Guaranteed Most Affordable Web Hosting In The World!',
        'description' => 'HostingSpell is an India-based web hosting company offering affordable hosting.',
        'trusted_by_sites' => 110000,
        'awards' => ['Top 10 Resellers Hosting Provider']
    ],
    'infrastructure' => [
        'web_server' => 'LiteSpeed Enterprise',
        'storage_type' => 'Pure NVMe SSD',
        'cloud_providers' => ['DigitalOcean', 'Linode'],
        'security' => 'Imunify360 AI Anti-Virus',
        'uptime_sla' => '100% on Cloud & Premium plans',
        'backup' => 'Free daily automatic backups (JetBackup)'
    ],
    'key_features' => [
        '7-day Money Back Guarantee',
        'Free SSL certificates on all plans',
        'Pure NVMe SSD storage',
        'AI-powered support via Sahdev'
    ],
    'hosting_products' => [
        'cloud_hosting' => [
            'title' => 'Cloud Hosting',
            'description' => 'Affordable cloud hosting with LiteSpeed.',
            'plans' => [
                [
                    'name' => 'Venus',
                    'specs' => ['storage' => '1GB NVMe SSD', 'bandwidth' => '10GB', 'cpu' => '1 Core', 'ram' => '1GB'],
                    'pricing' => ['USD' => ['monthly' => 1.29, 'annual' => 12.99]],
                    'order_url' => 'https://manage.hostingspell.com/store/ssd-web-hosting/venus'
                ],
                [
                    'name' => 'Mars',
                    'specs' => ['storage' => '10GB NVMe SSD', 'bandwidth' => '100GB', 'cpu' => '1 Core', 'ram' => '1GB'],
                    'pricing' => ['USD' => ['monthly' => 1.69, 'annual' => 16.49]],
                    'order_url' => 'https://manage.hostingspell.com/store/ssd-web-hosting/mars'
                ]
            ]
        ]
    ],
    'billing_and_payment' => [
        'refund_policy' => '7-day money-back guarantee on all hosting plans',
        'payment_methods' => ['Visa', 'MasterCard', 'PayPal', 'UPI'],
        'free_migration' => '1 free cPanel migration for new customers'
    ],
    'faqs' => [
        [
            'question' => 'How can I get a refund?',
            'answer' => 'Create a sales ticket in the client area within 7 days.'
        ],
        [
            'question' => 'Do you provide migration support?',
            'answer' => 'Yes, 1 free cPanel migration is included.'
        ]
    ]
], JSON_PRETTY_PRINT);

$mockSource = (object)[
    'id' => 1,
    'name' => 'HostingSpell Main Website',
    'source_type' => 'url',
    'source_url' => 'https://hostingspell.com/ai-summary',
    'cached_content' => $sampleJson,
    'custom_content' => null,
    'is_enabled' => 1
];

$output = WebsiteDataSourcesService::formatWebsiteForPrompt($mockSource);
echo "Result:\n" . $output . "\n\n";

assert(strpos($output, 'HostingSpell') !== false, 'Output must contain HostingSpell');
assert(strpos($output, 'LiteSpeed Enterprise') !== false, 'Output must contain LiteSpeed');
assert(strpos($output, '7-day money-back guarantee') !== false, 'Output must contain refund policy');
assert(strpos($output, 'Venus') !== false, 'Output must contain Venus plan');
assert(strpos($output, 'How can I get a refund?') !== false, 'Output must contain FAQs');

echo "=== TEST 2: Testing Custom Input Markdown/Plain Text ===\n";
$mockCustomSource = (object)[
    'id' => 2,
    'name' => 'Custom Policy Addendum',
    'source_type' => 'custom',
    'source_url' => null,
    'cached_content' => null,
    'custom_content' => "Custom Presales Note: All servers support Node.js and Python via cPanel Setup Python App.",
    'is_enabled' => 1
];
$customOutput = WebsiteDataSourcesService::formatWebsiteForPrompt($mockCustomSource);
echo "Result:\n" . $customOutput . "\n\n";

assert(strpos($customOutput, 'Custom Policy Addendum') !== false, 'Custom output must contain label');
assert(strpos($customOutput, 'Setup Python App') !== false, 'Custom output must contain content');

echo "=== TEST 3: Testing Live Fetch of https://hostingspell.com/ai-summary ===\n";
$ch = curl_init('https://hostingspell.com/ai-summary');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_USERAGENT, 'Sahdev-AI-Bot/2.0');
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "HTTP Status: {$code} | Bytes: " . strlen($res) . "\n";
$liveJson = json_decode($res, true);
echo "Valid JSON: " . (is_array($liveJson) ? "YES" : "NO") . "\n";
if (is_array($liveJson)) {
    echo "Site Name from live JSON: " . ($liveJson['name'] ?? 'N/A') . "\n";
    $liveMock = (object)[
        'id' => 99,
        'name' => 'HostingSpell Live Website',
        'source_type' => 'url',
        'source_url' => 'https://hostingspell.com/ai-summary',
        'cached_content' => $res,
        'custom_content' => null,
        'is_enabled' => 1
    ];
    $formattedLive = WebsiteDataSourcesService::formatWebsiteForPrompt($liveMock);
    echo "Formatted Digest Length: " . strlen($formattedLive) . " chars\n";
    assert(strpos($formattedLive, 'HostingSpell') !== false);
}

echo "\nALL TESTS COMPLETED SUCCESSFULLY!\n";
