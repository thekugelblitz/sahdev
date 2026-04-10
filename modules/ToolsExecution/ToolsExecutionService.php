<?php

namespace Sahdev\Modules\ToolsExecution;

use Carbon\Carbon;
use WHMCS\Database\Capsule;

require_once dirname(__DIR__, 2) . '/lib/GoogleAIProvider.php';
require_once dirname(__DIR__, 2) . '/lib/LMStudioAIProvider.php';
require_once dirname(__DIR__, 2) . '/lib/ReplicateAIProvider.php';
require_once dirname(__DIR__, 2) . '/lib/TicketDataExtractor.php';

class ToolsExecutionService
{
    public const DEFAULT_BASE_URL = 'https://toolsapi.2hs.in';
    private const OPENAPI_REF_FILE = 'toolsapi-2hs-in-openapi.json';
    private const TASK_LOCK_KEY = 'tools_execution_cron_lock_until';

    public static function isEnabled(): bool
    {
        self::ensureSchema();
        $settings = Capsule::table('tblsahdev_settings')->first();
        return !empty($settings) && (int) ($settings->tools_execution_enabled ?? 0) === 1;
    }

    public static function runCron(bool $verbose = false): array
    {
        $service = new self();
        return $service->run($verbose);
    }

    public static function runTicket(int $ticketId, int $adminId = 0, bool $force = false): array
    {
        $service = new self();
        return $service->processTicket($ticketId, $adminId, $force);
    }

    public static function listOperations(): array
    {
        self::ensureSchema();
        $service = new self();
        $allowed = $service->allowedOperations();
        $ops = [];
        foreach ($allowed as $row) {
            [$method, $path] = explode(' ', $row, 2);
            $ops[] = ['method' => strtoupper($method), 'path' => $path];
        }
        return $ops;
    }

    public static function inferSmartValues(int $ticketId, int $adminId = 0): array
    {
        self::ensureSchema();
        $extractor = new \Sahdev\Lib\TicketDataExtractor($ticketId, $adminId);
        $ctx = $extractor->getContext(false);
        $blob = trim(
            (string) ($ctx['subject'] ?? '') . "\n" .
            (string) ($ctx['services_summary'] ?? '') . "\n" .
            implode("\n", array_map(function ($m) {
                return (string) ($m['message'] ?? '');
            }, array_slice((array) ($ctx['messages'] ?? []), -10)))
        );

        preg_match_all('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $blob, $ipMatches);
        preg_match_all('/\b([a-z0-9][a-z0-9-]*\.[a-z]{2,})\b/i', $blob, $domainMatches);
        preg_match_all('/\bhttps?:\/\/[^\s<>"\']+/i', $blob, $urlMatches);

        $ips = array_values(array_unique($ipMatches[0] ?? []));
        $domains = array_values(array_unique(array_map('strtolower', $domainMatches[1] ?? [])));
        $urls = array_values(array_unique($urlMatches[0] ?? []));

        return [
            'ips' => array_slice($ips, 0, 20),
            'domains' => array_slice($domains, 0, 20),
            'urls' => array_slice($urls, 0, 20),
            'default_domain' => $domains[0] ?? '',
            'default_ip' => $ips[0] ?? '',
            'default_url' => $urls[0] ?? '',
        ];
    }

    public static function runManualTool(
        int $ticketId,
        int $adminId,
        string $method,
        string $path,
        array $pathParams = [],
        array $query = [],
        array $body = []
    ): array {
        self::ensureSchema();
        $service = new self();
        $settings = $service->settings();
        if (empty($settings) || (int) ($settings->tools_execution_enabled ?? 0) !== 1) {
            throw new \Exception('Tools execution disabled.');
        }
        $apiKeyEncrypted = (string) ($settings->tools_api_key_encrypted ?? '');
        $apiKey = $apiKeyEncrypted !== '' ? decrypt($apiKeyEncrypted) : '';
        if ($apiKey === '') {
            throw new \Exception('Tools API key missing.');
        }

        $method = strtoupper(trim($method));
        if (!in_array($method, ['GET', 'POST'], true)) {
            throw new \Exception('Unsupported method.');
        }
        $path = trim($path);
        if ($path === '') {
            throw new \Exception('Path is required.');
        }

        $allowed = $service->allowedOperations();
        if (!$service->isAllowedOperation($allowed, $method, $path)) {
            throw new \Exception('Operation is not allowed by OpenAPI allowlist.');
        }

        $finalPath = $service->applyPathParams($path, $pathParams);
        $baseUrl = rtrim((string) ($settings->tools_api_base_url ?: self::DEFAULT_BASE_URL), '/');
        $url = $baseUrl . $finalPath;
        $timeout = max(5, min(60, (int) ($settings->tools_request_timeout_sec ?? 60)));

        $ticket = Capsule::table('tbltickets')->where('id', $ticketId)->first();
        $lastReply = (string) ($ticket->lastreply ?? '');
        $suggestionId = Capsule::table('tblsahdev_tool_suggestions')->insertGetId([
            'ticket_id' => $ticketId,
            'ticket_last_reply_at' => $lastReply !== '' ? $lastReply : null,
            'status' => 'manual',
            'model_name' => 'manual-admin',
            'suggestions_json' => json_encode([[
                'method' => $method,
                'path' => $path,
                'path_params' => $pathParams,
                'query' => $query,
                'body' => $body,
                'reason' => 'Manual admin tool execution',
            ]]),
            'ai_prompt_excerpt' => 'Manual tool execution from ticket page',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $exec = $service->executeHttp($url, $method, $apiKey, $query, $body, $timeout);
        $normalized = $service->normalizeOutput($method, $finalPath, (string) ($exec['body'] ?? ''), (array) $settings);
        Capsule::table('tblsahdev_tool_runs')->insert([
            'suggestion_id' => $suggestionId,
            'ticket_id' => $ticketId,
            'method' => $method,
            'path' => $finalPath,
            'request_query_json' => json_encode($query),
            'request_body_json' => json_encode($body),
            'status' => $exec['status'],
            'http_status' => (int) ($exec['http_status'] ?? 0),
            'response_body' => (string) ($exec['body'] ?? ''),
            'normalized_summary' => (string) ($normalized['summary'] ?? ''),
            'normalized_json' => (string) ($normalized['json'] ?? ''),
            'normalization_status' => (string) ($normalized['status'] ?? 'raw'),
            'normalization_error' => (string) ($normalized['error'] ?? ''),
            'error_message' => (string) ($exec['error'] ?? ''),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        Capsule::table('tblsahdev_tool_suggestions')->where('id', $suggestionId)->update([
            'status' => 'manual-executed',
            'updated_at' => Carbon::now(),
        ]);

        return ['status' => 'success', 'suggestion_id' => $suggestionId, 'execution' => $exec];
    }

    public static function getLatestRunSummary(int $ticketId): ?array
    {
        self::ensureSchema();
        $row = Capsule::table('tblsahdev_tool_suggestions')
            ->where('ticket_id', $ticketId)
            ->orderBy('id', 'desc')
            ->first();

        if (!$row) {
            return null;
        }

        $suggestions = json_decode((string) $row->suggestions_json, true);
        if (!is_array($suggestions)) {
            $suggestions = [];
        }

        $runs = Capsule::table('tblsahdev_tool_runs')
            ->where('suggestion_id', $row->id)
            ->orderBy('id', 'asc')
            ->get();

        return [
            'suggestion_id' => (int) $row->id,
            'ticket_id' => (int) $row->ticket_id,
            'status' => (string) $row->status,
            'model_name' => (string) ($row->model_name ?? ''),
            'suggestions' => $suggestions,
            'runs' => $runs,
            'created_at' => (string) $row->created_at,
            'updated_at' => (string) $row->updated_at,
        ];
    }

    public static function buildPromptContextBlock(int $ticketId): string
    {
        $summary = self::getLatestRunSummary($ticketId);
        if (!$summary) {
            return '';
        }
        $includeRawFallback = true;
        try {
            $settings = Capsule::table('tblsahdev_settings')->first();
            if ($settings) {
                $includeRawFallback = !isset($settings->tools_include_raw_fallback) || (int) $settings->tools_include_raw_fallback === 1;
            }
        } catch (\Throwable $e) {
        }

        $lines = [];
        $lines[] = "=== TOOLS EXECUTION RESULTS (AUTO-RUN) ===";
        $lines[] = "Status: " . $summary['status'];
        if (!empty($summary['model_name'])) {
            $lines[] = "Suggestion model: " . $summary['model_name'];
        }

        $suggestions = $summary['suggestions'] ?? [];
        if (!empty($suggestions)) {
            $lines[] = "Suggested tools:";
            foreach (array_slice($suggestions, 0, 8) as $idx => $s) {
                $method = strtoupper((string) ($s['method'] ?? 'GET'));
                $path = (string) ($s['path'] ?? '');
                $reason = trim((string) ($s['reason'] ?? ''));
                $lines[] = ($idx + 1) . ". {$method} {$path}" . ($reason !== '' ? " - {$reason}" : '');
            }
        }

        $runs = $summary['runs'] ?? [];
        if (!empty($runs)) {
            $lines[] = "Tool outputs:";
            foreach ($runs as $run) {
                $method = strtoupper((string) ($run->method ?? 'GET'));
                $path = (string) ($run->path ?? '');
                $statusCode = (int) ($run->http_status ?? 0);
                $status = (string) ($run->status ?? 'unknown');
                $lines[] = "- {$method} {$path} => status={$status}, http={$statusCode}";
                $readable = trim((string) ($run->normalized_summary ?? ''));
                if ($readable !== '') {
                    $lines[] = "  readable: " . $readable;
                }
                $body = trim((string) ($run->response_body ?? ''));
                if ($body !== '' && $readable === '' && $includeRawFallback) {
                    if (strlen($body) > 1200) {
                        $body = substr($body, 0, 1200) . '... [truncated]';
                    }
                    $lines[] = "  raw: " . $body;
                }
            }
        }

        $body = implode("\n", $lines);
        try {
            $tpl = Capsule::table('tblsahdev_prompt_templates')
                ->where('prompt_key', 'tools_reply_context_wrapper')
                ->value('content');
            if (is_string($tpl) && trim($tpl) !== '') {
                return str_replace('{{TOOLS_EVIDENCE}}', $body, $tpl);
            }
        } catch (\Throwable $e) {
            // Non-fatal: default wrapper below.
        }

        return $body;
    }

    private function run(bool $verbose = false): array
    {
        self::ensureSchema();
        $result = [
            'queued' => 0,
            'processed' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        if (!self::isEnabled()) {
            $result['errors'][] = 'Tools execution is disabled.';
            return $result;
        }

        if (!$this->acquireLock()) {
            $result['errors'][] = 'Tools cron lock is active.';
            return $result;
        }

        try {
            $settings = $this->settings();
            $maxPerRun = max(1, min(50, (int) ($settings->tools_cron_max_per_run ?? 10)));
            $statuses = $this->ticketStatuses($settings);
            $queue = $this->queueTickets($statuses, $maxPerRun);
            $result['queued'] = count($queue);

            foreach ($queue as $ticketId) {
                try {
                    $this->processTicket((int) $ticketId, 0, false);
                    $result['processed']++;
                } catch (\Throwable $e) {
                    $result['failed']++;
                    $result['errors'][] = "Ticket #{$ticketId}: " . $e->getMessage();
                }
            }

            $this->saveHeartbeat($result);
        } finally {
            $this->releaseLock();
        }

        return $result;
    }

    private function processTicket(int $ticketId, int $adminId, bool $force): array
    {
        self::ensureSchema();
        $settings = $this->settings();
        if (empty($settings) || (int) ($settings->tools_execution_enabled ?? 0) !== 1) {
            throw new \Exception('Tools execution disabled.');
        }

        $apiKeyEncrypted = (string) ($settings->tools_api_key_encrypted ?? '');
        $apiKey = $apiKeyEncrypted !== '' ? decrypt($apiKeyEncrypted) : '';
        if ($apiKey === '') {
            throw new \Exception('Tools API key missing.');
        }

        $ticket = Capsule::table('tbltickets')->where('id', $ticketId)->first();
        if (!$ticket) {
            throw new \Exception('Ticket not found.');
        }

        $lastReply = (string) ($ticket->lastreply ?? '');
        if (!$force) {
            $lastDone = Capsule::table('tblsahdev_tool_suggestions')
                ->where('ticket_id', $ticketId)
                ->orderBy('id', 'desc')
                ->first();
            if ($lastDone && (string) ($lastDone->ticket_last_reply_at ?? '') === $lastReply) {
                return ['status' => 'skipped', 'message' => 'Already processed for current reply state.'];
            }
        }

        $extractor = new \Sahdev\Lib\TicketDataExtractor($ticketId, $adminId);
        $context = $extractor->getContext(false);
        $suggestions = $this->generateSuggestions($context, $settings);

        $suggestionId = Capsule::table('tblsahdev_tool_suggestions')->insertGetId([
            'ticket_id' => $ticketId,
            'ticket_last_reply_at' => $lastReply ?: null,
            'status' => 'suggested',
            'model_name' => (string) ($settings->model_name ?? ''),
            'suggestions_json' => json_encode($suggestions),
            'ai_prompt_excerpt' => substr($this->buildSuggestionPrompt($context), 0, 5000),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $baseUrl = rtrim((string) ($settings->tools_api_base_url ?: self::DEFAULT_BASE_URL), '/');
        $allowed = $this->allowedOperations();
        $maxTools = max(1, min(50, (int) ($settings->tools_max_tools_per_ticket ?? 50)));
        $timeout = max(5, min(60, (int) ($settings->tools_request_timeout_sec ?? 60)));
        $retry = max(0, min(3, (int) ($settings->tools_request_retry_count ?? 3)));

        $picked = array_slice($suggestions, 0, $maxTools);
        foreach ($picked as $item) {
            $method = strtoupper((string) ($item['method'] ?? 'GET'));
            $path = (string) ($item['path'] ?? '');
            if (!$this->isAllowedOperation($allowed, $method, $path)) {
                continue;
            }
            $pathParams = is_array($item['path_params'] ?? null) ? $item['path_params'] : [];
            $query = is_array($item['query'] ?? null) ? $item['query'] : [];
            $body = is_array($item['body'] ?? null) ? $item['body'] : [];

            $finalPath = $this->applyPathParams($path, $pathParams);
            $url = $baseUrl . $finalPath;

            $attempt = 0;
            $exec = null;
            do {
                $exec = $this->executeHttp($url, $method, $apiKey, $query, $body, $timeout);
                $attempt++;
            } while ($attempt <= $retry && $exec['status'] === 'error');
            $normalized = $this->normalizeOutput($method, $finalPath, (string) ($exec['body'] ?? ''), (array) $settings);

            Capsule::table('tblsahdev_tool_runs')->insert([
                'suggestion_id' => $suggestionId,
                'ticket_id' => $ticketId,
                'method' => $method,
                'path' => $finalPath,
                'request_query_json' => json_encode($query),
                'request_body_json' => json_encode($body),
                'status' => $exec['status'],
                'http_status' => (int) ($exec['http_status'] ?? 0),
                'response_body' => (string) ($exec['body'] ?? ''),
                'normalized_summary' => (string) ($normalized['summary'] ?? ''),
                'normalized_json' => (string) ($normalized['json'] ?? ''),
                'normalization_status' => (string) ($normalized['status'] ?? 'raw'),
                'normalization_error' => (string) ($normalized['error'] ?? ''),
                'error_message' => (string) ($exec['error'] ?? ''),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }

        Capsule::table('tblsahdev_tool_suggestions')
            ->where('id', $suggestionId)
            ->update([
                'status' => 'executed',
                'updated_at' => Carbon::now(),
            ]);

        return ['status' => 'success', 'suggestion_id' => $suggestionId];
    }

    private function settings()
    {
        self::ensureSchema();
        return Capsule::table('tblsahdev_settings')->first();
    }

    private static function ensureSchema(): void
    {
        try {
            if (Capsule::schema()->hasTable('tblsahdev_settings')) {
                try {
                    Capsule::table('tblsahdev_settings')->select('tools_execution_enabled')->first();
                } catch (\Throwable $e) {
                    Capsule::schema()->table('tblsahdev_settings', function ($table) {
                        $table->boolean('tools_execution_enabled')->default(0);
                        $table->string('tools_api_base_url', 255)->default('https://toolsapi.2hs.in');
                        $table->text('tools_api_key_encrypted')->nullable();
                        $table->integer('tools_max_tools_per_ticket')->default(50);
                        $table->integer('tools_request_timeout_sec')->default(60);
                        $table->integer('tools_request_retry_count')->default(3);
                        $table->integer('tools_cron_max_per_run')->default(10);
                        $table->string('tools_cron_statuses', 512)->nullable();
                        $table->text('tools_filter_domains')->nullable();
                        $table->text('tools_filter_ips')->nullable();
                        $table->text('tools_filter_emails')->nullable();
                        $table->boolean('tools_normalize_enabled')->default(1);
                        $table->boolean('tools_include_raw_fallback')->default(1);
                        $table->timestamp('tools_cron_last_run_at')->nullable();
                        $table->string('tools_cron_last_message', 512)->nullable();
                        $table->timestamp('tools_execution_cron_lock_until')->nullable();
                    });
                }
            }
        } catch (\Throwable $e) {
            // Never hard-fail on schema guard.
        }

        try {
            if (!Capsule::schema()->hasTable('tblsahdev_tool_suggestions')) {
                Capsule::schema()->create('tblsahdev_tool_suggestions', function ($table) {
                    $table->increments('id');
                    $table->integer('ticket_id')->unsigned()->index();
                    $table->timestamp('ticket_last_reply_at')->nullable();
                    $table->string('status', 32)->default('suggested');
                    $table->string('model_name', 255)->nullable();
                    $table->longText('suggestions_json')->nullable();
                    $table->longText('ai_prompt_excerpt')->nullable();
                    $table->timestamps();
                });
            }
        } catch (\Throwable $e) {
            // Never hard-fail on schema guard.
        }

        try {
            if (!Capsule::schema()->hasTable('tblsahdev_tool_runs')) {
                Capsule::schema()->create('tblsahdev_tool_runs', function ($table) {
                    $table->increments('id');
                    $table->integer('suggestion_id')->unsigned()->index();
                    $table->integer('ticket_id')->unsigned()->index();
                    $table->string('method', 16);
                    $table->string('path', 1024);
                    $table->longText('request_query_json')->nullable();
                    $table->longText('request_body_json')->nullable();
                    $table->string('status', 32)->default('ok');
                    $table->integer('http_status')->nullable();
                    $table->longText('response_body')->nullable();
                    $table->longText('normalized_summary')->nullable();
                    $table->longText('normalized_json')->nullable();
                    $table->string('normalization_status', 32)->nullable();
                    $table->text('normalization_error')->nullable();
                    $table->text('error_message')->nullable();
                    $table->timestamps();
                });
            }
        } catch (\Throwable $e) {
            // Never hard-fail on schema guard.
        }

        // Upgrade-safe settings columns
        try {
            Capsule::table('tblsahdev_settings')->select('tools_normalize_enabled')->first();
        } catch (\Throwable $e) {
            try {
                Capsule::schema()->table('tblsahdev_settings', function ($table) {
                    $table->boolean('tools_normalize_enabled')->default(1);
                    $table->boolean('tools_include_raw_fallback')->default(1);
                });
            } catch (\Throwable $ignored) {
            }
        }

        // Upgrade-safe output normalization columns
        try {
            Capsule::table('tblsahdev_tool_runs')->select('normalized_summary')->first();
        } catch (\Throwable $e) {
            try {
                Capsule::schema()->table('tblsahdev_tool_runs', function ($table) {
                    $table->longText('normalized_summary')->nullable();
                    $table->longText('normalized_json')->nullable();
                    $table->string('normalization_status', 32)->nullable();
                    $table->text('normalization_error')->nullable();
                });
            } catch (\Throwable $ignored) {
            }
        }
    }

    private function queueTickets(array $statuses, int $maxPerRun): array
    {
        return Capsule::table('tbltickets as t')
            ->leftJoin('tblsahdev_tool_suggestions as s', 's.ticket_id', '=', 't.id')
            ->whereIn('t.status', $statuses)
            ->where(function ($q) {
                $q->whereNull('s.ticket_id')
                    ->orWhereRaw('t.lastreply > s.ticket_last_reply_at');
            })
            ->orderBy('t.lastreply', 'asc')
            ->limit($maxPerRun)
            ->pluck('t.id')
            ->toArray();
    }

    private function ticketStatuses($settings): array
    {
        $raw = trim((string) ($settings->tools_cron_statuses ?? ''));
        if ($raw === '') {
            return ['Customer-Reply', 'Awaiting Reply', 'Open'];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    private function generateSuggestions(array $context, $settings): array
    {
        $heuristic = $this->buildDeterministicSuggestions($context);
        $provider = $this->initPrimaryProvider($settings);
        $prompt = $this->buildSuggestionPrompt($context);
        $system = "You are a network/support triage planner. Return strict JSON only.";
        $fakeContext = [
            'subject' => (string) ($context['subject'] ?? ''),
            'client_name' => (string) ($context['client_name'] ?? ''),
            'department' => (string) ($context['department'] ?? ''),
            'services_summary' => (string) ($context['services_summary'] ?? ''),
            'attachments_text' => '',
            'messages' => [['admin' => false, 'date' => '', 'message' => $prompt]],
            'attachments_images' => [],
        ];
        $callSettings = [
            'provider_type' => (string) ($settings->provider_type ?? ''),
            'model_name' => (string) ($settings->model_name ?? ''),
            'api_url' => (string) ($settings->api_url ?? ''),
            'api_key' => (string) ($settings->api_key ?? ''),
            'system_prompt' => $system,
            'user_prompt_template' => '{{MESSAGES}}',
            'temperature' => 0.2,
            // Keep tools suggestion generation aligned with global Sahdev max_tokens.
            // If unavailable, use configurable fallback with a minimum standard of 4096.
            'max_tokens' => ((int) ($settings->max_tokens ?? 0)) > 0
                ? (int) $settings->max_tokens
                : max(4096, (int) ($settings->max_tokens_fallback ?? 4096)),
        ];
        $raw = $provider->generateResponse($fakeContext, $callSettings, 'Professional', '');
        $parsed = $this->parseSuggestions($raw, $context);
        if (!empty($heuristic)) {
            $parsed = $this->mergeSuggestions($heuristic, $parsed, 8);
        }
        $parsed = $this->enforceIntentPolicy($context, $parsed);
        if (empty($parsed)) {
            $parsed = $this->enforceIntentPolicy($context, $heuristic);
            if (empty($parsed)) {
                $fallbackTarget = $this->inferTargetFromContext($context);
                if ($fallbackTarget !== '') {
                    return [[
                        'method' => 'GET',
                        'path' => '/dns/{domain}',
                        'path_params' => ['domain' => $fallbackTarget],
                        'query' => ['type' => 'A'],
                        'body' => new \stdClass(),
                        'reason' => 'Fallback DNS validation for ticket domain',
                    ]];
                }
            }
        }
        return $parsed;
    }

    private function buildSuggestionPrompt(array $context): string
    {
        $subject = (string) ($context['subject'] ?? '');
        $services = (string) ($context['services_summary'] ?? '');
        $attachmentsText = trim((string) ($context['attachments_text'] ?? ''));
        if (strlen($attachmentsText) > 3000) {
            $attachmentsText = substr($attachmentsText, 0, 3000) . "\n...[attachments text truncated]";
        }
        $imageSources = [];
        $attachmentsImages = $context['attachments_images'] ?? [];
        if (is_array($attachmentsImages)) {
            foreach (array_slice($attachmentsImages, 0, 8) as $img) {
                $src = '';
                if (is_array($img)) {
                    $src = (string) ($img['source'] ?? '');
                } elseif (is_string($img)) {
                    $src = $img;
                }
                $src = trim($src);
                if ($src !== '') {
                    $imageSources[] = $src;
                }
            }
        }
        $messages = $context['messages'] ?? [];
        $intent = $this->detectIntent($context);
        $entities = $this->extractEntitiesFromContext($context);
        $settings = $this->settings();
        $excludedDomainPatterns = $this->compilePatterns((string) ($settings->tools_filter_domains ?? ''));
        $excludedIpPatterns = $this->compilePatterns((string) ($settings->tools_filter_ips ?? ''));
        $excludedEmailPatterns = $this->compilePatterns((string) ($settings->tools_filter_emails ?? ''));
        $lastMsgs = [];
        foreach (array_slice($messages, -6) as $m) {
            $role = !empty($m['admin']) ? 'ADMIN' : 'CLIENT';
            $lastMsgs[] = $role . ': ' . trim((string) ($m['message'] ?? ''));
        }
        $text = implode("\n", $lastMsgs);
        $openApi = $this->loadOpenApiPathSummary();

        return "Pick the best 1-3 diagnostic tool API calls for this ticket.\n"
            . "Return JSON object with key tools as array.\n"
            . "Each item fields: method, path, path_params(object), query(object), body(object), reason.\n"
            . "Only use paths present in OpenAPI list below.\n"
            . "CRITICAL: prioritize unresolved client asks from latest CLIENT messages. Do not repeat stale/admin-already-answered checks.\n"
            . "CRITICAL: use ONLY entities listed under 'Candidate entities' unless there is a very clear reason.\n"
            . "CRITICAL: return 1-5 tools maximum, high signal only, avoid spam.\n\n"
            . "Detected intent: {$intent}\n"
            . "Candidate entities:\n"
            . "- Domains: " . implode(', ', $entities['domains']) . "\n"
            . "- IPs: " . implode(', ', $entities['ips']) . "\n"
            . "- URLs: " . implode(', ', $entities['urls']) . "\n\n"
            . "Explicitly excluded patterns:\n"
            . "- Domain patterns: " . implode(', ', $excludedDomainPatterns) . "\n"
            . "- IP patterns: " . implode(', ', $excludedIpPatterns) . "\n"
            . "- Email patterns: " . implode(', ', $excludedEmailPatterns) . "\n"
            . "Never choose entities matching excluded patterns.\n\n"
            . "Ticket subject: {$subject}\n"
            . "Client services/server info:\n{$services}\n"
            . "Conversation:\n{$text}\n\n"
            . "Attachment extracted text:\n" . ($attachmentsText !== '' ? $attachmentsText : '[none]') . "\n\n"
            . "Attachment image sources:\n" . (!empty($imageSources) ? implode("\n", $imageSources) : '[none]') . "\n\n"
            . "OpenAPI operations:\n{$openApi}\n\n"
            . "Output example: {\"tools\":[{\"method\":\"GET\",\"path\":\"/dns/{domain}\",\"path_params\":{\"domain\":\"example.com\"},\"query\":{\"type\":\"A\"},\"body\":{},\"reason\":\"Verify DNS record\"}]}";
    }

    private function loadOpenApiPathSummary(): string
    {
        $root = dirname(__DIR__, 2);
        $file = $root . DIRECTORY_SEPARATOR . self::OPENAPI_REF_FILE;
        if (!is_file($file)) {
            return '';
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return '';
        }
        $json = json_decode($raw, true);
        if (!is_array($json) || !isset($json['paths']) || !is_array($json['paths'])) {
            return '';
        }
        $lines = [];
        foreach ($json['paths'] as $path => $ops) {
            if (!is_array($ops)) {
                continue;
            }
            foreach ($ops as $method => $def) {
                $m = strtoupper((string) $method);
                if (!in_array($m, ['GET', 'POST'], true)) {
                    continue;
                }
                $lines[] = "{$m} {$path}";
            }
        }
        return implode("\n", array_slice($lines, 0, 120));
    }

    private function allowedOperations(): array
    {
        $root = dirname(__DIR__, 2);
        $file = $root . DIRECTORY_SEPARATOR . self::OPENAPI_REF_FILE;
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        $json = json_decode((string) $raw, true);
        if (!is_array($json) || !isset($json['paths'])) {
            return [];
        }
        $allowed = [];
        foreach ($json['paths'] as $path => $ops) {
            if (!is_array($ops)) {
                continue;
            }
            foreach ($ops as $method => $_def) {
                $allowed[] = strtoupper((string) $method) . ' ' . (string) $path;
            }
        }
        return $allowed;
    }

    private function isAllowedOperation(array $allowed, string $method, string $path): bool
    {
        $key = strtoupper($method) . ' ' . $path;
        if (in_array($key, $allowed, true)) {
            return true;
        }
        foreach ($allowed as $item) {
            [$m, $p] = explode(' ', $item, 2);
            if ($m !== strtoupper($method)) {
                continue;
            }
            $regex = '#^' . preg_replace('/\{[^}]+\}/', '[^/]+', preg_quote($p, '#')) . '$#';
            if (preg_match($regex, $path)) {
                return true;
            }
        }
        return false;
    }

    private function parseSuggestions($raw, array $context = []): array
    {
        $payload = null;
        if (is_array($raw)) {
            if (isset($raw['tools'])) {
                $payload = $raw['tools'];
            } elseif (!empty($raw) && is_string(reset($raw))) {
                $joined = implode('', array_map('strval', $raw));
                $payload = $this->decodeToolsFromJsonText($joined);
            } else {
                $payload = $raw;
            }
        } elseif (is_string($raw)) {
            $payload = $this->decodeToolsFromJsonText((string) $raw);
        }

        if (!is_array($payload)) {
            return [];
        }

        $out = [];
        $entities = $this->extractEntitiesFromContext($context);
        foreach ($payload as $item) {
            if (!is_array($item)) {
                continue;
            }
            $method = strtoupper((string) ($item['method'] ?? 'GET'));
            $path = (string) ($item['path'] ?? '');
            if ($path === '' || !in_array($method, ['GET', 'POST'], true)) {
                continue;
            }
            $out[] = [
                'method' => $method,
                'path' => $path,
                'path_params' => $this->normalizePathParams($path, is_array($item['path_params'] ?? null) ? $item['path_params'] : [], $entities),
                'query' => is_array($item['query'] ?? null) ? $item['query'] : [],
                'body' => is_array($item['body'] ?? null) ? $item['body'] : [],
                'reason' => (string) ($item['reason'] ?? ''),
            ];
        }
        return $this->dedupeSuggestions($out);
    }

    private function decodeToolsFromJsonText(string $text)
    {
        $clean = trim($text);
        $clean = preg_replace('/```(?:json)?/i', '', $clean);
        $clean = str_replace('```', '', (string) $clean);
        $decoded = json_decode((string) $clean, true);
        if (is_array($decoded)) {
            return $decoded['tools'] ?? $decoded;
        }

        $first = strpos($clean, '{');
        $last = strrpos($clean, '}');
        if ($first !== false && $last !== false && $last > $first) {
            $slice = substr($clean, $first, $last - $first + 1);
            $decoded = json_decode($slice, true);
            if (is_array($decoded)) {
                return $decoded['tools'] ?? $decoded;
            }
        }
        return null;
    }

    private function detectIntent(array $context): string
    {
        $subject = strtolower((string) ($context['subject'] ?? ''));
        $msgs = $context['messages'] ?? [];
        $latest = strtolower((string) ($msgs ? end($msgs)['message'] : ''));
        $blob = $subject . "\n" . $latest;
        if (preg_match('/ssl|certificate|padlock|https/', $blob)) {
            return 'ssl';
        }
        if (preg_match('/dns|nameserver|propagation|mx|txt|cname/', $blob)) {
            return 'dns';
        }
        if (preg_match('/ping|latency|packet|route|traceroute|network/', $blob)) {
            return 'network';
        }
        if (preg_match('/invoice|billing|payment|renew/', $blob)) {
            return 'billing';
        }
        return 'general';
    }

    private function extractEntitiesFromContext(array $context): array
    {
        $settings = $this->settings();
        $subject = (string) ($context['subject'] ?? '');
        $services = (string) ($context['services_summary'] ?? '');
        $messages = $context['messages'] ?? [];
        $lastMsgs = [];
        foreach (array_slice($messages, -12) as $m) {
            $lastMsgs[] = (string) ($m['message'] ?? '');
        }
        $blob = $subject . "\n" . $services . "\n" . implode("\n", $lastMsgs);

        preg_match_all('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $blob, $ipMatches);
        preg_match_all('/\b([a-z0-9][a-z0-9.-]*\.[a-z]{2,})\b/i', $blob, $domainMatches);
        preg_match_all('/\bhttps?:\/\/[^\s<>"\']+/i', $blob, $urlMatches);

        $ips = array_values(array_unique(array_filter($ipMatches[0] ?? [])));
        $domains = array_values(array_unique(array_filter(array_map('strtolower', $domainMatches[1] ?? []))));
        $urls = array_values(array_unique(array_filter($urlMatches[0] ?? [])));
        $domains = array_values(array_filter($domains, function ($d) use ($settings) {
            return !$this->isNoiseDomain($d, (array) $settings);
        }));
        $ips = array_values(array_filter($ips, function ($ip) use ($settings) {
            return !$this->isFilteredIp($ip, (array) $settings);
        }));

        return [
            'domains' => array_slice($domains, 0, 20),
            'ips' => array_slice($ips, 0, 20),
            'urls' => array_slice($urls, 0, 20),
        ];
    }

    private function buildDeterministicSuggestions(array $context): array
    {
        $intent = $this->detectIntent($context);
        $entities = $this->extractEntitiesFromContext($context);
        $latestDomains = $this->extractLatestClientDomains($context);
        $domains = array_values(array_unique(array_merge($latestDomains, $entities['domains'])));
        $ips = $entities['ips'];
        $out = [];

        if ($intent === 'ssl' || $intent === 'dns') {
            foreach (array_slice($domains, 0, 3) as $d) {
                $out[] = [
                    'method' => 'GET',
                    'path' => '/dns/{domain}',
                    'path_params' => ['domain' => $d],
                    'query' => ['type' => 'A'],
                    'body' => [],
                    'reason' => 'Check domain DNS resolution for SSL eligibility',
                ];
            }
            if ($intent === 'ssl') {
                foreach (array_slice($domains, 0, 2) as $d) {
                    $out[] = [
                        'method' => 'GET',
                        'path' => '/ssl/{target}',
                        'path_params' => ['target' => $d],
                        'query' => [],
                        'body' => [],
                        'reason' => 'Inspect SSL state for unresolved domain',
                    ];
                }
                foreach (array_slice($domains, 0, 2) as $d) {
                    $out[] = [
                        'method' => 'GET',
                        'path' => '/dnssec/{domain}',
                        'path_params' => ['domain' => $d],
                        'query' => [],
                        'body' => [],
                        'reason' => 'Validate DNS security posture impacting certificate trust chain',
                    ];
                }
            }
        }

        if ($intent === 'network' && !empty($ips)) {
            foreach (array_slice($ips, 0, 2) as $ip) {
                $out[] = [
                    'method' => 'GET',
                    'path' => '/ping/{host}',
                    'path_params' => ['host' => $ip],
                    'query' => ['count' => 4],
                    'body' => [],
                    'reason' => 'Baseline network reachability check',
                ];
            }
        }

        return $this->dedupeSuggestions($out);
    }

    private function normalizePathParams(string $path, array $params, array $entities): array
    {
        if (!preg_match_all('/\{([^}]+)\}/', $path, $matches)) {
            return $params;
        }
        $placeholders = $matches[1] ?? [];
        foreach ($placeholders as $ph) {
            if (!isset($params[$ph]) || trim((string) $params[$ph]) === '') {
                if (in_array($ph, ['domain'], true) && !empty($entities['domains'])) {
                    $params[$ph] = $entities['domains'][0];
                } elseif (in_array($ph, ['ip'], true) && !empty($entities['ips'])) {
                    $params[$ph] = $entities['ips'][0];
                } elseif (in_array($ph, ['target', 'host', 'url'], true)) {
                    if (!empty($entities['domains'])) {
                        $params[$ph] = $entities['domains'][0];
                    } elseif (!empty($entities['ips'])) {
                        $params[$ph] = $entities['ips'][0];
                    }
                }
            }
        }
        return $params;
    }

    private function isNoiseDomain(string $domain, array $settings = []): bool
    {
        $d = strtolower(trim($domain));
        if ($d === '') {
            return true;
        }
        $noiseSuffixes = [
            'nslookup.io',
            'whynopadlock.com',
            'google.com',
            'googleapis.com',
            'gstatic.com',
            'replicate.com',
        ];
        foreach ($noiseSuffixes as $suffix) {
            if ($d === $suffix || substr($d, -strlen('.' . $suffix)) === '.' . $suffix) {
                return true;
            }
        }
        $customPatterns = $this->compilePatterns((string) ($settings['tools_filter_domains'] ?? ''));
        if ($this->matchesAnyPattern($d, $customPatterns)) {
            return true;
        }
        return false;
    }

    private function extractLatestClientDomains(array $context): array
    {
        $msgs = $context['messages'] ?? [];
        $latestClient = '';
        for ($i = count($msgs) - 1; $i >= 0; $i--) {
            if (empty($msgs[$i]['admin'])) {
                $latestClient = (string) ($msgs[$i]['message'] ?? '');
                break;
            }
        }
        if ($latestClient === '') {
            return [];
        }
        preg_match_all('/\b([a-z0-9][a-z0-9.-]*\.[a-z]{2,})\b/i', $latestClient, $matches);
        $domains = array_values(array_unique(array_filter(array_map('strtolower', $matches[1] ?? []))));
        $settings = (array) $this->settings();
        $domains = array_values(array_filter($domains, function ($d) use ($settings) {
            return !$this->isNoiseDomain($d, $settings);
        }));
        return array_slice($domains, 0, 6);
    }

    private function enforceIntentPolicy(array $context, array $suggestions): array
    {
        $intent = $this->detectIntent($context);
        $entities = $this->extractEntitiesFromContext($context);
        $settings = (array) $this->settings();
        $allowedDomains = array_flip($entities['domains']);
        $allowedIps = array_flip($entities['ips']);

        $pathAllow = [
            'ssl' => ['/ssl/{target}', '/dns/{domain}', '/dns-propagation/{domain}', '/dnssec/{domain}', '/headers/{target}'],
            'dns' => ['/dns/{domain}', '/dns-propagation/{domain}', '/dnssec/{domain}', '/recon/dns/{domain}', '/whois/{domain}'],
            'network' => ['/ping/{host}', '/traceroute/{host}', '/mtr/{target}', '/reverse/{ip}', '/asn/{ip}', '/geoip/{ip}'],
        ];
        $allowedPaths = $pathAllow[$intent] ?? [];
        if (empty($allowedPaths)) {
            return array_slice($this->dedupeSuggestions($suggestions), 0, 5);
        }

        $out = [];
        foreach ($suggestions as $s) {
            $path = (string) ($s['path'] ?? '');
            if (!in_array($path, $allowedPaths, true)) {
                continue;
            }
            $pp = is_array($s['path_params'] ?? null) ? $s['path_params'] : [];
            $domain = strtolower((string) ($pp['domain'] ?? $pp['target'] ?? $pp['host'] ?? ''));
            $ip = (string) ($pp['ip'] ?? $pp['target'] ?? $pp['host'] ?? '');

            if ($domain !== '' && !$this->isNoiseDomain($domain, $settings)) {
                if (empty($allowedDomains) || isset($allowedDomains[$domain])) {
                    $out[] = $s;
                    continue;
                }
            }
            if ($ip !== '' && preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $ip)) {
                if ($this->isFilteredIp($ip, $settings)) {
                    continue;
                }
                if (empty($allowedIps) || isset($allowedIps[$ip])) {
                    $out[] = $s;
                    continue;
                }
            }
        }

        if (empty($out)) {
            $out = $this->buildDeterministicSuggestions($context);
        }
        return array_slice($this->dedupeSuggestions($out), 0, 5);
    }

    private function isFilteredIp(string $ip, array $settings): bool
    {
        $patterns = $this->compilePatterns((string) ($settings['tools_filter_ips'] ?? ''));
        if (empty($patterns)) {
            return false;
        }
        return $this->matchesAnyPattern(trim($ip), $patterns);
    }

    private function compilePatterns(string $raw): array
    {
        $lines = preg_split('/[\r\n,]+/', $raw);
        if (!is_array($lines)) {
            return [];
        }
        $out = [];
        foreach ($lines as $line) {
            $p = strtolower(trim($line));
            if ($p === '') {
                continue;
            }
            $out[] = $p;
        }
        return array_values(array_unique($out));
    }

    private function matchesAnyPattern(string $value, array $patterns): bool
    {
        $val = strtolower(trim($value));
        foreach ($patterns as $pattern) {
            $pattern = strtolower(trim((string) $pattern));
            if ($pattern === '') {
                continue;
            }
            $regex = '/^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/i';
            if (preg_match($regex, $val)) {
                return true;
            }
        }
        return false;
    }

    private function dedupeSuggestions(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            $method = strtoupper((string) ($item['method'] ?? 'GET'));
            $path = (string) ($item['path'] ?? '');
            $params = $item['path_params'] ?? [];
            $key = $method . '|' . $path . '|' . md5(json_encode($params));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $item;
        }
        return $out;
    }

    private function mergeSuggestions(array $a, array $b, int $max): array
    {
        $merged = $this->dedupeSuggestions(array_merge($a, $b));
        return array_slice($merged, 0, max(1, $max));
    }

    private function executeHttp(string $url, string $method, string $apiKey, array $query, array $body, int $timeout): array
    {
        if (!empty($query)) {
            $qs = http_build_query($query);
            $url .= (strpos($url, '?') === false ? '?' : '&') . $qs;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-API-Key: ' . $apiKey,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $resp = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'status' => $error !== '' ? 'error' : 'ok',
            'http_status' => $httpCode,
            'body' => is_string($resp) ? $resp : '',
            'error' => $error,
        ];
    }

    private function normalizeOutput(string $method, string $path, string $rawBody, array $settings): array
    {
        $normalizeEnabled = !array_key_exists('tools_normalize_enabled', $settings) || (int) ($settings['tools_normalize_enabled'] ?? 1) === 1;
        $includeRawFallback = !array_key_exists('tools_include_raw_fallback', $settings) || (int) ($settings['tools_include_raw_fallback'] ?? 1) === 1;
        if (!$normalizeEnabled) {
            return ['status' => 'disabled', 'summary' => '', 'json' => '', 'error' => ''];
        }

        try {
            $decoded = json_decode($rawBody, true);
            $summary = '';
            $normalized = [];

            if (is_array($decoded)) {
                if (strpos($path, '/ssl/') === 0) {
                    $valid = isset($decoded['is_valid']) ? (string) json_encode($decoded['is_valid']) : 'unknown';
                    $days = isset($decoded['days_remaining']) ? (string) $decoded['days_remaining'] : 'unknown';
                    $cn = (string) ($decoded['subject']['commonName'] ?? '');
                    $issuer = (string) ($decoded['issuer']['commonName'] ?? '');
                    $sanCount = is_array($decoded['san'] ?? null) ? count($decoded['san']) : 0;
                    $summary = 'SSL: valid=' . $valid . ', expires_in_days=' . $days
                        . ($cn !== '' ? ', cn=' . $cn : '')
                        . ($issuer !== '' ? ', issuer=' . $issuer : '')
                        . ($sanCount > 0 ? ', san_count=' . $sanCount : '')
                        . '.';
                    $normalized = [
                        'type' => 'ssl',
                        'is_valid' => $decoded['is_valid'] ?? null,
                        'days_remaining' => $decoded['days_remaining'] ?? null,
                        'common_name' => $cn,
                        'issuer' => $decoded['issuer'] ?? null,
                        'san_count' => $sanCount,
                    ];
                } elseif (strpos($path, '/dns/') === 0) {
                    $records = $decoded['records'] ?? [];
                    $count = is_array($records) ? count($records) : 0;
                    $samples = [];
                    if (is_array($records)) {
                        foreach (array_slice($records, 0, 3) as $rec) {
                            if (!is_array($rec)) {
                                continue;
                            }
                            $rtype = (string) ($rec['type'] ?? '');
                            $rvalue = (string) ($rec['value'] ?? '');
                            if ($rtype !== '' || $rvalue !== '') {
                                $samples[] = trim($rtype . ':' . $rvalue, ':');
                            }
                        }
                    }
                    $summary = 'DNS: records_found=' . $count . (empty($samples) ? '' : ', sample=' . implode(' | ', $samples)) . '.';
                    $normalized = ['type' => 'dns', 'records_found' => $count, 'sample_records' => $samples];
                } elseif (strpos($path, '/dnssec/') === 0) {
                    $enabled = isset($decoded['dnssec_enabled']) ? (string) json_encode($decoded['dnssec_enabled']) : 'unknown';
                    $hasDs = isset($decoded['has_ds_record']) ? (string) json_encode($decoded['has_ds_record']) : 'unknown';
                    $hasKey = isset($decoded['has_dnskey_record']) ? (string) json_encode($decoded['has_dnskey_record']) : 'unknown';
                    $algs = is_array($decoded['algorithms'] ?? null) ? implode(',', $decoded['algorithms']) : '';
                    $summary = 'DNSSEC: enabled=' . $enabled . ', has_ds=' . $hasDs . ', has_dnskey=' . $hasKey
                        . ($algs !== '' ? ', algorithms=' . $algs : '')
                        . '.';
                    $normalized = [
                        'type' => 'dnssec',
                        'dnssec_enabled' => $decoded['dnssec_enabled'] ?? null,
                        'has_ds_record' => $decoded['has_ds_record'] ?? null,
                        'has_dnskey_record' => $decoded['has_dnskey_record'] ?? null,
                        'algorithms' => $decoded['algorithms'] ?? [],
                    ];
                } else {
                    $keys = array_slice(array_keys($decoded), 0, 10);
                    $summary = 'Readable extraction: fields=' . implode(', ', $keys) . '.';
                    $normalized = ['type' => 'generic', 'keys' => $keys];
                }
            } else {
                $trimmed = trim($rawBody);
                if ($trimmed !== '') {
                    $summary = 'Non-JSON response excerpt: ' . substr($trimmed, 0, 400);
                    $normalized = ['type' => 'text', 'excerpt' => substr($trimmed, 0, 1000)];
                }
            }

            if ($summary === '' && $includeRawFallback) {
                $summary = 'Raw output fallback: ' . substr(trim($rawBody), 0, 500);
                return ['status' => 'raw_fallback', 'summary' => $summary, 'json' => '', 'error' => 'empty-normalization'];
            }

            return [
                'status' => 'normalized',
                'summary' => $summary,
                'json' => !empty($normalized) ? json_encode($normalized) : '',
                'error' => '',
            ];
        } catch (\Throwable $e) {
            if ($includeRawFallback) {
                return [
                    'status' => 'raw_fallback',
                    'summary' => 'Raw output fallback due to normalization error: ' . substr(trim($rawBody), 0, 500),
                    'json' => '',
                    'error' => $e->getMessage(),
                ];
            }
            return ['status' => 'error', 'summary' => '', 'json' => '', 'error' => $e->getMessage()];
        }
    }

    private function applyPathParams(string $path, array $pathParams): string
    {
        foreach ($pathParams as $k => $v) {
            $path = str_replace('{' . $k . '}', rawurlencode((string) $v), $path);
        }
        return $path;
    }

    private function inferTargetFromContext(array $context): string
    {
        $subject = (string) ($context['subject'] ?? '');
        if (preg_match('/([a-z0-9-]+\.[a-z]{2,})/i', $subject, $m)) {
            return strtolower($m[1]);
        }
        $services = (string) ($context['services_summary'] ?? '');
        if (preg_match('/([a-z0-9-]+\.[a-z]{2,})/i', $services, $m)) {
            return strtolower($m[1]);
        }
        return '';
    }

    private function initPrimaryProvider($settings)
    {
        $providerId = (int) ($settings->primary_provider_id ?? 0);
        $providerRow = Capsule::table('tblsahdev_providers')->where('id', $providerId)->first();
        if (!$providerRow) {
            throw new \Exception('Primary provider not found.');
        }
        $settings->provider_type = (string) $providerRow->provider_type;
        $settings->model_name = (string) ($providerRow->model_name ?? '');
        $settings->api_url = (string) ($providerRow->api_url ?? '');
        $settings->api_key = !empty($providerRow->api_key) ? decrypt($providerRow->api_key) : '';

        if ($providerRow->provider_type === 'google') {
            if ($settings->api_key === '') {
                throw new \Exception('Google provider API key missing.');
            }
            return new \Sahdev\Lib\GoogleAIProvider($settings->api_key);
        }
        if ($providerRow->provider_type === 'lmstudio') {
            if ($settings->api_url === '') {
                throw new \Exception('LMStudio URL missing.');
            }
            return new \Sahdev\Lib\LMStudioAIProvider($settings->api_url, $settings->api_key);
        }
        if ($providerRow->provider_type === 'replicate') {
            if ($settings->api_url === '' || $settings->api_key === '') {
                throw new \Exception('Replicate credentials missing.');
            }
            return new \Sahdev\Lib\ReplicateAIProvider($settings->api_url, $settings->api_key);
        }

        throw new \Exception('Unsupported provider type for tools suggestion.');
    }

    private function acquireLock(): bool
    {
        $settings = $this->settings();
        $now = Carbon::now();
        $lockUntil = $settings ? (string) ($settings->{self::TASK_LOCK_KEY} ?? '') : '';
        if ($lockUntil !== '') {
            try {
                if (Carbon::parse($lockUntil)->gt($now)) {
                    return false;
                }
            } catch (\Throwable $e) {
            }
        }
        Capsule::table('tblsahdev_settings')->where('id', 1)->update([
            self::TASK_LOCK_KEY => $now->copy()->addMinutes(10),
            'updated_at' => Carbon::now(),
        ]);
        return true;
    }

    private function releaseLock(): void
    {
        Capsule::table('tblsahdev_settings')->where('id', 1)->update([
            self::TASK_LOCK_KEY => null,
            'updated_at' => Carbon::now(),
        ]);
    }

    private function saveHeartbeat(array $result): void
    {
        $message = sprintf(
            'Tools cron: queued=%d processed=%d failed=%d',
            (int) ($result['queued'] ?? 0),
            (int) ($result['processed'] ?? 0),
            (int) ($result['failed'] ?? 0)
        );
        Capsule::table('tblsahdev_settings')->where('id', 1)->update([
            'tools_cron_last_run_at' => Carbon::now(),
            'tools_cron_last_message' => substr($message, 0, 500),
            'updated_at' => Carbon::now(),
        ]);
    }
}
