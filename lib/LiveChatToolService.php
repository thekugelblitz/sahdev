<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;
use Carbon\Carbon;
use Sahdev\Lib\ModuleLogger;

/**
 * Service for managing intelligent, cost-efficient, and secure ToolsAPI
 * tool calling automation within WHMCS Live Chat.
 *
 * Implements:
 * - OpenAPI registry caching and category segmentation
 * - Lightweight local pre-routing (zero extra AI classifier calls)
 * - Conversational entity tracking and follow-up context retention
 * - Result caching with endpoint-specific TTLs
 * - Concurrency deduplication and atomic request coalescing
 * - Server-side execution budgets and loop guards
 * - Strict SSRF validation and parameter boundary controls
 * - Compact tool response normalization for minimal LLM token consumption
 */
class LiveChatToolService
{
    public const DEFAULT_BASE_URL = 'https://toolsapi.2hs.in';
    public const OPENAPI_REF_FILE = 'toolsapi-2hs-in-openapi.json';
    private const UPSTREAM_TOTAL_TIMEOUT_SEC = 15;
    private const UPSTREAM_MAX_RETRIES = 3;

    public const CATEGORY_DNS      = 'dns';
    public const CATEGORY_WHOIS    = 'whois';
    public const CATEGORY_SSL      = 'ssl';
    public const CATEGORY_NETWORK  = 'network';
    public const CATEGORY_WEB      = 'web';
    public const CATEGORY_SECURITY = 'security';

    /**
     * In-memory cache for parsed OpenAPI tool definitions.
     * @var array|null
     */
    private static ?array $toolRegistryCache = null;

    /**
     * Mapping of endpoints to categories and default caching TTLs (seconds).
     */
    private static array $endpointMetadata = [
        // DNS
        'toolsapi_dns_lookup' => [
            'category'    => self::CATEGORY_DNS,
            'method'      => 'GET',
            'path'        => '/dns/{domain}',
            'ttl'         => 300,
            'description' => 'Resolve the IPv4 A record for a domain.',
        ],
        'toolsapi_dns_propagation' => [
            'category'    => self::CATEGORY_DNS,
            'method'      => 'GET',
            'path'        => '/dns-propagation/{domain}',
            'ttl'         => 300,
            'description' => 'Check DNS propagation across global DNS resolvers for a domain.',
        ],
        'toolsapi_dnssec_validator' => [
            'category'    => self::CATEGORY_DNS,
            'method'      => 'GET',
            'path'        => '/dnssec/{domain}',
            'ttl'         => 900,
            'description' => 'Validate DNSSEC configuration and DS records for a domain.',
        ],

        // WHOIS & Domains
        'toolsapi_whois_query' => [
            'category'    => self::CATEGORY_WHOIS,
            'method'      => 'GET',
            'path'        => '/whois/{domain}',
            'ttl'         => 14400, // 4 hours
            'description' => 'Query WHOIS registration, registrar, creation and expiry dates for a domain.',
        ],
        'toolsapi_domain_age' => [
            'category'    => self::CATEGORY_WHOIS,
            'method'      => 'GET',
            'path'        => '/domainAge/{domain}',
            'ttl'         => 86400, // 24 hours
            'description' => 'Calculate domain registration age and time until renewal.',
        ],
        'toolsapi_crtsh' => [
            'category'    => self::CATEGORY_WHOIS,
            'method'      => 'GET',
            'path'        => '/crtsh/{domain}',
            'ttl'         => 3600,
            'description' => 'Search Certificate Transparency logs to discover known subdomains and cert history.',
        ],

        // SSL
        'toolsapi_ssl_inspect' => [
            'category'    => self::CATEGORY_SSL,
            'method'      => 'GET',
            'path'        => '/ssl/{target}',
            'ttl'         => 1800, // 30 mins
            'description' => 'Inspect SSL/TLS certificate validity, issuer, SANs, and remaining days until expiry.',
        ],

        // Network Diagnostics
        'toolsapi_ping' => [
            'category'    => self::CATEGORY_NETWORK,
            'method'      => 'GET',
            'path'        => '/ping/{host}',
            'ttl'         => 60, // 1 min
            'description' => 'Ping a public host or IP address to measure packet loss and round-trip latency.',
        ],
        'toolsapi_traceroute' => [
            'category'    => self::CATEGORY_NETWORK,
            'method'      => 'GET',
            'path'        => '/traceroute/{host}',
            'ttl'         => 120,
            'description' => 'Trace route network hops to a public host or IP to identify routing issues.',
        ],
        'toolsapi_mtr' => [
            'category'    => self::CATEGORY_NETWORK,
            'method'      => 'GET',
            'path'        => '/mtr/{target}',
            'ttl'         => 120,
            'description' => 'Run MTR (My Traceroute) analysis combining ping and traceroute diagnostics.',
        ],
        'toolsapi_reverse_dns' => [
            'category'    => self::CATEGORY_NETWORK,
            'method'      => 'GET',
            'path'        => '/reverse/{ip}',
            'ttl'         => 1800,
            'description' => 'Perform reverse DNS (PTR record) lookup for a public IP address.',
        ],
        'toolsapi_geoip' => [
            'category'    => self::CATEGORY_NETWORK,
            'method'      => 'GET',
            'path'        => '/geoip/{ip}',
            'ttl'         => 86400,
            'description' => 'Lookup geographic location, country, city, and ISP for a public IP address.',
        ],
        'toolsapi_asn' => [
            'category'    => self::CATEGORY_NETWORK,
            'method'      => 'GET',
            'path'        => '/asn/{ip}',
            'ttl'         => 86400,
            'description' => 'Lookup Autonomous System Number (ASN) and BGP network organization for an IP.',
        ],
        'toolsapi_ipv6' => [
            'category'    => self::CATEGORY_NETWORK,
            'method'      => 'GET',
            'path'        => '/ipv6/{domain}',
            'ttl'         => 3600,
            'description' => 'Check IPv6 readiness and AAAA record connectivity for a domain.',
        ],

        // Web & Performance
        'toolsapi_http_headers' => [
            'category'    => self::CATEGORY_WEB,
            'method'      => 'GET',
            'path'        => '/headers/{target}',
            'ttl'         => 300,
            'description' => 'Inspect HTTP response status code, server headers, and redirects for a website.',
        ],
        'toolsapi_pagespeed' => [
            'category'    => self::CATEGORY_WEB,
            'method'      => 'GET',
            'path'        => '/pagespeed/{url}',
            'ttl'         => 1800,
            'description' => 'Measure Google PageSpeed performance score, FCP, LCP, and CLS for a webpage URL.',
        ],
        'toolsapi_techstack' => [
            'category'    => self::CATEGORY_WEB,
            'method'      => 'GET',
            'path'        => '/techstack/{url}',
            'ttl'         => 3600,
            'description' => 'Detect server technologies, web server, CMS, programming language, and frameworks.',
        ],
        'toolsapi_redirect' => [
            'category'    => self::CATEGORY_WEB,
            'method'      => 'GET',
            'path'        => '/redirect/{target}',
            'ttl'         => 600,
            'description' => 'Trace full URL redirect chains (301, 302, canonical hops) to the destination URL.',
        ],
        'toolsapi_robots' => [
            'category'    => self::CATEGORY_WEB,
            'method'      => 'GET',
            'path'        => '/robots/{target}',
            'ttl'         => 3600,
            'description' => 'Retrieve and inspect the robots.txt file and search engine crawl rules for a site.',
        ],
        'toolsapi_cms' => [
            'category'    => self::CATEGORY_WEB,
            'method'      => 'GET',
            'path'        => '/cms/{target}',
            'ttl'         => 3600,
            'description' => 'Identify content management system (WordPress, Joomla, Drupal, Shopify, etc.).',
        ],

        // Security & Email
        'toolsapi_emailsec' => [
            'category'    => self::CATEGORY_SECURITY,
            'method'      => 'GET',
            'path'        => '/emailsec/{domain}',
            'ttl'         => 600,
            'description' => 'Validate email authentication records: SPF configuration, DMARC policy, and MX setup.',
        ],
        'toolsapi_dnsbl' => [
            'category'    => self::CATEGORY_SECURITY,
            'method'      => 'GET',
            'path'        => '/dnsbl/{ip}',
            'ttl'         => 900,
            'description' => 'Check if a public IP is listed on major email spam blacklists (DNSBL / RBL).',
        ],
        'toolsapi_secheaders' => [
            'category'    => self::CATEGORY_SECURITY,
            'method'      => 'GET',
            'path'        => '/secheaders/{target}',
            'ttl'         => 1800,
            'description' => 'Audit security headers (HSTS, CSP, X-Frame-Options, X-Content-Type-Options).',
        ],
        'toolsapi_waf' => [
            'category'    => self::CATEGORY_SECURITY,
            'method'      => 'GET',
            'path'        => '/waf/{target}',
            'ttl'         => 3600,
            'description' => 'Detect active Web Application Firewall (Cloudflare, AWS WAF, Sucuri, etc.).',
        ],
        'toolsapi_cors' => [
            'category'    => self::CATEGORY_SECURITY,
            'method'      => 'GET',
            'path'        => '/cors/{target}',
            'ttl'         => 1800,
            'description' => 'Audit Cross-Origin Resource Sharing (CORS) headers and allowed origins configuration.',
        ],
    ];

    /**
     * Check if Live Chat Tool calling is enabled in settings.
     */
    public static function isEnabled(): bool
    {
        try {
            if (!class_exists('WHMCS\\Database\\Capsule')) {
                return true;
            }
            try {
                if (!Capsule::schema()->hasTable('tblsahdev_settings')) {
                    return true;
                }
            } catch (\Throwable $t) {
                return true; // Unit test / standalone CLI fallback
            }

            $settings = Capsule::table('tblsahdev_settings')->first();
            if (!$settings) {
                return true;
            }

            // Explicit client_chat_tools_enabled setting, fallback to general tools_execution_enabled
            if (isset($settings->client_chat_tools_enabled)) {
                return (int) $settings->client_chat_tools_enabled === 1;
            }
            return (int) ($settings->tools_execution_enabled ?? 1) === 1;
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Maximum tool executions allowed per turn.
     */
    public static function getMaxExecutionsPerTurn(): int
    {
        try {
            $settings = Capsule::table('tblsahdev_settings')->first();
            if ($settings && isset($settings->client_chat_tool_max_executions)) {
                return max(1, min(5, (int) $settings->client_chat_tool_max_executions));
            }
        } catch (\Throwable $e) {}
        return 2;
    }

    /**
     * Default cache TTL in seconds.
     */
    public static function getDefaultCacheTtl(): int
    {
        try {
            $settings = Capsule::table('tblsahdev_settings')->first();
            if ($settings && isset($settings->client_chat_tool_cache_ttl)) {
                return max(60, min(86400, (int) $settings->client_chat_tool_cache_ttl));
            }
        } catch (\Throwable $e) {}
        return 900;
    }

    /**
     * Get or build the cached OpenAPI Tool Registry conforming to OpenAI function calling format.
     * Caches in memory to ensure the OpenAPI specification is never parsed on every request.
     *
     * @return array Array of tools in OpenAI format: ['type' => 'function', 'function' => [...]]
     */
    public static function getToolRegistry(): array
    {
        if (self::$toolRegistryCache !== null) {
            return self::$toolRegistryCache;
        }

        $registry = [];
        $rawOpenApi = self::loadOpenApiSpec();
        $openApiJson = !empty($rawOpenApi) ? json_decode($rawOpenApi, true) : null;
        $paths = $openApiJson['paths'] ?? [];

        foreach (self::$endpointMetadata as $toolName => $meta) {
            $pathPattern = $meta['path'];
            $method = strtolower($meta['method']);
            $openApiOp = $paths[$pathPattern][$method] ?? null;

            $properties = [];
            $required = [];

            if ($openApiOp && isset($openApiOp['parameters']) && is_array($openApiOp['parameters'])) {
                foreach ($openApiOp['parameters'] as $param) {
                    $pName = (string) ($param['name'] ?? '');
                    if ($pName === '') {
                        continue;
                    }
                    $pType = (string) ($param['schema']['type'] ?? 'string');
                    $pDesc = (string) ($param['description'] ?? ($param['schema']['description'] ?? ''));

                    $propDef = [
                        'type'        => $pType,
                        'description' => $pDesc !== '' ? $pDesc : "Target {$pName} parameter",
                    ];
                    if (isset($param['schema']['default'])) {
                        $propDef['default'] = $param['schema']['default'];
                    }

                    $properties[$pName] = $propDef;
                    if (!empty($param['required'])) {
                        $required[] = $pName;
                    }
                }
            } else {
                // Fallback deterministic property extraction from path
                preg_match_all('/\{([^}]+)\}/', $pathPattern, $matches);
                foreach ($matches[1] as $pName) {
                    $properties[$pName] = [
                        'type'        => 'string',
                        'description' => "Target {$pName}",
                    ];
                    $required[] = $pName;
                }
            }

            if ($toolName === 'toolsapi_dns_lookup') {
                // This client chat tool is intentionally IPv4-only. Keep the model's
                // schema aligned with the server-side enforcement below.
                $properties['type'] = [
                    'type'        => 'string',
                    'description' => 'Always use A (IPv4) records. Other record types are not supported by this tool.',
                    'enum'        => ['A'],
                ];
            }

            $registry[$toolName] = [
                'type'     => 'function',
                'function' => [
                    'name'        => $toolName,
                    'description' => $meta['description'],
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => $properties,
                        'required'   => $required,
                    ],
                ],
                '_meta' => $meta,
            ];
        }

        self::$toolRegistryCache = $registry;
        return self::$toolRegistryCache;
    }

    /**
     * Pre-router: Determines if an incoming chat message requires external ToolsAPI functions.
     * Evaluates local heuristics without making an expensive or slow AI classifier request.
     *
     * @param string $messageText The visitor's incoming message
     * @param int $sessionId The active live chat session ID
     * @param int|null $clientId The authenticated client ID (if any)
     * @return array ['needs_tools' => bool, 'categories' => array, 'tools' => array, 'target' => ?string]
     */
    public static function evaluatePreRoute(string $messageText, int $sessionId, ?int $clientId = null): array
    {
        if (!self::isEnabled()) {
            return ['needs_tools' => false, 'categories' => [], 'tools' => [], 'target' => null];
        }

        $text = mb_strtolower(trim($messageText));
        $len = mb_strlen($text);

        // 1. Definite Non-Tool Queries: Small talk, greetings, acknowledgements
        if ($len <= 45) {
            $cleanText = trim(preg_replace('/[^a-z0-9\s]/', '', $text));
            $conversationalPhrases = [
                'hi', 'hello', 'hey', 'greetings', 'good morning', 'good afternoon', 'good evening',
                'thanks', 'thank you', 'thx', 'got it', 'understood', 'ok', 'okay', 'great', 'awesome',
                'perfect', 'cool', 'sure', 'alright', 'bye', 'goodbye', 'see you', 'who are you',
                'what can you do', 'help', 'test', 'got it thanks', 'thanks a lot', 'thank you so much'
            ];
            if (in_array($cleanText, $conversationalPhrases, true)) {
                return ['needs_tools' => false, 'categories' => [], 'tools' => [], 'target' => null];
            }
        }

        // 2. Definite Non-Tool Queries: Billing, payments, invoices, plan checkout
        $isBillingOnly = (bool) preg_match('/\b(pay|payment|invoice|invoices|bill|billing|due|receipt|refund|money back|credit card|paypal|bank transfer)\b/i', $text)
            && !preg_match('/\b(dns|ssl|ping|traceroute|domain|nameserver|record|server|ip|status)\b/i', $text);
        if ($isBillingOnly) {
            return ['needs_tools' => false, 'categories' => [], 'tools' => [], 'target' => null];
        }

        // 3. Category Detection Rules
        $matchedCategories = [];

        // DNS checks
        if (preg_match('/\b(dns|nameserver|nameservers|ns record|mx record|txt record|a record|cname|propagation|propagate|dnssec)\b/i', $text)) {
            $matchedCategories[] = self::CATEGORY_DNS;
        }

        // WHOIS checks
        if (preg_match('/\b(whois|domain age|registrar|domain expiration|expire date|expiry date|registered on|domain status)\b/i', $text)) {
            $matchedCategories[] = self::CATEGORY_WHOIS;
        }

        // SSL checks
        if (preg_match('/\b(ssl|certificate|cert|https|tls|ssl expiry|ssl expiration|ssl valid|invalid certificate)\b/i', $text)) {
            $matchedCategories[] = self::CATEGORY_SSL;
        }

        // Network checks
        if (preg_match('/\b(ping|latency|packet loss|traceroute|mtr|route|ip lookup|geoip|asn|location of ip|server down|down or just me|port|reverse dns|ptr record|ipv6)\b/i', $text)) {
            $matchedCategories[] = self::CATEGORY_NETWORK;
        }

        // Web & Performance checks
        if (preg_match('/\b(pagespeed|page speed|load time|slow website|performance score|http headers|status code|redirect|redirects|techstack|tech stack|cms|wordpress version|robots\.txt|robots txt)\b/i', $text)) {
            $matchedCategories[] = self::CATEGORY_WEB;
        }

        // Security checks
        if (preg_match('/\b(email security|spf|dmarc|dkim|blacklist|blacklisted|dnsbl|spam list|rbl|security headers|waf|firewall|cors)\b/i', $text)) {
            $matchedCategories[] = self::CATEGORY_SECURITY;
        }

        // 4. Target Extraction & Context Resolution
        $entities = self::extractEntities($messageText);
        $target = $entities['domains'][0] ?? ($entities['ips'][0] ?? ($entities['urls'][0] ?? null));

        // If no target in current turn, attempt to resolve entity from recent conversation history or client profile
        if (empty($target) && !empty($matchedCategories)) {
            $target = self::resolveContextTarget($sessionId, $clientId);
        }

        // If diagnostic category matched, expose the relevant tool subset
        if (!empty($matchedCategories)) {
            $categoryTools = self::getToolsForCategories($matchedCategories);
            return [
                'needs_tools' => !empty($categoryTools),
                'categories'  => $matchedCategories,
                'tools'       => $categoryTools,
                'target'      => $target,
            ];
        }

        // If user provided a raw domain/IP with an implicit status check (e.g. "Check example.com", "is 1.2.3.4 up?")
        if (!empty($target) && preg_match('/\b(check|status|inspect|analyze|test|lookup|info|details|working|live|up)\b/i', $text)) {
            $defaultCategories = [self::CATEGORY_DNS, self::CATEGORY_SSL, self::CATEGORY_NETWORK];
            return [
                'needs_tools' => true,
                'categories'  => $defaultCategories,
                'tools'       => self::getToolsForCategories($defaultCategories),
                'target'      => $target,
            ];
        }

        return ['needs_tools' => false, 'categories' => [], 'tools' => [], 'target' => null];
    }

    /**
     * Retrieve tool definitions for specific categories, preventing prompt bloat by omitting unrelated tools.
     *
     * @param array $categories Array of category strings (e.g. ['dns', 'ssl'])
     * @return array OpenAI format tools array
     */
    public static function getToolsForCategories(array $categories): array
    {
        $registry = self::getToolRegistry();
        $filtered = [];

        foreach ($registry as $toolName => $entry) {
            $cat = $entry['_meta']['category'] ?? '';
            if (in_array($cat, $categories, true)) {
                $filtered[] = [
                    'type'     => 'function',
                    'function' => $entry['function'],
                ];
            }
        }

        return $filtered;
    }

    /**
     * Extract network entities (domains, IPs, URLs) from text.
     *
     * @param string $text
     * @return array ['domains' => [], 'ips' => [], 'urls' => []]
     */
    public static function extractEntities(string $text): array
    {
        preg_match_all('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $text, $ipMatches);
        preg_match_all('/\bhttps?:\/\/[^\s<>"\']+/i', $text, $urlMatches);
        // Clean domain match: must have at least one dot, valid ASCII letters, and not look like file extensions or email prefixes
        preg_match_all('/\b([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z]{2,})+)\b/i', $text, $domainMatches);

        $ips = array_values(array_unique($ipMatches[0] ?? []));
        $urls = array_values(array_unique($urlMatches[0] ?? []));
        $rawDomains = array_values(array_unique(array_map('strtolower', $domainMatches[1] ?? [])));
        $fileExtensions = ['php', 'html', 'htm', 'js', 'css', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'pdf', 'txt', 'json', 'zip', 'tar', 'gz', 'xml', 'md'];
        $domains = array_values(array_filter($rawDomains, function ($d) use ($fileExtensions) {
            $parts = explode('.', $d);
            $last = end($parts);
            return !in_array($last, $fileExtensions, true);
        }));

        return [
            'ips'     => $ips,
            'urls'    => $urls,
            'domains' => $domains,
        ];
    }

    /**
     * Resolve target entity (domain or IP) from recent conversation history or client account context.
     * Preserves conversational context across turns (e.g. "What about its SSL certificate?").
     *
     * @param int $sessionId
     * @param int|null $clientId
     * @return string|null
     */
    public static function resolveContextTarget(int $sessionId, ?int $clientId = null): ?string
    {
        try {
            // 1. Inspect last 6 messages in the session
            $recentMessages = Capsule::table('tblsahdev_chat_messages')
                ->where('session_id', $sessionId)
                ->orderBy('id', 'desc')
                ->limit(6)
                ->pluck('message_text')
                ->all();

            foreach ($recentMessages as $msg) {
                $entities = self::extractEntities((string) $msg);
                if (!empty($entities['domains'][0])) {
                    return $entities['domains'][0];
                }
                if (!empty($entities['ips'][0])) {
                    return $entities['ips'][0];
                }
                if (!empty($entities['urls'][0])) {
                    $parsed = parse_url($entities['urls'][0], PHP_URL_HOST);
                    if (!empty($parsed)) {
                        return $parsed;
                    }
                }
            }

            // 2. If client is authenticated, check their primary active domain
            if ($clientId && $clientId > 0) {
                $primaryDomain = Capsule::table('tbldomains')
                    ->where('userid', $clientId)
                    ->where('status', 'Active')
                    ->orderBy('id', 'desc')
                    ->value('domain');
                if (!empty($primaryDomain)) {
                    return strtolower(trim($primaryDomain));
                }

                $hostingDomain = Capsule::table('tblhosting')
                    ->where('userid', $clientId)
                    ->where('domainstatus', 'Active')
                    ->whereNotNull('domain')
                    ->where('domain', '!=', '')
                    ->orderBy('id', 'desc')
                    ->value('domain');
                if (!empty($hostingDomain)) {
                    return strtolower(trim($hostingDomain));
                }
            }
        } catch (\Throwable $e) {
            // Non-fatal context resolution failure
        }

        return null;
    }

    /**
     * Strict SSRF and argument security validation.
     * Validates target against private IP ranges, loopback, link-local, and WHMCS internal hostnames.
     *
     * @param string $target Target host, domain, IP, or URL
     * @return array ['valid' => bool, 'sanitized' => string, 'error' => ?string]
     */
    public static function validateTargetSecurity(string $target): array
    {
        $target = trim($target);
        if ($target === '' || strlen($target) > 255) {
            return ['valid' => false, 'sanitized' => '', 'error' => 'Target is empty or exceeds 255 characters.'];
        }

        // If target is a full URL, extract hostname
        if (preg_match('#^https?://#i', $target)) {
            $parsed = parse_url($target);
            if (empty($parsed['scheme']) || !in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
                return ['valid' => false, 'sanitized' => '', 'error' => 'Only HTTP and HTTPS protocols are permitted.'];
            }
            $host = $parsed['host'] ?? '';
        } else {
            // Strip protocol or paths if loosely formatted
            $host = preg_replace('#^https?://#i', '', $target);
            $host = explode('/', $host)[0];
            $host = explode(':', $host)[0]; // strip port
        }

        $host = strtolower(trim($host));
        if ($host === '') {
            return ['valid' => false, 'sanitized' => '', 'error' => 'Unable to resolve target host.'];
        }

        // 1. Loopback and internal name rejection
        $disallowedHosts = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];
        if (in_array($host, $disallowedHosts, true) || preg_match('/\.(local|internal|lan|corp|home|arpa)$/i', $host)) {
            return ['valid' => false, 'sanitized' => '', 'error' => "Access to local and internal addresses is restricted ({$host})."];
        }

        // 2. Reject current WHMCS host to prevent internal loops
        try {
            $whmcsUrl = ChatService::getWhmcsSystemUrl();
            if (!empty($whmcsUrl)) {
                $whmcsHost = strtolower((string) parse_url($whmcsUrl, PHP_URL_HOST));
                if (!empty($whmcsHost) && $host === $whmcsHost) {
                    return ['valid' => false, 'sanitized' => '', 'error' => 'Self-referential host diagnostics are prohibited.'];
                }
            }
        } catch (\Throwable $e) {}

        // 3. IP address validation (RFC-1918, RFC-3927, loopback, private ranges)
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return ['valid' => false, 'sanitized' => '', 'error' => "Target IP address falls within a private or reserved range ({$host})."];
            }
        } else {
            // Domain validation & DNS rebinding protection: resolve domain to verify it doesn't map to private IP
            if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/i', $host)) {
                return ['valid' => false, 'sanitized' => '', 'error' => "Invalid domain syntax ({$host})."];
            }

            // Verify DNS resolution doesn't resolve to private range
            $resolvedIps = @gethostbynamel($host);
            if (is_array($resolvedIps)) {
                foreach ($resolvedIps as $rip) {
                    if (!filter_var($rip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        return ['valid' => false, 'sanitized' => '', 'error' => "Target domain resolves to a private or loopback IP ({$rip})."];
                    }
                }
            }
        }

        return ['valid' => true, 'sanitized' => $host, 'error' => null];
    }

    /**
     * Dispatch and execute a single tool call requested by the AI.
     * Enforces schema validation, SSRF checks, caching, deduplication, and output normalization.
     *
     * @param string $toolName Function name (e.g. 'toolsapi_dns_lookup')
     * @param array $args Tool arguments passed by AI
     * @param int $sessionId Live chat session ID
     * @param int|null $clientId Client ID
     * @return array ['success' => bool, 'summary' => string, 'cached' => bool, 'error' => ?string]
     */
    public static function executeToolCall(string $toolName, array $args, int $sessionId, ?int $clientId = null): array
    {
        $registry = self::getToolRegistry();
        if (!isset($registry[$toolName])) {
            return [
                'success' => false,
                'summary' => "Error: Tool '{$toolName}' is not registered or allowed.",
                'cached'  => false,
                'error'   => 'unregistered_tool',
            ];
        }

        $meta = $registry[$toolName]['_meta'];
        $pathPattern = $meta['path'];
        $method = strtoupper($meta['method']);
        $ttl = $meta['ttl'] ?? self::getDefaultCacheTtl();

        // 1. Identify and sanitize primary target parameter (domain, host, target, ip, url)
        $targetParamName = 'domain';
        if (isset($args['target'])) {
            $targetParamName = 'target';
        } elseif (isset($args['host'])) {
            $targetParamName = 'host';
        } elseif (isset($args['ip'])) {
            $targetParamName = 'ip';
        } elseif (isset($args['url'])) {
            $targetParamName = 'url';
        } elseif (isset($args['domain'])) {
            $targetParamName = 'domain';
        }

        $rawTarget = (string) ($args[$targetParamName] ?? '');
        if ($rawTarget === '') {
            // Attempt to resolve target from context if omitted by model
            $contextTarget = self::resolveContextTarget($sessionId, $clientId);
            if (!empty($contextTarget)) {
                $rawTarget = $contextTarget;
                $args[$targetParamName] = $rawTarget;
            } else {
                return [
                    'success' => false,
                    'summary' => "Error: Missing required target parameter for {$toolName}.",
                    'cached'  => false,
                    'error'   => 'missing_target',
                ];
            }
        }

        // 2. Strict SSRF Guard
        $secCheck = self::validateTargetSecurity($rawTarget);
        if (!$secCheck['valid']) {
            return [
                'success' => false,
                'summary' => "Security Notice: " . $secCheck['error'],
                'cached'  => false,
                'error'   => 'ssrf_blocked',
            ];
        }

        $sanitizedTarget = $secCheck['sanitized'];
        $args[$targetParamName] = $sanitizedTarget;

        // 3. Cache & Deduplication Check
        $cacheKey = self::generateCacheKey($toolName, $args);
        $cachedResult = self::getCachedResult($cacheKey);
        if ($cachedResult !== null) {
            ModuleLogger::info('client_chat_tools', "Cache HIT for {$toolName} [target: {$sanitizedTarget}]");
            return [
                'success' => true,
                'summary' => $cachedResult['normalized_summary'],
                'data'    => $cachedResult['data'],
                'cached'  => true,
                'error'   => null,
            ];
        }

        // 4. In-Flight Request Deduplication / Concurrency Lock
        $locked = self::acquireLock($cacheKey);
        if (!$locked) {
            // Another concurrent process is executing this exact call; wait briefly for its completion
            for ($i = 0; $i < 6; $i++) {
                usleep(250000); // 250ms
                $cachedResult = self::getCachedResult($cacheKey);
                if ($cachedResult !== null) {
                    ModuleLogger::info('client_chat_tools', "Coalesced in-flight duplicate for {$toolName} [target: {$sanitizedTarget}]");
                    return [
                        'success' => true,
                        'summary' => $cachedResult['normalized_summary'],
                        'data'    => $cachedResult['data'],
                        'cached'  => true,
                        'error'   => null,
                    ];
                }
            }
        }

        // 5. Upstream HTTP Execution
        try {
            $startTime = microtime(true);
            $httpResult = self::executeUpstreamHttp($method, $pathPattern, $args);
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

            $httpStatus = (int) ($httpResult['http_status'] ?? 0);
            $rawBody = (string) ($httpResult['body'] ?? '');

            if ($httpResult['status'] !== 'ok' || $httpStatus >= 400) {
                self::releaseLock($cacheKey);
                $errDetail = !empty($httpResult['error']) ? $httpResult['error'] : "HTTP {$httpStatus}";
                $execUrl = $httpResult['url'] ?? '';
                $bodyExcerpt = substr(trim($rawBody), 0, 300);
                $attempts = (int) ($httpResult['attempts'] ?? 1);
                ModuleLogger::warning('client_chat_tools', "ToolsAPI execution failed for {$toolName} [{$sanitizedTarget}] after {$attempts} attempt(s): {$errDetail} - URL: {$execUrl}" . ($bodyExcerpt !== '' ? " - Response: {$bodyExcerpt}" : ''));
                return [
                    'success' => false,
                    'summary' => "Diagnostic check for {$sanitizedTarget} returned an error ({$errDetail}).",
                    'cached'  => false,
                    'error'   => $errDetail,
                ];
            }

            if ((int) ($httpResult['attempts'] ?? 1) > 1) {
                ModuleLogger::info('client_chat_tools', "ToolsAPI recovered for {$toolName} [{$sanitizedTarget}] after " . (int) $httpResult['attempts'] . " attempts (HTTP {$httpStatus})");
            }

            // 6. Compact Output Normalization
            $normalizedSummary = self::normalizeOutput($toolName, $pathPattern, $rawBody, $sanitizedTarget);

            // 7. Store in Persistent Cache
            self::storeCache($cacheKey, $toolName, $sanitizedTarget, $args, $rawBody, $normalizedSummary, $ttl);

            // 8. Record Diagnostic Log
            try {
                if (Capsule::schema()->hasTable('tblsahdev_module_logs')) {
                    Capsule::table('tblsahdev_module_logs')->insert([
                        'level'      => 'info',
                        'source'     => 'LiveChatToolService',
                        'message'    => "Executed {$toolName} for [{$sanitizedTarget}] in {$latencyMs}ms (HTTP {$httpStatus})",
                        'ticket_id'  => null,
                        'created_at' => Carbon::now(),
                    ]);
                }
            } catch (\Throwable $e) {}

            return [
                'success' => true,
                'summary' => $normalizedSummary,
                'data'    => json_decode($rawBody, true),
                'cached'  => false,
                'error'   => null,
            ];
        } catch (\Throwable $e) {
            self::releaseLock($cacheKey);
            ModuleLogger::error('client_chat_tools', "Exception executing {$toolName}: " . $e->getMessage());
            return [
                'success' => false,
                'summary' => "Diagnostic execution encountered an error: " . $e->getMessage(),
                'cached'  => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Compact and normalize raw JSON response into lean structured text for LLM consumption.
     */
    public static function normalizeOutput(string $toolName, string $path, string $rawBody, string $target): string
    {
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            return "Response for {$target}: " . substr(trim($rawBody), 0, 500);
        }

        switch ($toolName) {
            case 'toolsapi_dns_lookup':
                $records = $decoded['records'] ?? [];
                if (empty($records)) {
                    return "DNS lookup for {$target}: No records found.";
                }
                $lines = [];
                foreach (array_slice($records, 0, 8) as $rec) {
                    $type = $rec['type'] ?? 'RECORD';
                    $val = $rec['value'] ?? ($rec['data'] ?? '');
                    $lines[] = "- {$type}: {$val}";
                }
                return "DNS Records for {$target}:\n" . implode("\n", $lines);

            case 'toolsapi_dns_propagation':
                $servers = $decoded['servers'] ?? ($decoded['results'] ?? []);
                $total = count($servers);
                $resolved = 0;
                foreach ($servers as $srv) {
                    if (!empty($srv['records']) || (!empty($srv['status']) && $srv['status'] === 'ok')) {
                        $resolved++;
                    }
                }
                return "DNS Propagation for {$target}: {$resolved}/{$total} global resolvers confirmed active propagation.";

            case 'toolsapi_ssl_inspect':
                $valid = !empty($decoded['is_valid']) ? 'VALID' : 'INVALID/EXPIRED';
                $days = $decoded['days_remaining'] ?? 'unknown';
                $issuer = $decoded['issuer']['commonName'] ?? ($decoded['issuer'] ?? 'Unknown');
                $expiry = $decoded['valid_to'] ?? ($decoded['expiry_date'] ?? 'unknown');
                return "SSL Inspection for {$target}:\n- Status: {$valid}\n- Issuer: {$issuer}\n- Days Remaining: {$days}\n- Expires: {$expiry}";

            case 'toolsapi_whois_query':
                $registrar = $decoded['registrar'] ?? 'Unknown';
                $created = $decoded['creation_date'] ?? 'Unknown';
                $expiry = $decoded['expiration_date'] ?? 'Unknown';
                $status = is_array($decoded['status'] ?? null) ? implode(', ', array_slice($decoded['status'], 0, 3)) : ($decoded['status'] ?? 'Active');
                $ns = is_array($decoded['nameservers'] ?? null) ? implode(', ', $decoded['nameservers']) : ($decoded['nameservers'] ?? 'None');
                return "WHOIS Details for {$target}:\n- Registrar: {$registrar}\n- Created: {$created}\n- Expires: {$expiry}\n- Status: {$status}\n- Nameservers: {$ns}";

            case 'toolsapi_domain_age':
                $age = $decoded['age_human'] ?? ($decoded['age_days'] ? "{$decoded['age_days']} days" : 'Unknown');
                $created = $decoded['created'] ?? 'Unknown';
                return "Domain Age for {$target}: Registered on {$created} ({$age} old).";

            case 'toolsapi_ping':
                $loss = $decoded['packet_loss'] ?? ($decoded['loss_percentage'] ?? '0%');
                $avgRtt = $decoded['avg_rtt'] ?? ($decoded['rtt_avg'] ?? 'N/A');
                $status = ($loss === '100%' || $loss === 100) ? 'HOST UNREACHABLE' : 'HOST REACHABLE';
                return "Ping Diagnostics for {$target}:\n- Status: {$status}\n- Packet Loss: {$loss}\n- Round-Trip Average: {$avgRtt} ms";

            case 'toolsapi_traceroute':
                $hops = $decoded['hops'] ?? [];
                $count = count($hops);
                $finalHop = !empty($hops) ? end($hops) : null;
                $finalIp = $finalHop['ip'] ?? 'target reached';
                return "Traceroute to {$target}: Completed in {$count} hops. Destination reached at {$finalIp}.";

            case 'toolsapi_pagespeed':
                $score = $decoded['performance_score'] ?? ($decoded['score'] ?? 'N/A');
                $strategy = $decoded['strategy'] ?? 'mobile';
                $fcp = $decoded['first_contentful_paint'] ?? 'N/A';
                $lcp = $decoded['largest_contentful_paint'] ?? 'N/A';
                return "PageSpeed ({$strategy}) for {$target}:\n- Performance Score: {$score}/100\n- First Contentful Paint: {$fcp}\n- Largest Contentful Paint: {$lcp}";

            case 'toolsapi_techstack':
                $techs = $decoded['technologies'] ?? ($decoded['tech_stack'] ?? []);
                $list = [];
                if (is_array($techs)) {
                    foreach (array_slice($techs, 0, 6) as $t) {
                        $name = is_array($t) ? ($t['name'] ?? '') : (string) $t;
                        if ($name !== '') $list[] = $name;
                    }
                }
                $techStr = !empty($list) ? implode(', ', $list) : 'Standard Web Stack';
                return "Technology Stack for {$target}: {$techStr}";

            case 'toolsapi_emailsec':
                $spf = !empty($decoded['has_spf']) || !empty($decoded['spf_record']) ? 'CONFIGURED' : 'MISSING';
                $dmarc = !empty($decoded['has_dmarc']) || !empty($decoded['dmarc_record']) ? 'CONFIGURED' : 'MISSING';
                $policy = $decoded['dmarc_policy'] ?? 'none';
                return "Email Security for {$target}:\n- SPF: {$spf}\n- DMARC: {$dmarc} (Policy: {$policy})";

            case 'toolsapi_dnsbl':
                $listed = !empty($decoded['is_listed']) ? 'LISTED ON BLACKLIST' : 'CLEAN (Not Blacklisted)';
                $count = $decoded['listed_count'] ?? 0;
                return "IP Reputation (DNSBL) for {$target}: {$listed} ({$count} detections).";

            default:
                // Generic compact key-value summary
                $keys = array_slice(array_keys($decoded), 0, 8);
                $summaryPairs = [];
                foreach ($keys as $k) {
                    $val = $decoded[$k];
                    if (is_scalar($val)) {
                        $summaryPairs[] = "- {$k}: {$val}";
                    }
                }
                return "Diagnostic Result for {$target}:\n" . implode("\n", $summaryPairs);
        }
    }

    /**
     * Upstream HTTP client with authentication and path parameter binding.
     */
    public static function buildUpstreamUrl(string $baseUrl, string $pathPattern, array $args, array &$consumedKeys = [], array &$queryParams = []): string
    {
        $consumedKeys = [];

        $finalPath = preg_replace_callback('/(?:\{|%7B)([^}%]+)(?:\}|%7D)/i', function ($m) use ($args, &$consumedKeys) {
            $key = $m[1];
            $lowerKey = strtolower($key);

            // 1. Direct match in $args
            foreach ($args as $ak => $av) {
                if (strtolower((string) $ak) === $lowerKey && trim((string) $av) !== '') {
                    $consumedKeys[(string) $ak] = true;
                    return rawurlencode(trim((string) $av));
                }
            }

            // 2. Domain / Host / Target / URL alias resolution
            if (in_array($lowerKey, ['domain', 'target', 'host', 'hostname', 'url'], true)) {
                $candidateKeys = ['domain', 'target', 'host', 'hostname', 'url'];
                foreach ($candidateKeys as $ck) {
                    if (isset($args[$ck]) && trim((string) $args[$ck]) !== '') {
                        $consumedKeys[$ck] = true;
                        $val = trim((string) $args[$ck]);
                        // If path expects domain or host, strip http:// or https:// and paths
                        if (in_array($lowerKey, ['domain', 'host', 'hostname'], true) && preg_match('#^https?://#i', $val)) {
                            $parsedHost = parse_url($val, PHP_URL_HOST);
                            if (!empty($parsedHost)) {
                                $val = $parsedHost;
                            }
                        }
                        return rawurlencode($val);
                    }
                }
            }

            // 3. IP alias resolution
            if (in_array($lowerKey, ['ip', 'host', 'target'], true)) {
                $candidateKeys = ['ip', 'host', 'target'];
                foreach ($candidateKeys as $ck) {
                    if (isset($args[$ck]) && trim((string) $args[$ck]) !== '') {
                        $consumedKeys[$ck] = true;
                        return rawurlencode(trim((string) $args[$ck]));
                    }
                }
            }

            // 4. CIDR alias resolution
            if ($lowerKey === 'cidr') {
                if (isset($args['cidr']) && trim((string) $args['cidr']) !== '') {
                    $consumedKeys['cidr'] = true;
                    return rawurlencode(trim((string) $args['cidr']));
                }
            }

            return $m[0]; // If unresolvable, leave intact
        }, $pathPattern);

        // Build query parameters, strictly excluding consumed path parameters
        $queryParams = [];
        foreach ($args as $ak => $av) {
            if (!isset($consumedKeys[(string) $ak])) {
                $queryParams[$ak] = $av;
            }
        }

        // Live chat DNS lookup is IPv4-only, even if the model requests AAAA or
        // another DNS record type. Explicitly send A so upstream behavior is stable.
        if (preg_match('#^/dns/[^/?]+$#i', $finalPath)) {
            $queryParams['type'] = 'A';
        }

        $url = rtrim($baseUrl, '/') . $finalPath;
        if (!empty($queryParams)) {
            $qs = http_build_query($queryParams);
            $url .= (strpos($url, '?') === false ? '?' : '&') . $qs;
        }

        return $url;
    }

    /**
     * Upstream HTTP client with authentication and path parameter binding.
     */
    private static function executeUpstreamHttp(string $method, string $pathPattern, array $args): array
    {
        try {
            $settings = Capsule::table('tblsahdev_settings')->first();
        } catch (\Throwable $e) {
            throw new \RuntimeException('Unable to load ToolsAPI settings from tblsahdev_settings.', 0, $e);
        }
        if (!$settings) {
            throw new \RuntimeException('ToolsAPI settings are not configured.');
        }

        $baseUrl = rtrim((string) ($settings->tools_api_base_url ?: self::DEFAULT_BASE_URL), '/');
        $apiKeyEncrypted = (string) ($settings->tools_api_key_encrypted ?? '');
        if ($apiKeyEncrypted === '') {
            throw new \RuntimeException('ToolsAPI API key is missing. Configure it in the Tools Execution settings.');
        }
        if (!function_exists('decrypt')) {
            throw new \RuntimeException('WHMCS decrypt() is unavailable; refusing to send the encrypted ToolsAPI key.');
        }
        try {
            $apiKey = trim((string) decrypt($apiKeyEncrypted));
        } catch (\Throwable $e) {
            throw new \RuntimeException('Unable to decrypt the configured ToolsAPI API key.', 0, $e);
        }
        if ($apiKey === '') {
            throw new \RuntimeException('The configured ToolsAPI API key decrypted to an empty value.');
        }

        $consumedKeys = [];
        $queryParams = [];
        $url = self::buildUpstreamUrl($baseUrl, $pathPattern, $args, $consumedKeys, $queryParams);

        // Keep retries inside the interactive SLA instead of allowing each retry a fresh 15 seconds.
        $startedAt = microtime(true);
        $deadline = $startedAt + self::UPSTREAM_TOTAL_TIMEOUT_SEC;
        $configuredRetries = (int) ($settings->tools_request_retry_count ?? self::UPSTREAM_MAX_RETRIES);
        $retryLimit = max(0, min(self::UPSTREAM_MAX_RETRIES, $configuredRetries));
        $attemptLimit = $retryLimit + 1;
        $result = [
            'status'      => 'error',
            'http_status' => 0,
            'body'        => '',
            'error'       => 'ToolsAPI request did not start.',
            'url'         => $url,
            'attempts'    => 0,
        ];

        for ($attempt = 1; $attempt <= $attemptLimit; $attempt++) {
            $remainingMs = (int) floor(($deadline - microtime(true)) * 1000);
            if ($remainingMs <= 0) {
                if ((int) ($result['attempts'] ?? 0) === 0) {
                    $result['error'] = 'ToolsAPI request exceeded the 15-second live chat deadline.';
                }
                break;
            }

            // Give this attempt the remaining SLA budget; quick transient failures can still retry within it.
            $requestTimeoutMs = $remainingMs;
            $ch = curl_init($url);
            if ($ch === false) {
                $result['error'] = 'Unable to initialize cURL for the ToolsAPI request.';
                break;
            }
            $responseHeaders = [];

            $optionsSet = curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER    => true,
                CURLOPT_TIMEOUT_MS         => $requestTimeoutMs,
                CURLOPT_CONNECTTIMEOUT_MS  => min(5000, $requestTimeoutMs),
                CURLOPT_SSL_VERIFYPEER    => true,
                CURLOPT_SSL_VERIFYHOST    => 2,
                CURLOPT_HEADERFUNCTION    => static function ($handle, string $headerLine) use (&$responseHeaders): int {
                    $headerLength = strlen($headerLine);
                    $headerParts = explode(':', $headerLine, 2);
                    if (count($headerParts) === 2) {
                        $responseHeaders[strtolower(trim($headerParts[0]))] = trim($headerParts[1]);
                    }
                    return $headerLength;
                },
                CURLOPT_HTTPHEADER        => [
                    'Content-Type: application/json',
                    'X-API-Key: ' . $apiKey,
                ],
            ]);
            if (!$optionsSet) {
                $curlError = curl_error($ch);
                curl_close($ch);
                $result['error'] = $curlError !== '' ? $curlError : 'Unable to apply ToolsAPI cURL timeout and security options.';
                $result['attempts'] = $attempt;
                break;
            }

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($queryParams ?: new \stdClass()));
            }

            $resp = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            $result = [
                'status'      => $error !== '' ? 'error' : 'ok',
                'http_status' => $httpCode,
                'body'        => is_string($resp) ? $resp : '',
                'error'       => $error,
                'url'         => $url,
                'attempts'    => $attempt,
            ];

            $retryableHttpStatus = in_array($httpCode, [408, 425, 429, 500, 502, 503, 504], true);
            // The registered Live Chat tools are read-only GET requests. Do not replay future POST tools automatically.
            $shouldRetry = $method === 'GET' && ($error !== '' || $retryableHttpStatus);
            $remainingMs = (int) floor(($deadline - microtime(true)) * 1000);
            if (!$shouldRetry || $attempt >= $attemptLimit || $remainingMs <= 0) {
                break;
            }

            $backoffMs = min(1000, 250 * (2 ** ($attempt - 1)));
            if (isset($responseHeaders['retry-after'])) {
                $retryAfter = trim((string) $responseHeaders['retry-after']);
                if (ctype_digit($retryAfter)) {
                    $retryAfterMs = (int) $retryAfter * 1000;
                    $backoffMs = max($backoffMs, $retryAfterMs);
                } else {
                    $retryAt = strtotime($retryAfter);
                    if ($retryAt !== false) {
                        $retryAfterMs = max(0, $retryAt - time()) * 1000;
                        $backoffMs = max($backoffMs, $retryAfterMs);
                    }
                }
            } elseif ($httpCode === 429) {
                $backoffMs = max($backoffMs, 1000);
            }
            $backoffMs = min($backoffMs, $remainingMs);
            usleep($backoffMs * 1000);
        }

        $result['elapsed_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
        return $result;
    }

    /**
     * Generate unique hash key for result caching.
     */
    private static function generateCacheKey(string $toolName, array $args): string
    {
        ksort($args);
        return md5('toolsapi:' . $toolName . ':' . json_encode($args));
    }

    /**
     * Retrieve cached tool execution result.
     */
    private static function getCachedResult(string $cacheKey): ?array
    {
        try {
            if (!Capsule::schema()->hasTable('tblsahdev_tool_cache')) {
                return null;
            }

            $entry = Capsule::table('tblsahdev_tool_cache')
                ->where('cache_key', $cacheKey)
                ->where('expires_at', '>', Carbon::now())
                ->where('status', 'success')
                ->first();

            if ($entry) {
                return [
                    'normalized_summary' => (string) $entry->normalized_summary,
                    'data'               => json_decode((string) $entry->response_data, true),
                ];
            }
        } catch (\Throwable $e) {}

        return null;
    }

    /**
     * Acquire concurrency deduplication lock.
     */
    private static function acquireLock(string $cacheKey): bool
    {
        try {
            if (!Capsule::schema()->hasTable('tblsahdev_tool_cache')) {
                return true;
            }

            $now = Carbon::now();
            $lockUntil = Carbon::now()->addSeconds(10);

            $existing = Capsule::table('tblsahdev_tool_cache')->where('cache_key', $cacheKey)->first();
            if ($existing) {
                if ($existing->is_locked && $existing->locked_until && Carbon::parse($existing->locked_until)->isFuture()) {
                    return false; // locked by another process
                }
                Capsule::table('tblsahdev_tool_cache')->where('cache_key', $cacheKey)->update([
                    'is_locked'    => 1,
                    'locked_until' => $lockUntil,
                    'updated_at'   => $now,
                ]);
            } else {
                Capsule::table('tblsahdev_tool_cache')->insert([
                    'cache_key'    => $cacheKey,
                    'tool_name'    => '',
                    'target'       => '',
                    'status'       => 'in_flight',
                    'is_locked'    => 1,
                    'locked_until' => $lockUntil,
                    'expires_at'   => $lockUntil,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]);
            }
            return true;
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Release concurrency lock.
     */
    private static function releaseLock(string $cacheKey): void
    {
        try {
            if (Capsule::schema()->hasTable('tblsahdev_tool_cache')) {
                Capsule::table('tblsahdev_tool_cache')->where('cache_key', $cacheKey)->update([
                    'is_locked'    => 0,
                    'locked_until' => null,
                ]);
            }
        } catch (\Throwable $e) {}
    }

    /**
     * Store successful result in persistent cache.
     */
    private static function storeCache(
        string $cacheKey,
        string $toolName,
        string $target,
        array $args,
        string $rawBody,
        string $normalizedSummary,
        int $ttl
    ): void {
        try {
            if (!Capsule::schema()->hasTable('tblsahdev_tool_cache')) {
                return;
            }

            $now = Carbon::now();
            $expiresAt = Carbon::now()->addSeconds($ttl);

            Capsule::table('tblsahdev_tool_cache')->updateOrInsert(
                ['cache_key' => $cacheKey],
                [
                    'tool_name'             => $toolName,
                    'target'                => $target,
                    'normalized_args_json'  => json_encode($args),
                    'response_data'         => $rawBody,
                    'normalized_summary'    => $normalizedSummary,
                    'status'                => 'success',
                    'http_status'           => 200,
                    'is_locked'             => 0,
                    'locked_until'          => null,
                    'expires_at'            => $expiresAt,
                    'updated_at'            => $now,
                ]
            );
        } catch (\Throwable $e) {
            ModuleLogger::warning('client_chat_tools', 'Failed to store tool cache: ' . $e->getMessage());
        }
    }

    /**
     * Load raw OpenAPI specification from file or settings.
     */
    private static function loadOpenApiSpec(): string
    {
        $root = dirname(__DIR__);
        $file = $root . DIRECTORY_SEPARATOR . self::OPENAPI_REF_FILE;
        if (is_file($file)) {
            $content = @file_get_contents($file);
            if (is_string($content) && $content !== '') {
                return $content;
            }
        }
        return '';
    }
}
