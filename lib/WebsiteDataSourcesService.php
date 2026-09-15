<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;
use Carbon\Carbon;

/**
 * Class WebsiteDataSourcesService
 *
 * Manages website AI-summary JSON data sources (e.g. https://hostingspell.com/ai-summary or custom JSON input).
 * Provides robust remote URL syncing, caching, validation, and token-efficient Markdown grounding
 * for both unauthenticated visitors and logged-in clients in Client Live Chat.
 */
class WebsiteDataSourcesService
{
    /**
     * Get all website data sources.
     */
    public static function getAll(): array
    {
        try {
            if (!Capsule::schema()->hasTable('tblsahdev_website_datasources')) {
                return [];
            }
            return Capsule::table('tblsahdev_website_datasources')
                ->orderBy('id', 'desc')
                ->get()
                ->toArray();
        } catch (\Throwable $e) {
            ModuleLogger::error('website_datasources', 'Error fetching all datasources: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get single data source by ID.
     */
    public static function getById(int $id): ?object
    {
        try {
            if (!Capsule::schema()->hasTable('tblsahdev_website_datasources')) {
                return null;
            }
            return Capsule::table('tblsahdev_website_datasources')->where('id', $id)->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Create a new website data source.
     */
    public static function create(array $data): int
    {
        $now = Carbon::now();
        $sourceType = in_array($data['source_type'] ?? '', ['url', 'custom'], true) ? $data['source_type'] : 'url';
        $url = !empty($data['source_url']) ? trim($data['source_url']) : null;
        $customContent = !empty($data['custom_content']) ? trim($data['custom_content']) : null;

        $id = Capsule::table('tblsahdev_website_datasources')->insertGetId([
            'name'           => trim($data['name'] ?? 'Website AI Summary'),
            'source_type'    => $sourceType,
            'source_url'     => $url,
            'custom_content' => $customContent,
            'cached_content' => ($sourceType === 'custom') ? $customContent : null,
            'last_synced_at' => ($sourceType === 'custom') ? $now : null,
            'sync_status'    => ($sourceType === 'custom') ? 'success' : 'pending',
            'sync_error'     => null,
            'is_enabled'     => !empty($data['is_enabled']) ? 1 : 0,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        // If URL provided, trigger initial sync immediately
        if ($sourceType === 'url' && !empty($url)) {
            self::syncSource($id);
        }

        return $id;
    }

    /**
     * Update an existing data source.
     */
    public static function update(int $id, array $data): bool
    {
        $source = self::getById($id);
        if (!$source) {
            return false;
        }

        $now = Carbon::now();
        $sourceType = in_array($data['source_type'] ?? '', ['url', 'custom'], true) ? $data['source_type'] : $source->source_type;
        $url = isset($data['source_url']) ? trim($data['source_url']) : $source->source_url;
        $customContent = isset($data['custom_content']) ? trim($data['custom_content']) : $source->custom_content;

        $update = [
            'name'        => isset($data['name']) ? trim($data['name']) : $source->name,
            'source_type' => $sourceType,
            'source_url'  => $url,
            'custom_content' => $customContent,
            'is_enabled'  => isset($data['is_enabled']) ? (!empty($data['is_enabled']) ? 1 : 0) : $source->is_enabled,
            'updated_at'  => $now,
        ];

        if ($sourceType === 'custom') {
            $update['cached_content'] = $customContent;
            $update['last_synced_at'] = $now;
            $update['sync_status']    = 'success';
            $update['sync_error']     = null;
        }

        Capsule::table('tblsahdev_website_datasources')->where('id', $id)->update($update);

        if ($sourceType === 'url' && !empty($url) && ($url !== $source->source_url || empty($source->cached_content))) {
            self::syncSource($id);
        }

        return true;
    }

    /**
     * Delete data source by ID.
     */
    public static function delete(int $id): bool
    {
        try {
            return (bool) Capsule::table('tblsahdev_website_datasources')->where('id', $id)->delete();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Toggle enabled state.
     */
    public static function toggle(int $id): bool
    {
        $source = self::getById($id);
        if (!$source) {
            return false;
        }
        $newVal = $source->is_enabled ? 0 : 1;
        Capsule::table('tblsahdev_website_datasources')->where('id', $id)->update([
            'is_enabled' => $newVal,
            'updated_at' => Carbon::now(),
        ]);
        return (bool) $newVal;
    }

    /**
     * Synchronize and cache a data source.
     */
    public static function syncSource(int $sourceId): array
    {
        $source = self::getById($sourceId);
        if (!$source) {
            return ['success' => false, 'error' => 'Data source not found.'];
        }

        $now = Carbon::now();

        // If custom content, no HTTP request needed
        if ($source->source_type === 'custom') {
            $content = trim((string)$source->custom_content);
            if (empty($content)) {
                Capsule::table('tblsahdev_website_datasources')->where('id', $sourceId)->update([
                    'sync_status' => 'error',
                    'sync_error'  => 'Custom input content is empty.',
                    'updated_at'  => $now,
                ]);
                return ['success' => false, 'error' => 'Custom input content is empty.'];
            }

            Capsule::table('tblsahdev_website_datasources')->where('id', $sourceId)->update([
                'cached_content' => $content,
                'last_synced_at' => $now,
                'sync_status'    => 'success',
                'sync_error'     => null,
                'updated_at'     => $now,
            ]);
            return ['success' => true, 'message' => 'Custom content validated and cached.'];
        }

        // URL source fetching
        $url = trim((string)$source->source_url);
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            Capsule::table('tblsahdev_website_datasources')->where('id', $sourceId)->update([
                'sync_status' => 'error',
                'sync_error'  => 'Invalid or empty URL provided.',
                'updated_at'  => $now,
            ]);
            return ['success' => false, 'error' => 'Invalid or empty URL provided.'];
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 4);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json, text/plain, */*',
            'User-Agent: Sahdev-AI-Bot/2.0 (WHMCS Presales Assistant)'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if (!empty($curlErr)) {
            $err = "Connection failed: {$curlErr}";
            Capsule::table('tblsahdev_website_datasources')->where('id', $sourceId)->update([
                'sync_status' => 'error',
                'sync_error'  => $err,
                'updated_at'  => $now,
            ]);
            return ['success' => false, 'error' => $err];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $err = "HTTP request returned status code {$httpCode}.";
            Capsule::table('tblsahdev_website_datasources')->where('id', $sourceId)->update([
                'sync_status' => 'error',
                'sync_error'  => $err,
                'updated_at'  => $now,
            ]);
            return ['success' => false, 'error' => $err];
        }

        $trimmed = trim((string)$response);
        if (empty($trimmed)) {
            $err = "Remote URL returned an empty response.";
            Capsule::table('tblsahdev_website_datasources')->where('id', $sourceId)->update([
                'sync_status' => 'error',
                'sync_error'  => $err,
                'updated_at'  => $now,
            ]);
            return ['success' => false, 'error' => $err];
        }

        // Validate JSON
        $decoded = json_decode($trimmed, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Check if it's HTML error page
            if (stripos($trimmed, '<html') !== false) {
                $err = "Remote URL returned HTML instead of a JSON summary.";
            } else {
                $err = "Invalid JSON returned: " . json_last_error_msg();
            }
            Capsule::table('tblsahdev_website_datasources')->where('id', $sourceId)->update([
                'sync_status' => 'error',
                'sync_error'  => $err,
                'updated_at'  => $now,
            ]);
            return ['success' => false, 'error' => $err];
        }

        // Success - store raw JSON in cached_content
        Capsule::table('tblsahdev_website_datasources')->where('id', $sourceId)->update([
            'cached_content' => $trimmed,
            'last_synced_at' => $now,
            'sync_status'    => 'success',
            'sync_error'     => null,
            'updated_at'     => $now,
        ]);

        $bytes = strlen($trimmed);
        $kb = round($bytes / 1024, 1);
        return [
            'success' => true,
            'message' => "Successfully synchronized ({$kb} KB cached).",
            'bytes'   => $bytes,
        ];
    }

    /**
     * Format a website data source record into a clean, token-efficient Markdown grounding digest for the LLM.
     */
    public static function formatWebsiteForPrompt(object $source): string
    {
        $raw = !empty($source->cached_content) ? $source->cached_content : $source->custom_content;
        if (empty($raw)) {
            return '';
        }

        $name = !empty($source->name) ? $source->name : 'Official Website';
        $url = !empty($source->source_url) ? $source->source_url : '';

        // Decode HTML entities (e.g. &quot;, &#039;) which frequently occur in cached scraped content
        $cleanedRaw = html_entity_decode(html_entity_decode((string) $raw, ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8');

        // Attempt JSON parse
        $data = json_decode($cleanedRaw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            $data = json_decode((string) $raw, true);
        }

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            // Plain text / Markdown fallback - cap to 800 chars to avoid prompt bloat
            $header = "WEBSITE KNOWLEDGE SOURCE: {$name}" . ($url ? " ({$url})" : "");
            $safeSnippet = mb_substr(trim($cleanedRaw), 0, 800);
            return "{$header}\n" . $safeSnippet;
        }

        return self::formatStructuredJsonSummary($name, $url, $data);
    }

    /**
     * Parses structured AI summary JSON (supporting schemas like hostingspell.com/ai-summary)
     * and compiles a lean, token-efficient Markdown digest (essential company profile, infrastructure, guarantees).
     */
    private static function formatStructuredJsonSummary(string $sourceName, string $sourceUrl, array $data): string
    {
        $sections = [];

        $siteTitle = $data['name'] ?? $sourceName;
        $siteUrl = $data['url'] ?? $sourceUrl;
        $sections[] = "=== OFFICIAL WEBSITE SUMMARY: {$siteTitle}" . ($siteUrl ? " ({$siteUrl})" : "") . " ===";

        // 1. Company Profile & Trust
        if (!empty($data['company']) && is_array($data['company'])) {
            $c = $data['company'];
            $cParts = [];
            if (!empty($c['legal_name'])) $cParts[] = "Legal Name: {$c['legal_name']}";
            if (!empty($c['founded_year'])) $cParts[] = "Founded: {$c['founded_year']}";
            if (!empty($c['years_of_expertise'])) $cParts[] = "{$c['years_of_expertise']}+ Years Experience";
            if (!empty($c['tagline'])) $cParts[] = "Tagline: \"{$c['tagline']}\"";
            if (!empty($c['trusted_by_sites'])) $cParts[] = "Trusted by: " . number_format((int)$c['trusted_by_sites']) . "+ websites";

            $desc = !empty($c['description']) ? $c['description'] : '';
            $awards = !empty($c['awards']) && is_array($c['awards']) ? "Awards: " . implode(' | ', $c['awards']) : '';

            $cText = "COMPANY PROFILE:\n" . implode(' | ', $cParts);
            if ($desc) $cText .= "\nDescription: {$desc}";
            if ($awards) $cText .= "\n{$awards}";
            $sections[] = $cText;
        }

        // 2. Infrastructure & Technical Stack
        if (!empty($data['infrastructure']) && is_array($data['infrastructure'])) {
            $infra = $data['infrastructure'];
            $iLines = ["SERVER INFRASTRUCTURE & TECH STACK:"];
            if (!empty($infra['web_server'])) $iLines[] = "- Web Server: {$infra['web_server']}";
            if (!empty($infra['storage_type'])) $iLines[] = "- Storage Type: {$infra['storage_type']}";
            if (!empty($infra['cloud_providers']) && is_array($infra['cloud_providers'])) {
                $iLines[] = "- Cloud Upstream Providers: " . implode(', ', $infra['cloud_providers']);
            }
            if (!empty($infra['security'])) $iLines[] = "- Security & Firewall: {$infra['security']}";
            if (!empty($infra['caching_stack']) && is_array($infra['caching_stack'])) {
                $iLines[] = "- Caching Stack: " . implode(', ', $infra['caching_stack']);
            }
            if (!empty($infra['uptime_sla'])) $iLines[] = "- Uptime SLA: {$infra['uptime_sla']}";
            if (!empty($infra['backup'])) $iLines[] = "- Backups: {$infra['backup']}";
            if (!empty($infra['ssl'])) $iLines[] = "- SSL: {$infra['ssl']}";
            if (!empty($infra['script_installer'])) $iLines[] = "- 1-Click Apps: {$infra['script_installer']}";
            if (!empty($infra['control_panels']) && is_array($infra['control_panels'])) {
                $cpList = [];
                foreach ($infra['control_panels'] as $cat => $panels) {
                    $pArr = is_array($panels) ? implode('/', $panels) : $panels;
                    $cpList[] = "{$cat}: {$pArr}";
                }
                $iLines[] = "- Control Panels: " . implode(' | ', $cpList);
            }
            $sections[] = implode("\n", $iLines);
        }

        // 3. Key Highlights & Guarantees
        if (!empty($data['key_features']) && is_array($data['key_features'])) {
            $featList = array_slice($data['key_features'], 0, 6);
            $sections[] = "CORE ADVANTAGES & GUARANTEES:\n- " . implode("\n- ", $featList);
        }

        // 4. Hosting Categories Overview (High-level summary without duplicating WHMCS catalog)
        if (!empty($data['hosting_products']) && is_array($data['hosting_products'])) {
            $cats = [];
            foreach ($data['hosting_products'] as $pKey => $pGroup) {
                if (!is_array($pGroup)) continue;
                $pTitle = $pGroup['title'] ?? ucfirst(str_replace('_', ' ', $pKey));
                $cats[] = $pTitle;
            }
            if (!empty($cats)) {
                $sections[] = "HOSTING OFFERINGS: " . implode(', ', $cats);
            }
        }

        // 5. Billing, Payment & Guarantees
        if (!empty($data['billing_and_payment']) && is_array($data['billing_and_payment'])) {
            $bp = $data['billing_and_payment'];
            $bLines = ["BILLING & GUARANTEES:"];
            if (!empty($bp['refund_policy'])) $bLines[] = "- Guarantee: {$bp['refund_policy']}";
            if (!empty($bp['free_migration'])) $bLines[] = "- Migration: {$bp['free_migration']}";
            if (!empty($bp['payment_methods']) && is_array($bp['payment_methods'])) {
                $bLines[] = "- Payment Methods: " . implode(', ', array_slice($bp['payment_methods'], 0, 6));
            }
            $sections[] = implode("\n", $bLines);
        }

        // 6. Policy Directives
        if (!empty($data['policies']) && is_array($data['policies'])) {
            $pol = $data['policies'];
            $polLines = ["POLICIES:"];
            if (!empty($pol['refund'])) $polLines[] = "- Refund: {$pol['refund']}";
            if (!empty($pol['prohibited_content'])) $polLines[] = "- Prohibited: {$pol['prohibited_content']}";
            $sections[] = implode("\n", $polLines);
        }

        // 7. Frequently Asked Questions (Top 3 FAQs only)
        if (!empty($data['faqs']) && is_array($data['faqs'])) {
            $faqLines = ["FREQUENTLY ASKED QUESTIONS:"];
            $count = 0;
            foreach ($data['faqs'] as $faq) {
                if (empty($faq['question']) || empty($faq['answer'])) continue;
                $faqLines[] = "Q: {$faq['question']}\nA: {$faq['answer']}";
                $count++;
                if ($count >= 3) break;
            }
            if ($count > 0) {
                $sections[] = implode("\n", $faqLines);
            }
        }

        // 9. Generic key fallback for keys not covered above
        $handledKeys = ['schema_version', 'generated', 'name', 'url', 'billing_portal', 'ai_support', 'company', 'infrastructure', 'key_features', 'hosting_products', 'domains', 'billing_and_payment', 'policies', 'faqs', 'navigation', 'knowledge_base'];
        $extraLines = [];
        foreach ($data as $key => $val) {
            if (in_array($key, $handledKeys, true)) continue;
            if (is_string($val) && !empty($val)) {
                $extraLines[] = "- " . ucfirst(str_replace('_', ' ', $key)) . ": {$val}";
            }
        }
        if (!empty($extraLines)) {
            $sections[] = "ADDITIONAL WEBSITE INFORMATION:\n" . implode("\n", $extraLines);
        }

        return implode("\n\n", $sections);
    }

    /**
     * Ingest all active website data sources into a single concatenated markdown string.
     */
    public static function getActiveWebsiteScope(): string
    {
        try {
            if (!Capsule::schema()->hasTable('tblsahdev_website_datasources')) {
                return '';
            }

            $sources = Capsule::table('tblsahdev_website_datasources')
                ->where('is_enabled', 1)
                ->orderBy('id', 'asc')
                ->get();

            if (empty($sources) || count($sources) === 0) {
                return '';
            }

            $output = [];
            foreach ($sources as $s) {
                // If URL and never cached, attempt on-the-fly sync
                if ($s->source_type === 'url' && empty($s->cached_content) && !empty($s->source_url)) {
                    self::syncSource((int)$s->id);
                    $s = self::getById((int)$s->id);
                }

                $formatted = self::formatWebsiteForPrompt($s);
                if (!empty($formatted)) {
                    $output[] = $formatted;
                }
            }

            return implode("\n\n----------------------------------------\n\n", $output);
        } catch (\Throwable $e) {
            ModuleLogger::error('website_datasources', 'Error generating active website scope: ' . $e->getMessage());
            return '';
        }
    }
}
