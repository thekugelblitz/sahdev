<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;

require_once __DIR__ . '/TaskProviderResolver.php';

/**
 * Per-WHMCS-admin Sahdev preferences (tblsahdev_admin_preferences.preferences_json).
 *
 * JSON shape:
 * - default_provider_id (int, 0 = use global task routing / primary)
 * - tone_default (string|null, null = inherit tblsahdev_settings.tone_default)
 * - features (object): feature_key => bool; omitted keys default to true (no row = all enabled)
 *
 * Feature keys (stable):
 * - ticket_ai, summarizer, historical_context, rewrite, quality_score, canned_kb,
 *   tools, ticket_insights, analytics, audit_delete
 */
class AdminPreferences
{
    public const FEATURE_TICKET_AI = 'ticket_ai';
    public const FEATURE_SUMMARIZER = 'summarizer';
    public const FEATURE_HISTORICAL_CONTEXT = 'historical_context';
    public const FEATURE_REWRITE = 'rewrite';
    public const FEATURE_QUALITY_SCORE = 'quality_score';
    public const FEATURE_CANNED_KB = 'canned_kb';
    public const FEATURE_TOOLS = 'tools';
    public const FEATURE_TICKET_INSIGHTS = 'ticket_insights';
    public const FEATURE_ANALYTICS = 'analytics';
    public const FEATURE_AUDIT_DELETE = 'audit_delete';

    /** @return string[] */
    public static function allFeatureKeys(): array
    {
        return [
            self::FEATURE_TICKET_AI,
            self::FEATURE_SUMMARIZER,
            self::FEATURE_HISTORICAL_CONTEXT,
            self::FEATURE_REWRITE,
            self::FEATURE_QUALITY_SCORE,
            self::FEATURE_CANNED_KB,
            self::FEATURE_TOOLS,
            self::FEATURE_TICKET_INSIGHTS,
            self::FEATURE_ANALYTICS,
            self::FEATURE_AUDIT_DELETE,
        ];
    }

    public static function ensureSchema(): void
    {
        try {
            if (Capsule::schema()->hasTable('tblsahdev_admin_preferences')) {
                return;
            }
        } catch (\Throwable $e) {
            // hasTable can fail if schema layer is unavailable; attempt create below
        }

        try {
            Capsule::schema()->create('tblsahdev_admin_preferences', function ($table) {
                $table->integer('admin_id')->unsigned()->primary();
                $table->longText('preferences_json')->nullable();
                $table->timestamps();
            });
        } catch (\Throwable $e) {
            // Race: another request created the table; duplicate-table errors are OK
            try {
                if (Capsule::schema()->hasTable('tblsahdev_admin_preferences')) {
                    return;
                }
            } catch (\Throwable $ignored) {
            }
            // Table still missing and not a benign race — avoid breaking the request; caller may retry later
        }
    }

    /**
     * @return array{default_provider_id:int,tone_default:?string,features:array<string,bool>}
     */
    /** Valid UI theme identifiers. */
    public const THEME_CLASSIC     = 'classic';
    public const THEME_REMASTERED  = 'remastered';

    private static array $validThemes = [self::THEME_CLASSIC, self::THEME_REMASTERED];

    public static function load(int $adminId): array
    {
        self::ensureSchema();
        $defaults = self::defaultPreferences();

        if ($adminId <= 0) {
            return $defaults;
        }

        try {
            $row = Capsule::table('tblsahdev_admin_preferences')->where('admin_id', $adminId)->first();
        } catch (\Throwable $e) {
            return $defaults;
        }

        if (!$row || empty($row->preferences_json)) {
            return $defaults;
        }

        $decoded = json_decode((string) $row->preferences_json, true);
        if (!is_array($decoded)) {
            return $defaults;
        }

        return self::normalizeArray($decoded);
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{default_provider_id:int,tone_default:?string,features:array<string,bool>}
     */
    public static function normalizeArray(array $raw): array
    {
        $defaults = self::defaultPreferences();
        $out = $defaults;

        if (isset($raw['default_provider_id'])) {
            $out['default_provider_id'] = max(0, (int) $raw['default_provider_id']);
        }
        if (array_key_exists('tone_default', $raw)) {
            $t = $raw['tone_default'];
            if ($t === null || $t === '') {
                $out['tone_default'] = null;
            } else {
                $out['tone_default'] = self::sanitizeTone((string) $t);
            }
        }
        if (array_key_exists('ui_theme', $raw)) {
            $theme = strtolower(trim((string) ($raw['ui_theme'] ?? '')));
            $out['ui_theme'] = in_array($theme, self::$validThemes, true) ? $theme : self::THEME_REMASTERED;
        }
        $featIn = isset($raw['features']) && is_array($raw['features']) ? $raw['features'] : [];
        foreach (self::allFeatureKeys() as $k) {
            if (array_key_exists($k, $featIn)) {
                $out['features'][$k] = !empty($featIn[$k]);
            }
        }

        return $out;
    }

    /**
     * Convenience helper: get effective UI theme for an admin.
     */
    public static function getUiTheme(int $adminId): string
    {
        $prefs = self::load($adminId);
        return $prefs['ui_theme'] ?? self::THEME_REMASTERED;
    }

    /**
     * @param array{default_provider_id?:int,tone_default?:?string,features?:array<string,bool>} $prefs
     */
    public static function save(int $adminId, array $prefs): void
    {
        if ($adminId <= 0) {
            throw new \InvalidArgumentException('Invalid admin id.');
        }

        self::ensureSchema();
        $normalized = self::normalizeArray($prefs);

        $dp = $normalized['default_provider_id'];
        if ($dp > 0 && !TaskProviderResolver::isValidActiveProviderId($dp)) {
            throw new \InvalidArgumentException('Invalid default AI provider.');
        }

        if ($normalized['tone_default'] !== null && $normalized['tone_default'] === '') {
            $normalized['tone_default'] = null;
        }

        $payload = json_encode($normalized);
        $now = \Carbon\Carbon::now();

        $exists = Capsule::table('tblsahdev_admin_preferences')->where('admin_id', $adminId)->exists();
        if ($exists) {
            Capsule::table('tblsahdev_admin_preferences')->where('admin_id', $adminId)->update([
                'preferences_json' => $payload,
                'updated_at'       => $now,
            ]);
        } else {
            Capsule::table('tblsahdev_admin_preferences')->insert([
                'admin_id'           => $adminId,
                'preferences_json'   => $payload,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        }
    }

    /**
     * Effective provider override: POST wins, else per-admin default, else null (routing map / primary).
     *
     * @param array<string,mixed> $settingsArray tblsahdev_settings row as array
     */
    public static function mergeEffectiveOverride(?int $postOverrideId, int $adminId, array $settingsArray): ?int
    {
        if ($postOverrideId !== null && $postOverrideId > 0 && TaskProviderResolver::isValidActiveProviderId($postOverrideId)) {
            return $postOverrideId;
        }

        $prefs = self::load($adminId);
        $d = (int) ($prefs['default_provider_id'] ?? 0);
        if ($d > 0 && TaskProviderResolver::isValidActiveProviderId($d)) {
            return $d;
        }

        return null;
    }

    /**
     * Global org setting must allow the feature; then admin may opt out (false).
     *
     * @param object|array<string,mixed>|null $settings tblsahdev_settings row
     */
    public static function featureEnabled(string $featureKey, int $adminId, $settings): bool
    {
        if (!in_array($featureKey, self::allFeatureKeys(), true)) {
            return false;
        }

        $s = self::settingsToArray($settings);
        if (!self::globalAllowsFeature($featureKey, $s)) {
            return false;
        }

        $prefs = self::load($adminId);

        return !empty($prefs['features'][$featureKey]);
    }

    /**
     * @param array<string,mixed> $settings
     */
    public static function globalAllowsFeature(string $featureKey, array $settings): bool
    {
        switch ($featureKey) {
            case self::FEATURE_TICKET_AI:
            case self::FEATURE_HISTORICAL_CONTEXT:
            case self::FEATURE_REWRITE:
            case self::FEATURE_CANNED_KB:
            case self::FEATURE_ANALYTICS:
            case self::FEATURE_AUDIT_DELETE:
                return true;
            case self::FEATURE_SUMMARIZER:
                return !empty($settings['summarizer_enabled']);
            case self::FEATURE_QUALITY_SCORE:
                return !empty($settings['quality_scorer_enabled']);
            case self::FEATURE_TOOLS:
                return !empty($settings['tools_execution_enabled']);
            case self::FEATURE_TICKET_INSIGHTS:
                return !empty($settings['cron_insights_enabled']);
            default:
                return false;
        }
    }

    /**
     * @param object|array<string,mixed>|null $settings
     * @throws \RuntimeException when denied
     */
    public static function assertFeatureOrThrow(string $featureKey, int $adminId, $settings): void
    {
        if (self::featureEnabled($featureKey, $adminId, $settings)) {
            return;
        }

        throw new \RuntimeException(
            'This Sahdev feature is disabled for your account or by the organization settings.'
        );
    }

    /** @return array<string, string> ajax action => feature key */
    public static function actionFeatureMap(): array
    {
        return [
            'get_payload'                  => self::FEATURE_TICKET_AI,
            'save_response'                => self::FEATURE_TICKET_AI,
            'auto_analyze'                 => self::FEATURE_TICKET_AI,
            'get_rewrite_payload'          => self::FEATURE_REWRITE,
            'rewrite_reply'                => self::FEATURE_REWRITE,
            'generate_summary'             => self::FEATURE_SUMMARIZER,
            'get_summary'                  => self::FEATURE_SUMMARIZER,
            'delete_summary'               => self::FEATURE_SUMMARIZER,
            'generate_historical_context'  => self::FEATURE_HISTORICAL_CONTEXT,
            'get_historical_context'       => self::FEATURE_HISTORICAL_CONTEXT,
            'delete_historical_context'    => self::FEATURE_HISTORICAL_CONTEXT,
            'score_reply'                  => self::FEATURE_QUALITY_SCORE,
            'delete_audit_entries'         => self::FEATURE_AUDIT_DELETE,
            'search_canned_responses'      => self::FEATURE_CANNED_KB,
            'generate_canned_template'     => self::FEATURE_CANNED_KB,
            'save_canned_response'         => self::FEATURE_CANNED_KB,
            'save_kb_article'              => self::FEATURE_CANNED_KB,
            'get_analytics'                => self::FEATURE_ANALYTICS,
            'get_ticket_insights'          => self::FEATURE_TICKET_INSIGHTS,
            'get_insights_queue'           => self::FEATURE_TICKET_INSIGHTS,
            'analyze_single_insight'       => self::FEATURE_TICKET_INSIGHTS,
            'trigger_cron_run'             => self::FEATURE_TICKET_INSIGHTS,
            'test_whmcs_cron_http'         => self::FEATURE_TICKET_INSIGHTS,
            'run_tools_for_ticket'         => self::FEATURE_TOOLS,
            'get_tools_ticket_status'      => self::FEATURE_TOOLS,
            'get_tools_operations'         => self::FEATURE_TOOLS,
            'run_manual_tool'              => self::FEATURE_TOOLS,
            'run_tools_queue'              => self::FEATURE_TOOLS,
            'autopilot_test_run'           => self::FEATURE_TICKET_AI,
        ];
    }

    /** Actions that skip per-admin feature checks (organizational / power). */
    public static function unguardedActions(): array
    {
        return ['set_ui_theme'];
    }

    /**
     * @param object|array<string,mixed>|null $settings
     */
    public static function isFeatureEnabledForUi(string $featureKey, int $adminId, $settings): bool
    {
        return self::featureEnabled($featureKey, $adminId, $settings);
    }

    /**
     * @param object|array<string,mixed>|null $settings
     * @return array<string,mixed>
     */
    private static function settingsToArray($settings): array
    {
        if ($settings === null) {
            return [];
        }
        if (is_array($settings)) {
            return $settings;
        }

        return (array) $settings;
    }

    /**
     * @return array{default_provider_id:int,tone_default:?string,features:array<string,bool>}
     */
    private static function defaultPreferences(): array
    {
        $features = [];
        foreach (self::allFeatureKeys() as $k) {
            $features[$k] = true;
        }

        return [
            'default_provider_id' => 0,
            'tone_default'        => null,
            'ui_theme'            => self::THEME_REMASTERED,
            'features'            => $features,
        ];
    }

    private static function sanitizeTone(string $tone): ?string
    {
        $allowed = ['Professional', 'Technical', 'Friendly', 'Strict', 'Custom'];
        $tone = trim($tone);

        return in_array($tone, $allowed, true) ? $tone : null;
    }
}
