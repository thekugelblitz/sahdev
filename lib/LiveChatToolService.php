<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;
use Carbon\Carbon;

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
require_once __DIR__ . '/DiagnosticPolicy.php';
require_once __DIR__ . '/DiagnosticLock.php';
require_once __DIR__ . '/DiagnosticEvidence.php';
require_once __DIR__ . '/DiagnosticTrace.php';

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
            'description' => 'Resolve the requested DNS record type for a domain.',
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
     * Optional override for unit testing / mocking.
     * @var bool|null
     */
    private static ?bool $enabledOverride = null;

    /**
     * Set test override for tool calling enabled state.
     * Pass null to restore database lookup behavior.
     */
    public static function setEnabledOverride(?bool $enabled): void
    {
        self::$enabledOverride = $enabled;
    }

    /**
     * Check if Live Chat Tool calling is enabled.
     * By default, it is DISABLED (0) to eliminate latency and preserve fast responses.
     * Must be explicitly enabled (1) in settings.
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        if (self::$enabledOverride !== null) {
            return self::$enabledOverride;
        }

        try {
            if (!class_exists('WHMCS\\Database\\Capsule')) {
                return false;
            }
            try {
                if (!Capsule::schema()->hasTable('tblsahdev_settings')) {
                    return false;
                }
            } catch (\Throwable $t) {
                return false;
            }

            $settings = Capsule::table('tblsahdev_settings')->first();
            if (!$settings) {
                return false;
            }

            // Strictly check client_chat_tools_enabled.
            // By default, it must be disabled (0). Never inherit general ticket tools_execution_enabled.
            if (isset($settings->client_chat_tools_enabled)) {
                return (int) $settings->client_chat_tools_enabled === 1;
            }

            return false;
        } catch (\Throwable $e) {
            return false;
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

                    foreach (['minimum', 'maximum', 'pattern', 'enum'] as $constraint) {
                        if (isset($param['schema'][$constraint])) $propDef[$constraint] = $param['schema'][$constraint];
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
                    'description' => 'Requested DNS record type. Defaults to A.',
                    'enum'        => ['A', 'AAAA', 'MX', 'TXT', 'NS', 'CNAME', 'SOA', 'CAA'],
                    'default' => 'A',
                ];
            }

            $registry[$toolName] = [
                'type'     => 'function',
                'function' => [
                    'name'        => $toolName,
                    'description' => $toolName === 'toolsapi_dns_lookup' ? 'Read the requested DNS record type for a domain.' : $meta['description'],
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => $properties,
                        'required'   => $required,
                        'additionalProperties' => false,
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
    public static function matchOperation(string $method, string $path): ?array
    {
        if (strtoupper($method) !== 'GET') return null;
        foreach (self::getToolRegistry() as $name => $tool) {
            $template = $tool['_meta']['path'];
            preg_match_all('/\{([^}]+)\}/', $template, $parameters);
            $pattern = preg_quote($template, '~');
            foreach ($parameters[0] as $parameter) $pattern = str_replace(preg_quote($parameter, '~'), '([^/]+)', $pattern);
            if (!preg_match('~^' . $pattern . '$~D', $path, $matches)) continue;
            $args = [];
            foreach ($parameters[1] as $i => $parameter) $args[$parameter] = rawurldecode($matches[$i + 1]);
            return ['tool'=>$name, 'args'=>$args];
        }
        return null;
    }

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
            $host = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) ? trim($host, '[]') : explode(':', $host)[0]; // strip port
        }

        $host = strtolower(trim($host, '[] '));
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
            // Preflight validation only; ToolsAPI must also validate resolved destinations and redirects.
            if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/i', $host)) {
                return ['valid' => false, 'sanitized' => '', 'error' => "Invalid domain syntax ({$host})."];
            }

            // Verify DNS resolution doesn't resolve to private range
            $resolvedIps = @gethostbynamel($host) ?: [];
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) if (!empty($record['ipv6'])) $resolvedIps[] = $record['ipv6'];
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
    public static function executeToolCall(string $toolName, array $args, int $sessionId, ?int $clientId = null, string $channel = 'livechat', ?float $deadline = null, bool $fresh = false, string $traceId = ''): array
    {
        $policy = DiagnosticPolicy::get();
        $context = ['channel'=>$channel, 'scope_id'=>$sessionId, 'trace_id'=>$traceId, 'tool'=>$toolName];
        DiagnosticTrace::record('tool_requested', $context, $policy);
        $started = microtime(true);
        try {
            $result = self::executeToolCallInternal($toolName, $args, $sessionId, $clientId, $channel, $deadline, $fresh);
        } catch (\Throwable $e) {
            $result = ['success'=>false, 'cached'=>false, 'summary'=>'Diagnostic execution is unavailable.', 'error'=>'execution_failed'];
        }
        $error = (string) ($result['error'] ?? '');
        DiagnosticTrace::record('tool_result', $context + ['success'=>$result['success'], 'cached'=>$result['cached'],
            'error_code'=>preg_match('/^[a-z_]{1,64}$/D', $error) ? $error : ($error !== '' ? 'upstream_error' : ''),
            'http_status'=>(int) ($result['http_status'] ?? 0), 'elapsed_ms'=>(int) round((microtime(true)-$started)*1000)], $policy);
        return $result;
    }

    private static function executeToolCallInternal(string $toolName, array $args, int $sessionId, ?int $clientId = null, string $channel = 'livechat', ?float $deadline = null, bool $fresh = false): array
    {
        $policy = DiagnosticPolicy::get();
        $channelEnabled = self::isEnabled();
        if ($channel === 'tickets') {
            try { $channelEnabled = (bool) Capsule::table('tblsahdev_settings')->value('tools_execution_enabled'); } catch (\Throwable $e) { $channelEnabled = false; }
        }
        if (!$channelEnabled || $policy['emergency_stop']) {
            return [
                'success' => false,
                'summary' => 'Tool calling is currently disabled for live chat.',
                'cached'  => false,
                'error'   => 'tools_disabled',
            ];
        }

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
        $ttl = min($meta['ttl'] ?? 900, self::getDefaultCacheTtl());

        if (in_array($toolName, $policy['disabled_tools'], true)) return ['success'=>false,'cached'=>false,'summary'=>'This diagnostic is disabled.','error'=>'disabled'];
        $schema = $registry[$toolName]['function']['parameters'];
        foreach ($args as $key => $value) {
            if (!isset($schema['properties'][$key]) || !is_scalar($value)) return ['success'=>false,'cached'=>false,'summary'=>'Invalid diagnostic arguments.','error'=>'invalid_arguments'];
            $rule = $schema['properties'][$key];
            if (($rule['type'] ?? '') === 'string' && !is_string($value) || ($rule['type'] ?? '') === 'integer' && !is_int($value) || isset($rule['enum']) && !in_array($value, $rule['enum'], true) || isset($rule['minimum']) && $value < $rule['minimum'] || isset($rule['maximum']) && $value > $rule['maximum'] || isset($rule['pattern']) && !preg_match('~' . str_replace('~', '\~', $rule['pattern']) . '~D', (string) $value)) return ['success'=>false,'cached'=>false,'summary'=>'Diagnostic argument outside permitted bounds.','error'=>'invalid_arguments'];
        }
        foreach ($schema['properties'] as $key => $rule) if (!isset($args[$key]) && isset($rule['default'])) $args[$key] = $rule['default'];
        preg_match('/\{([^}]+)\}/', $pathPattern, $binding);
        $targetParamName = $binding[1] ?? '';
        $rawTarget = (string) ($args[$targetParamName] ?? '');
        if ($rawTarget === '') return ['success'=>false,'cached'=>false,'summary'=>'Please specify the diagnostic target.','error'=>'missing_target'];

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
        if (in_array($targetParamName, ['url', 'target'], true) && preg_match('~^https?://~i', $rawTarget)) {
            $parts = parse_url($rawTarget);
            if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) && !in_array($parts['port'], [80, 443], true)) return ['success'=>false,'cached'=>false,'summary'=>'Unsupported URL credentials or port.','error'=>'invalid_url'];
            $args[$targetParamName] = strtolower($parts['scheme']) . '://' . (strpos($sanitizedTarget, ':') !== false ? '[' . $sanitizedTarget . ']' : $sanitizedTarget) . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        }

        // 3. Cache & Deduplication Check
        $cacheKey = self::generateCacheKey($toolName, $args);
        $cachedResult = $fresh ? null : self::getCachedResult($cacheKey);
        if ($cachedResult !== null) {
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
        if (!$locked) return ['success'=>false,'cached'=>false,'summary'=>'The same diagnostic is already running; please retry shortly.','error'=>'in_flight'];

        // 5. Upstream HTTP Execution
        try {
            $httpResult = self::executeUpstreamHttp($method, $pathPattern, $args, $deadline);

            $httpStatus = (int) ($httpResult['http_status'] ?? 0);
            $rawBody = (string) ($httpResult['body'] ?? '');

            if ($httpResult['status'] !== 'ok' || $httpStatus < 200 || $httpStatus >= 300) {
                self::releaseLock($cacheKey);
                $errDetail = !empty($httpResult['error']) ? $httpResult['error'] : "HTTP {$httpStatus}";
                return [
                    'success' => false,
                    'summary' => "Diagnostic check for {$sanitizedTarget} returned an error ({$errDetail}).",
                    'cached'  => false,
                    'error'   => ($httpResult['curl_errno'] ?? 0) === 28 ? 'timeout' : ($httpStatus === 401 || $httpStatus === 403 ? 'authentication_failed' : ($httpStatus === 429 ? 'rate_limited' : 'upstream_error')),
                    'http_status' => $httpStatus,
                ];
            }

            $contract = DiagnosticEvidence::decode($pathPattern, $rawBody);
            if (!$contract['valid']) return ['success'=>false,'cached'=>false,'summary'=>$contract['error'],'error'=>'invalid_response', 'http_status'=>$httpStatus];
            // 6. Compact Output Normalization
            $normalizedSummary = self::normalizeOutput($toolName, $pathPattern, $rawBody, $sanitizedTarget);

            if (isset($contract['data']['records']) && is_array($contract['data']['records'])) {
                foreach ($contract['data']['records'] as $record) if (is_array($record) && isset($record['ttl'])) $ttl = min($ttl, max(0, (int) $record['ttl']));
            }
            if (!empty($contract['data']['metadata']['cached'])) {
                $observed = strtotime((string) ($contract['data']['metadata']['timestamp'] ?? ''));
                $ttl = $observed ? max(0, $ttl - max(0, time() - $observed)) : 0;
            }
            // 7. Store in Persistent Cache
            self::storeCache($cacheKey, $toolName, $sanitizedTarget, $args, $rawBody, $normalizedSummary, $ttl);

            return [
                'success' => true,
                'summary' => $normalizedSummary,
                'http_status' => $httpStatus,
                'data'    => json_decode($rawBody, true),
                'cached'  => false,
                'error'   => null,
            ];
        } catch (\Throwable $e) {
            self::releaseLock($cacheKey);
            return [
                'success' => false,
                'summary' => 'Diagnostic execution is unavailable. Please retry shortly.',
                'cached'  => false,
                'error'   => preg_match('/API key|ToolsAPI key/i', $e->getMessage()) ? 'credentials_missing' : 'execution_failed',
            ];
        } finally { self::releaseLock($cacheKey); }
    }

    /**
     * Compact and normalize raw JSON response into lean structured text for LLM consumption.
     */
    public static function normalizeOutput(string $toolName, string $path, string $rawBody, string $target): string
    {
        return DiagnosticEvidence::summary($toolName, $path, $rawBody, $target);
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

        // Preserve the requested record type; schema validation restricts automatic calls.
        if (preg_match('#^/dns/[^/?]+$#i', $finalPath)) {
            $queryParams['type'] = $args['type'] ?? 'A';
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
    private static function executeUpstreamHttp(string $method, string $pathPattern, array $args, ?float $outerDeadline = null): array
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
        $deadline = min($outerDeadline ?? PHP_FLOAT_MAX, $startedAt + self::UPSTREAM_TOTAL_TIMEOUT_SEC);
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
            $responseBody = '';

            $optionsSet = curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER    => true,
                CURLOPT_TIMEOUT_MS         => $requestTimeoutMs,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody): int {
                    if (strlen($responseBody) + strlen($chunk) > 1048576) return 0;
                    $responseBody .= $chunk;
                    return strlen($chunk);
                },
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
            $curlErrno = curl_errno($ch);
            curl_close($ch);

            $result = [
                'status'      => $error !== '' ? 'error' : 'ok',
                'http_status' => $httpCode,
                'body'        => $responseBody,
                'error'       => $error,
                'curl_errno'  => $curlErrno,
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
        try { $backend = (string) Capsule::table('tblsahdev_settings')->value('tools_api_base_url'); } catch (\Throwable $e) { $backend = self::DEFAULT_BASE_URL; }
        return hash('sha256', 'v5:' . $backend . ':' . $toolName . ':' . json_encode($args));
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
                    'normalized_summary' => (string) $entry->normalized_summary . '\n[Sahdev cache stored at ' . $entry->updated_at . '; expires ' . $entry->expires_at . ']',
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
        return DiagnosticLock::acquire('tool:' . $cacheKey);
    }

    private static function releaseLock(string $cacheKey): void
    {
        DiagnosticLock::release('tool:' . $cacheKey);
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
            DiagnosticTrace::record('cache_write_failed', ['reason'=>'cache_storage_unavailable']);
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
