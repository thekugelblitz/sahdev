<?php
namespace Sahdev\Lib;

use WHMCS\Database\Capsule;

require_once __DIR__ . '/DiagnosticPolicy.php';

/** Small operational events only. Never store chat text, prompts, keys or raw HTTP bodies. */
class DiagnosticTrace
{
    public const SOURCE = 'diagnostic_workflow';
    public const SOURCES = ['diagnostic_workflow', 'diagnostic_usage', 'diagnostics', 'client_chat_tools', 'LiveChatToolService'];

    public static function record(string $event, array $context = [], ?array $policy = null): void
    {
        $policy = $policy ?? DiagnosticPolicy::get();
        if (empty($policy['logs_enabled'])) return;
        $allowed = ['channel', 'scope_id', 'turn_id', 'trace_id', 'reason', 'intent', 'entity_count',
            'target_host', 'target_source', 'tool', 'success', 'cached', 'error_code', 'http_status',
            'elapsed_ms', 'provider_id', 'check_count', 'status'];
        $data = ['event' => substr($event, 0, 64), 'version'=>DiagnosticPolicy::VERSION];
        foreach ($allowed as $key) {
            if (!isset($context[$key]) || !is_scalar($context[$key])) continue;
            $data[$key] = is_string($context[$key]) ? mb_substr($context[$key], 0, 255) : $context[$key];
        }
        $message = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
        try {
            Capsule::table('tblsahdev_module_logs')->insert([
                'level' => 'info', 'source' => self::SOURCE, 'message' => $message,
                'ticket_id' => ($context['channel'] ?? '') === 'tickets' ? (int) ($context['scope_id'] ?? 0) : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Keep failures visible even if the database log table is unavailable.
            error_log('Sahdev diagnostic log unavailable: ' . $message);
        }
    }

    /** Each pass is bounded and explicitly scoped; never prune unrelated module logs. */
    public static function cleanup(bool $clear = false, int $limit = 500, ?array $policy = null): int
    {
        $policy = $policy ?? DiagnosticPolicy::get();
        $limit = max(1, min(5000, $limit));
        if (!Capsule::schema()->hasTable('tblsahdev_module_logs')) return 0;
        $query = Capsule::table('tblsahdev_module_logs')->whereIn('source', self::SOURCES);
        if (!$clear) $query->where('created_at', '<', date('Y-m-d H:i:s', time() - $policy['log_retention_days'] * 86400));
        $ids = $query->orderBy('id')->limit($limit)->pluck('id')->toArray();
        if (!$ids) return 0;
        return Capsule::table('tblsahdev_module_logs')->whereIn('source', self::SOURCES)->whereIn('id', $ids)->delete();
    }

    public static function automaticCleanup(): int
    {
        $policy = DiagnosticPolicy::get();
        return !empty($policy['logs_auto_cleanup']) ? self::cleanup(false, 1000, $policy) : 0;
    }
}
