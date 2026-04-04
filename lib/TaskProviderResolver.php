<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;

/**
 * Resolves which tblsahdev_providers.id to use per task (multi-model orchestration).
 * task_provider_map JSON on tblsahdev_settings: { "ticket_reply": 2, ... }; omitted = use global primary.
 */
class TaskProviderResolver
{
    public const TASK_TICKET_REPLY       = 'ticket_reply';
    public const TASK_SUMMARIZER         = 'summarizer';
    public const TASK_HISTORICAL_CONTEXT = 'historical_context';
    public const TASK_REWRITE_REPLY      = 'rewrite_reply';
    public const TASK_QUALITY_SCORE      = 'quality_score';
    public const TASK_CANNED_TEMPLATE    = 'canned_template';
    public const TASK_CRON_INSIGHTS      = 'cron_insights';

    /** @return string[] */
    public static function canonicalTaskKeys(): array
    {
        return [
            self::TASK_TICKET_REPLY,
            self::TASK_SUMMARIZER,
            self::TASK_HISTORICAL_CONTEXT,
            self::TASK_REWRITE_REPLY,
            self::TASK_QUALITY_SCORE,
            self::TASK_CANNED_TEMPLATE,
            self::TASK_CRON_INSIGHTS,
        ];
    }

    /**
     * @param mixed $raw JSON string, array, or null from DB
     * @return array<string, int>
     */
    public static function parseTaskProviderMap($raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_array($raw)) {
            $data = $raw;
        } else {
            $data = json_decode((string) $raw, true);
        }
        if (!is_array($data)) {
            return [];
        }
        $allowed = array_flip(self::canonicalTaskKeys());
        $out     = [];
        foreach ($data as $k => $v) {
            if (!isset($allowed[$k])) {
                continue;
            }
            $id = (int) $v;
            if ($id > 0) {
                $out[$k] = $id;
            }
        }

        return $out;
    }

    public static function isValidActiveProviderId(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        try {
            return Capsule::table('tblsahdev_providers')
                ->where('id', $id)
                ->where('is_active', 1)
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param array<string,mixed> $settings Row from tblsahdev_settings as array
     */
    public static function resolveProviderId(string $taskKey, ?int $runtimeOverrideId, array $settings): int
    {
        $primaryId = (int) ($settings['primary_provider_id'] ?? 1);

        if ($runtimeOverrideId !== null && $runtimeOverrideId > 0) {
            if (self::isValidActiveProviderId($runtimeOverrideId)) {
                return $runtimeOverrideId;
            }
        }

        $map = self::parseTaskProviderMap($settings['task_provider_map'] ?? null);
        if (isset($map[$taskKey])) {
            $tid = (int) $map[$taskKey];
            if ($tid > 0 && self::isValidActiveProviderId($tid)) {
                return $tid;
            }
        }

        return $primaryId > 0 ? $primaryId : 1;
    }

    /**
     * Active providers for admin UI dropdowns (ticket override, etc.).
     *
     * @return array<int, array{id:int,name:string,model_name:?string,provider_type:string}>
     */
    public static function listActiveProvidersForRouting(): array
    {
        try {
            $rows = Capsule::table('tblsahdev_providers')
                ->where('is_active', 1)
                ->orderBy('name')
                ->get(['id', 'name', 'model_name', 'provider_type']);
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'             => (int) $r->id,
                'name'           => (string) $r->name,
                'model_name'     => $r->model_name !== null ? (string) $r->model_name : '',
                'provider_type'  => (string) $r->provider_type,
            ];
        }

        return $out;
    }

    /** @return array<string, string> task_key => short label */
    public static function taskKeyLabels(): array
    {
        return [
            self::TASK_TICKET_REPLY       => 'Ticket analysis & reply',
            self::TASK_SUMMARIZER         => 'Summarizer',
            self::TASK_HISTORICAL_CONTEXT => 'Historical client context',
            self::TASK_REWRITE_REPLY      => 'Rewrite / polish draft',
            self::TASK_QUALITY_SCORE      => 'Quality score',
            self::TASK_CANNED_TEMPLATE    => 'Canned template generator',
            self::TASK_CRON_INSIGHTS      => 'Cron insights (batch)',
        ];
    }
}
