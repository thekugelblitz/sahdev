<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;

class ProductDomainCatalogService
{
    private static ?array $cachedProducts = null;
    private static ?array $cachedProductGroups = null;
    private static ?array $cachedDomainPricing = null;
    private static ?array $cachedCurrencies = null;

    /**
     * Determine relevant products/services and domains based on local analysis of user query.
     */
    public static function determineContext(string $messageText, ?int $clientId = null): string
    {
        try {
            $currency = self::resolveCurrency($clientId);
            $userLower = mb_strtolower(trim($messageText));
            $systemUrl = self::getWhmcsSystemUrl();

            $isCatalogEnabled = (bool) ChatService::getChatSetting('client_chat_ds_catalog', 1);
            $isDomainPricingEnabled = (bool) ChatService::getChatSetting('client_chat_ds_domain_pricing', 1);

            $output = [];

            // 1. Check for Domain Intent & TLD inquiries
            if ($isDomainPricingEnabled && self::hasDomainIntent($userLower)) {
                $domainContext = self::buildDomainContext($userLower, $currency, $systemUrl);
                if (!empty($domainContext)) {
                    $output[] = $domainContext;
                }
            }

            // 2. Check for Products & Services inquiries (Presales & Tech specs)
            if ($isCatalogEnabled) {
                $productContext = self::buildProductContext($userLower, $currency, $systemUrl, $clientId);
                if (!empty($productContext)) {
                    $output[] = $productContext;
                }
            }

            if (empty($output)) {
                return '';
            }

            return implode("\n\n", $output);
        } catch (\Throwable $e) {
            ModuleLogger::error('catalog_service', 'Error in determineContext: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Check if query has domain-related intent
     */
    private static function hasDomainIntent(string $text): bool
    {
        $domainKeywords = [
            'domain', 'domains', 'tld', 'tlds', 'register', 'registration', 'transfer',
            'whois', 'nameserver', 'nameservers', 'dns', 'renew domain', 'buy domain',
            'domain price', 'domain cost', 'free domain'
        ];

        foreach ($domainKeywords as $kw) {
            if (mb_strpos($text, $kw) !== false) {
                return true;
            }
        }

        // Check for specific TLD mentions like .com, .net, .org, .in, .io, .co, .xyz, etc.
        if (preg_match('/\b\.(com|net|org|in|io|co|xyz|info|biz|app|dev|me|online|tech|shop|site|cloud|ai|store)\b/i', $text)) {
            return true;
        }

        return false;
    }

    /**
     * Build domain pricing and registration guidance context
     */
    private static function buildDomainContext(string $text, array $currency, string $systemUrl): string
    {
        $domains = self::getActiveDomainPricing($currency['id']);
        if (empty($domains)) {
            return '';
        }

        // Determine if user asked for a specific extension
        $requestedTlds = [];
        foreach ($domains as $d) {
            $ext = mb_strtolower($d['extension']);
            if (mb_strpos($text, $ext) !== false || mb_strpos($text, ltrim($ext, '.')) !== false) {
                $requestedTlds[] = $d;
            }
        }

        // If specific TLDs requested, prioritize them; otherwise show top TLDs (up to 8)
        $tldsToShow = !empty($requestedTlds) ? $requestedTlds : array_slice($domains, 0, 8);

        $curPrefix = $currency['prefix'] ?? '$';
        $curSuffix = $currency['suffix'] ?? '';

        $lines = [];
        $lines[] = "=== ACTIVE DOMAIN REGISTRATION & TLD PRICING (Official Catalog) ===";
        $lines[] = "Domain Search & Registration Link: " . ($systemUrl ? "{$systemUrl}/cart.php?a=add&domain=register" : "cart.php?a=add&domain=register");
        $lines[] = "Available Active TLDs & Rates (Registration per 1 Year):";

        foreach ($tldsToShow as $tld) {
            $priceStr = $tld['price'] > 0 ? "{$curPrefix}{$tld['price']}{$curSuffix}/yr" : "Included with eligible annual plans";
            $addons = [];
            if (!empty($tld['dns'])) $addons[] = "DNS Management";
            if (!empty($tld['email'])) $addons[] = "Email Forwarding";
            if (!empty($tld['idprotection'])) $addons[] = "ID Protection";
            $addonStr = !empty($addons) ? " (" . implode(', ', $addons) . ")" : "";

            $lines[] = "- {$tld['extension']}: {$priceStr}{$addonStr}";
        }

        $lines[] = "DIRECT ACTION: Direct visitors to search and register available domains using: [Register Domain]({$systemUrl}/cart.php?a=add&domain=register)";

        return implode("\n", $lines);
    }

    /**
     * Build product catalog context based on local determination
     */
    private static function buildProductContext(string $text, array $currency, string $systemUrl, ?int $clientId = null): string
    {
        $allProducts = self::getActiveProducts($currency['id']);
        if (empty($allProducts)) {
            return '';
        }

        $matchedProducts = [];
        $matchedReason = '';

        // 1. Detect if logged in client wants to upgrade an active service
        if ($clientId && (mb_strpos($text, 'upgrade') !== false || mb_strpos($text, 'switch plan') !== false || mb_strpos($text, 'need more') !== false)) {
            $activeServices = self::getClientActiveServices($clientId);
            if (!empty($activeServices)) {
                $matchedReason = "Client requested plan upgrade for active service";
                // Find products in the same group or higher tier
                foreach ($activeServices as $svc) {
                    $pkgId = (int) $svc->packageid;
                    $currentProduct = $allProducts[$pkgId] ?? null;
                    if ($currentProduct) {
                        $gid = $currentProduct['gid'];
                        foreach ($allProducts as $p) {
                            if ($p['gid'] == $gid && $p['id'] != $pkgId) {
                                $matchedProducts[$p['id']] = $p;
                            }
                        }
                    }
                }
            }
        }

        // 2. Keyword & Group Intent Matching
        if (empty($matchedProducts)) {
            $groupKeywords = [
                'wordpress' => ['wordpress', 'wp ', 'wp-'],
                'vps'       => ['vps', 'virtual private', 'cloud server', 'compute', 'kvm', 'root server'],
                'dedicated' => ['dedicated', 'bare metal', 'dedicated server'],
                'reseller'  => ['reseller', 'whm', 'cpanel accounts'],
                'shared'    => ['shared', 'cpanel', 'plesk', 'starter', 'basic hosting', 'web hosting', 'personal hosting'],
                'email'     => ['email hosting', 'business email', 'mailbox', 'webmail'],
            ];

            foreach ($groupKeywords as $category => $keywords) {
                foreach ($keywords as $kw) {
                    if (mb_strpos($text, $kw) !== false) {
                        $matchedReason = "Category inquiry: {$category}";
                        foreach ($allProducts as $p) {
                            $haystack = mb_strtolower($p['name'] . ' ' . $p['group_name'] . ' ' . $p['description']);
                            if (mb_strpos($haystack, $kw) !== false || mb_strpos(mb_strtolower($p['group_name']), $category) !== false) {
                                $matchedProducts[$p['id']] = $p;
                            }
                        }
                        break 2;
                    }
                }
            }
        }

        // 3. Technical Specs Query (RAM, CPU, NVMe, Storage, Backup, SSL, PHP)
        if (empty($matchedProducts)) {
            $techKeywords = [
                'ram' => ['ram', 'memory', 'gb ram'],
                'cpu' => ['cpu', 'core', 'vcore', 'ghz'],
                'storage' => ['storage', 'disk', 'nvme', 'ssd', 'space'],
                'bandwidth' => ['bandwidth', 'traffic', 'unmetered'],
                'ssl' => ['free ssl', 'letsencrypt', 'ssl certificate'],
                'backup' => ['backup', 'snapshots', 'daily backup'],
                'php' => ['php 8', 'php version', 'nodejs', 'python'],
            ];

            $matchedTech = [];
            foreach ($techKeywords as $type => $kwList) {
                foreach ($kwList as $kw) {
                    if (mb_strpos($text, $kw) !== false) {
                        $matchedTech[] = $type;
                        break;
                    }
                }
            }

            if (!empty($matchedTech)) {
                $matchedReason = "Technical specification inquiry: " . implode(', ', $matchedTech);
                // Filter products that explicitly highlight specs in description
                foreach ($allProducts as $p) {
                    $desc = mb_strtolower($p['description']);
                    foreach ($matchedTech as $tech) {
                        if (mb_strpos($desc, $tech) !== false || mb_strpos($desc, 'gb') !== false || mb_strpos($desc, 'cpu') !== false) {
                            $matchedProducts[$p['id']] = $p;
                            break;
                        }
                    }
                }
            }
        }

        // 4. Product Name Direct Matching
        if (empty($matchedProducts)) {
            foreach ($allProducts as $p) {
                $pNameLower = mb_strtolower($p['name']);
                if (mb_strpos($text, $pNameLower) !== false) {
                    $matchedReason = "Direct product name mention: {$p['name']}";
                    $matchedProducts[$p['id']] = $p;
                    // Also include other plans in same group for comparison
                    foreach ($allProducts as $sibling) {
                        if ($sibling['gid'] == $p['gid']) {
                            $matchedProducts[$sibling['id']] = $sibling;
                        }
                    }
                    break;
                }
            }
        }

        // 5. Presales / Recommendation / Pricing Intent
        $isSalesInquiry = preg_match('/\b(price|pricing|cost|how much|plans|packages|recommend|buy|order|purchase|compare|features|best plan)\b/i', $text);
        if (empty($matchedProducts) && $isSalesInquiry) {
            $matchedReason = "General presales recommendation inquiry";
            // Group products by group and take top 1-2 from each active group (limit to 6 total)
            $byGroup = [];
            foreach ($allProducts as $p) {
                $byGroup[$p['gid']][] = $p;
            }
            foreach ($byGroup as $gid => $items) {
                foreach (array_slice($items, 0, 2) as $item) {
                    $matchedProducts[$item['id']] = $item;
                    if (count($matchedProducts) >= 6) break 2;
                }
            }
        }

        // 6. Default Fallback: Provide concise active catalog overview (max 4 plans)
        if (empty($matchedProducts)) {
            $matchedReason = "Available active catalog overview";
            $matchedProducts = array_slice($allProducts, 0, 4, true);
        }

        // Cap to max 6 products to prevent prompt bloat while giving rich details
        $productsToFormat = array_slice($matchedProducts, 0, 6, true);

        return self::formatProductsContext($productsToFormat, $currency, $systemUrl, $matchedReason);
    }

    /**
     * Format active products into structured, high-value Markdown for LLM
     */
    private static function formatProductsContext(array $products, array $currency, string $systemUrl, string $reason): string
    {
        $curPrefix = $currency['prefix'] ?? '$';
        $curSuffix = $currency['suffix'] ?? '';

        $lines = [];
        $lines[] = "=== ACTIVE PRODUCTS & SERVICES CATALOG (Verified Active, Non-Hidden) ===";
        $lines[] = "Local Determination Focus: {$reason}";
        $lines[] = "SALES DIRECTIVE: Help the visitor choose the best solution by explaining technical specs, suitability, and benefits. Always offer direct 1-click cart links for recommended plans.";

        foreach ($products as $p) {
            $pid = (int) $p['id'];
            $cartUrl = $systemUrl ? "{$systemUrl}/cart.php?a=add&pid={$pid}" : "cart.php?a=add&pid={$pid}";
            $priceStr = self::formatPricingString($p['pricing'] ?? [], $curPrefix, $curSuffix);
            $cleanDesc = self::cleanDescription($p['description'] ?? '');

            $lines[] = "\n--- [Product ID: {$pid}] {$p['name']} ---";
            $lines[] = "Group/Category: {$p['group_name']}";
            if (!empty($p['group_headline'])) {
                $lines[] = "Category Focus: {$p['group_headline']}";
            }
            $lines[] = "Pricing: {$priceStr}";
            $lines[] = "Order Cart URL: [Order {$p['name']}]({$cartUrl})";
            if (!empty($cleanDesc)) {
                $lines[] = "Technical Specs & Features:\n" . $cleanDesc;
            }
        }

        $lines[] = "\nIMPORTANT RULE: Only recommend or quote products listed above. Never invent unlisted plans or fake prices. Direct all orders to the provided [Order ...]({$systemUrl}/cart.php?a=add&pid=...) links.";

        return implode("\n", $lines);
    }

    /**
     * Format price string with available billing cycles
     */
    private static function formatPricingString(array $pricing, string $prefix, string $suffix): string
    {
        if (empty($pricing)) {
            return "Free / Custom Quote";
        }

        $cycles = [];
        if (isset($pricing['monthly']) && $pricing['monthly'] > 0) {
            $cycles[] = "{$prefix}{$pricing['monthly']}{$suffix}/mo";
        }
        if (isset($pricing['annually']) && $pricing['annually'] > 0) {
            $cycles[] = "{$prefix}{$pricing['annually']}{$suffix}/yr";
        }
        if (isset($pricing['semiannually']) && $pricing['semiannually'] > 0 && empty($cycles)) {
            $cycles[] = "{$prefix}{$pricing['semiannually']}{$suffix}/6-mo";
        }
        if (isset($pricing['biennially']) && $pricing['biennially'] > 0 && count($cycles) < 2) {
            $cycles[] = "{$prefix}{$pricing['biennially']}{$suffix}/2-yr";
        }

        if (empty($cycles)) {
            if (isset($pricing['monthly']) && $pricing['monthly'] == 0) {
                return "Free";
            }
            return "Contact Sales for Pricing";
        }

        return implode(' | ', $cycles);
    }

    /**
     * Clean HTML/formatting from product description into readable bullets
     */
    private static function cleanDescription(string $raw): string
    {
        $text = html_entity_decode($raw, ENT_QUOTES, 'UTF-8');
        // Convert <br>, <li>, <p> to newlines
        $text = preg_replace('/<(?:br|\/li|\/p)\s*\/?>/i', "\n", $text);
        $text = strip_tags($text);
        $lines = preg_split('/[\r\n]+/', $text);

        $cleanBullets = [];
        foreach ($lines as $line) {
            $line = trim($line, " \t\n\r\0\x0B-•*");
            if (!empty($line) && mb_strlen($line) > 2) {
                $cleanBullets[] = "  * " . $line;
                if (count($cleanBullets) >= 6) break; // Keep concise
            }
        }

        return implode("\n", $cleanBullets);
    }

    /**
     * Query all active, non-hidden products from tblproducts and tblproductgroups
     */
    public static function getActiveProducts(int $currencyId): array
    {
        if (self::$cachedProducts !== null && isset(self::$cachedProducts[$currencyId])) {
            return self::$cachedProducts[$currencyId];
        }

        if (!Capsule::schema()->hasTable('tblproducts') || !Capsule::schema()->hasTable('tblproductgroups')) {
            return [];
        }

        try {
            $query = Capsule::table('tblproducts')
                ->join('tblproductgroups', 'tblproducts.gid', '=', 'tblproductgroups.id')
                ->where(function ($q) {
                    $q->where('tblproducts.retired', 0)
                      ->orWhereNull('tblproducts.retired')
                      ->orWhere('tblproducts.retired', '');
                })
                ->where(function ($q) {
                    $q->where('tblproducts.hidden', 0)
                      ->orWhereNull('tblproducts.hidden')
                      ->orWhere('tblproducts.hidden', '');
                })
                ->where(function ($q) {
                    $q->where('tblproductgroups.hidden', 0)
                      ->orWhereNull('tblproductgroups.hidden')
                      ->orWhere('tblproductgroups.hidden', '');
                })
                ->select([
                    'tblproducts.id',
                    'tblproducts.gid',
                    'tblproducts.name',
                    'tblproducts.type',
                    'tblproducts.description',
                    'tblproducts.paytype',
                    'tblproducts.order as prod_order',
                    'tblproductgroups.name as group_name',
                    'tblproductgroups.headline as group_headline',
                    'tblproductgroups.order as group_order',
                ])
                ->orderBy('tblproductgroups.order', 'asc')
                ->orderBy('tblproducts.order', 'asc')
                ->get();

            $products = [];
            $productIds = [];

            foreach ($query as $row) {
                $pid = (int) $row->id;
                $productIds[] = $pid;
                $products[$pid] = [
                    'id'             => $pid,
                    'gid'            => (int) $row->gid,
                    'name'           => (string) $row->name,
                    'type'           => (string) $row->type,
                    'description'    => (string) $row->description,
                    'paytype'        => (string) $row->paytype,
                    'group_name'     => (string) $row->group_name,
                    'group_headline' => (string) ($row->group_headline ?? ''),
                    'pricing'        => [],
                ];
            }

            // Load pricing from tblpricing
            if (!empty($productIds) && Capsule::schema()->hasTable('tblpricing')) {
                $prices = Capsule::table('tblpricing')
                    ->where('type', 'product')
                    ->where('currency', $currencyId)
                    ->whereIn('relid', $productIds)
                    ->get(['relid', 'monthly', 'quarterly', 'semiannually', 'annually', 'biennially']);

                foreach ($prices as $p) {
                    $relId = (int) $p->relid;
                    if (isset($products[$relId])) {
                        $products[$relId]['pricing'] = [
                            'monthly'      => (float) $p->monthly,
                            'quarterly'    => (float) $p->quarterly,
                            'semiannually' => (float) $p->semiannually,
                            'annually'     => (float) $p->annually,
                            'biennially'   => (float) $p->biennially,
                        ];
                    }
                }
            }

            self::$cachedProducts[$currencyId] = $products;
            return $products;
        } catch (\Throwable $e) {
            ModuleLogger::error('catalog_service', 'Error loading active products: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Query active domain pricing from tbldomainpricing & tblpricing
     */
    public static function getActiveDomainPricing(int $currencyId): array
    {
        if (self::$cachedDomainPricing !== null && isset(self::$cachedDomainPricing[$currencyId])) {
            return self::$cachedDomainPricing[$currencyId];
        }

        if (!Capsule::schema()->hasTable('tbldomainpricing')) {
            return [];
        }

        try {
            $tlds = Capsule::table('tbldomainpricing')
                ->orderBy('order', 'asc')
                ->get(['id', 'extension', 'dns', 'email', 'idprotection']);

            $results = [];
            $tldIds = [];
            foreach ($tlds as $t) {
                $tid = (int) $t->id;
                $tldIds[] = $tid;
                $results[$tid] = [
                    'id'           => $tid,
                    'extension'    => (string) $t->extension,
                    'dns'          => (bool) $t->dns,
                    'email'        => (bool) $t->email,
                    'idprotection' => (bool) $t->idprotection,
                    'price'        => 0.0,
                ];
            }

            // Join with tblpricing for domainregister price (1 year: msetupfee)
            if (!empty($tldIds) && Capsule::schema()->hasTable('tblpricing')) {
                $prices = Capsule::table('tblpricing')
                    ->where('type', 'domainregister')
                    ->where('currency', $currencyId)
                    ->whereIn('relid', $tldIds)
                    ->get(['relid', 'msetupfee']);

                foreach ($prices as $pr) {
                    $rid = (int) $pr->relid;
                    if (isset($results[$rid])) {
                        $results[$rid]['price'] = (float) $pr->msetupfee;
                    }
                }
            }

            $final = array_values($results);
            self::$cachedDomainPricing[$currencyId] = $final;
            return $final;
        } catch (\Throwable $e) {
            ModuleLogger::error('catalog_service', 'Error loading domain pricing: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get active hosting services for a specific client
     */
    private static function getClientActiveServices(int $clientId): array
    {
        if (!Capsule::schema()->hasTable('tblhosting')) {
            return [];
        }

        try {
            return Capsule::table('tblhosting')
                ->where('userid', $clientId)
                ->where('domainstatus', 'Active')
                ->get(['id', 'packageid', 'domain', 'billingcycle'])
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Resolve currency prefix, suffix and ID for client or system default
     */
    private static function resolveCurrency(?int $clientId): array
    {
        $defaultCurrency = ['id' => 1, 'code' => 'USD', 'prefix' => '$', 'suffix' => ''];

        if (!Capsule::schema()->hasTable('tblcurrencies')) {
            return $defaultCurrency;
        }

        try {
            $currencyId = 0;
            if ($clientId && $clientId > 0 && Capsule::schema()->hasTable('tblclients')) {
                $currencyId = (int) Capsule::table('tblclients')->where('id', $clientId)->value('currency');
            }

            if ($currencyId > 0) {
                $cur = Capsule::table('tblcurrencies')->where('id', $currencyId)->first(['id', 'code', 'prefix', 'suffix']);
                if ($cur) {
                    return (array) $cur;
                }
            }

            $def = Capsule::table('tblcurrencies')->where('default', 1)->first(['id', 'code', 'prefix', 'suffix']);
            if ($def) {
                return (array) $def;
            }
        } catch (\Throwable $e) {}

        return $defaultCurrency;
    }

    /**
     * Get system base URL
     */
    private static function getWhmcsSystemUrl(): string
    {
        try {
            if (Capsule::schema()->hasTable('tblconfiguration')) {
                $url = Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->value('value');
                if (!empty($url)) {
                    return rtrim($url, '/');
                }
            }
        } catch (\Throwable $e) {}

        return '';
    }
}
