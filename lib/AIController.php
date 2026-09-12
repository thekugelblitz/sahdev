<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;
use Carbon\Carbon;

require_once __DIR__ . '/AIProviderInterface.php';
require_once __DIR__ . '/GoogleAIProvider.php';
require_once __DIR__ . '/LMStudioAIProvider.php';
require_once __DIR__ . '/ReplicateAIProvider.php';
require_once __DIR__ . '/TicketDataExtractor.php';
require_once __DIR__ . '/TaskProviderResolver.php';
require_once dirname(__DIR__) . '/modules/ToolsExecution/ToolsExecutionService.php';

class AIController
{
    private $ticketId;
    private $adminId;
    private $provider;
    private $settings;
    private $promptTemplates = [];

    private $fallbackProvider = null;

    public function __construct(int $ticketId, int $adminId)
    {
        $this->ticketId = $ticketId;
        $this->adminId = $adminId;
        $this->loadSettings();
    }

    private function initializeProvider($providerData)
    {
        if (!$providerData)
            return null;

        $apiUrl = trim((string) ($providerData->api_url ?? ''));
        $ptype = strtolower(trim((string) ($providerData->provider_type ?? '')));

        if ($ptype === 'openrouter' || stripos($apiUrl, 'openrouter.ai') !== false) {
            $apiKey = !empty($providerData->api_key) ? decrypt($providerData->api_key) : '';
            if (empty($apiKey))
                throw new \Exception("OpenRouter Provider '{$providerData->name}' lacks an API Key.");
            require_once __DIR__ . '/OpenRouterAIProvider.php';
            return new OpenRouterAIProvider($apiKey, $apiUrl);
        } elseif ($ptype === 'google') {
            $apiKey = !empty($providerData->api_key) ? decrypt($providerData->api_key) : '';
            if (empty($apiKey))
                throw new \Exception("Google AI Provider '{$providerData->name}' lacks an API Key.");
            return new GoogleAIProvider($apiKey);
        } elseif ($ptype === 'lmstudio') {
            if (empty($providerData->api_url))
                throw new \Exception("Local AI Provider '{$providerData->name}' lacks an API URL.");
            require_once __DIR__ . '/LMStudioAIProvider.php';
            $apiKey = !empty($providerData->api_key) ? decrypt($providerData->api_key) : '';
            return new LMStudioAIProvider($providerData->api_url, $apiKey);
        } elseif ($ptype === 'replicate') {
            if (empty($providerData->api_url))
                throw new \Exception("Replicate Provider '{$providerData->name}' lacks an API URL.");
            $apiKey = !empty($providerData->api_key) ? decrypt($providerData->api_key) : '';
            if (empty($apiKey))
                throw new \Exception("Replicate Provider '{$providerData->name}' lacks an API Key.");
            require_once __DIR__ . '/ReplicateAIProvider.php';
            return new ReplicateAIProvider($providerData->api_url, $apiKey);
        }
        throw new \Exception("Unsupported AI Provider Type: " . $providerData->provider_type);
    }

    private function loadSettings()
    {
        // Auto-migration check bypassing WHMCS schema cache
        try {
            Capsule::table('tblsahdev_providers')->first();
            Capsule::table('tblsahdev_settings')->select('primary_provider_id')->first();
            Capsule::table('tblsahdev_summaries')->first();
            Capsule::table('tblsahdev_audit_trail')->first();
            Capsule::table('tblsahdev_quality_scores')->first();
            Capsule::table('tblsahdev_canned_responses')->first();
        } catch (\Exception $e) {
            require_once dirname(__DIR__) . '/sahdev.php';
            if (function_exists('sahdev_activate')) {
                sahdev_activate();
            }
        }

        $this->settings = Capsule::table('tblsahdev_settings')->first();
        if (!$this->settings) {
            throw new \Exception("Sahdev settings not configured. Please visit Addons > Sahdev.");
        }

        // Convert to array for easier passing
        $this->settings = (array) $this->settings;

        // Load all prompt templates from DB (Prompt Library)
        $this->loadPromptTemplates();

        // Load Primary Provider
        $primaryId = $this->settings['primary_provider_id'] ?? 1;
        $primaryData = Capsule::table('tblsahdev_providers')->where('id', $primaryId)->first();
        if (!$primaryData)
            throw new \Exception("Primary AI Provider not found. Please check Sahdev settings.");

        $this->settings['model_name'] = $primaryData->model_name; // inject for hash logic
        $this->settings['api_url'] = $primaryData->api_url ?? ''; // inject for getPayload() LMStudio routing
        $this->settings['provider_type'] = $primaryData->provider_type; // inject provider type
        $this->settings['api_key'] = !empty($primaryData->api_key) ? decrypt($primaryData->api_key) : ''; // inject decrypted key
        $this->provider = $this->initializeProvider($primaryData);

        // Load Fallback Provider (Optional)
        $fallbackId = $this->settings['fallback_provider_id'] ?? 0;
        if ($fallbackId > 0 && $fallbackId !== $primaryId) {
            $fallbackData = Capsule::table('tblsahdev_providers')->where('id', $fallbackId)->first();
            if ($fallbackData) {
                // We keep the settings instance mostly the same but initialize the second provider
                $this->fallbackProvider = $this->initializeProvider($fallbackData);
                $this->settings['fallback_model_name'] = $fallbackData->model_name;
                $this->settings['fallback_api_key'] = !empty($fallbackData->api_key) ? decrypt($fallbackData->api_key) : ''; // inject decrypted key
            }
        }
    }

    /**
     * Merge global settings with a specific provider row (model, URL, type, key) for one AI call.
     *
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    private function mergeSettingsForProviderRow(array $base, $providerRow): array
    {
        $out = $base;
        if (!$providerRow) {
            return $out;
        }
        $out['model_name']     = $providerRow->model_name ?? ($out['model_name'] ?? '');
        $out['api_url']        = $providerRow->api_url ?? '';
        $out['provider_type']  = $providerRow->provider_type ?? '';
        $out['api_key']        = !empty($providerRow->api_key) ? decrypt($providerRow->api_key) : '';

        return $out;
    }

    /**
     * System prompt for ticket reply / analysis: base setting + knowledgebase/*.txt + optional historical client context.
     */
    private function buildReplySystemPrompt(bool $includeHistoricalContext): string
    {
        $systemPrompt = $this->settings['system_prompt'] ?? '';

        $kbPath = dirname(__DIR__) . '/knowledgebase';
        if (is_dir($kbPath)) {
            $kbRules = '';
            $dir = new \DirectoryIterator($kbPath);
            foreach ($dir as $fileinfo) {
                if (!$fileinfo->isDot() && $fileinfo->getExtension() === 'txt') {
                    $content = @file_get_contents($fileinfo->getPathname());
                    if ($content) {
                        $kbRules .= "\n--- Rule: {$fileinfo->getFilename()} ---\n" . trim($content) . "\n";
                    }
                }
            }
            if ($kbRules !== '') {
                $systemPrompt .= "\n\n=== RULES & KNOWLEDGEBASE ===\n" .
                    "The following facts, rules, and guidelines MUST be strictly adhered to when crafting the CLIENT_REPLY:\n" .
                    $kbRules;
            }
        }

        if ($includeHistoricalContext) {
            $historicalContext = $this->getHistoricalContext();
            if ($historicalContext) {
                $systemPrompt .= "\n\n=== HISTORICAL CLIENT CONTEXT ===\n" .
                    "The following is an AI-generated summary of the client's past tickets. Use this to understand their history and tailor your response if their past issues are related to the current ticket:\n" .
                    $historicalContext;
            }
        }

        return $systemPrompt;
    }

    /**
     * Resolved primary + global fallback instances and merged call settings for a task.
     *
     * @return array{
     *   primary: object,
     *   fallback: ?object,
     *   primary_row: object,
     *   fallback_row: ?object,
     *   call_settings: array,
     *   fallback_call_settings: ?array,
     *   effective_provider_id: int
     * }
     */
    private function getProviderStackForTask(string $taskKey, ?int $overrideId = null): array
    {
        $effectiveId = TaskProviderResolver::resolveProviderId($taskKey, $overrideId, $this->settings);
        $primaryRow  = Capsule::table('tblsahdev_providers')->where('id', $effectiveId)->first();
        if (!$primaryRow) {
            $pid = (int) ($this->settings['primary_provider_id'] ?? 1);
            $primaryRow = Capsule::table('tblsahdev_providers')->where('id', $pid)->first();
        }
        if (!$primaryRow) {
            throw new \Exception('AI Provider not found for this task. Check Sahdev AI Providers and routing.');
        }

        $primaryInst  = $this->initializeProvider($primaryRow);
        $callSettings = $this->mergeSettingsForProviderRow($this->settings, $primaryRow);

        $globalFbId = (int) ($this->settings['fallback_provider_id'] ?? 0);
        $fallbackRow = null;
        $fallbackInst = null;
        $fallbackCallSettings = null;
        if ($globalFbId > 0 && $globalFbId !== (int) $primaryRow->id) {
            $fallbackRow = Capsule::table('tblsahdev_providers')->where('id', $globalFbId)->first();
            if ($fallbackRow) {
                $fallbackInst = $this->initializeProvider($fallbackRow);
                $fallbackCallSettings = $this->mergeSettingsForProviderRow($this->settings, $fallbackRow);
                $fallbackCallSettings['fallback_model_name'] = $fallbackRow->model_name;
                $fallbackCallSettings['fallback_api_key']    = !empty($fallbackRow->api_key) ? decrypt($fallbackRow->api_key) : '';
            }
        }

        return [
            'primary'                => $primaryInst,
            'fallback'               => $fallbackInst,
            'primary_row'            => $primaryRow,
            'fallback_row'           => $fallbackRow,
            'call_settings'        => $callSettings,
            'fallback_call_settings' => $fallbackCallSettings,
            'effective_provider_id'  => (int) $primaryRow->id,
        ];
    }

    public function getAnalysis(string $tone = null, string $customInstruction = null, bool $forceRegenerate = false, bool $forceFallback = false, string $intent = 'AUTO', bool $useSummaryToggle = true, bool $includeHistoricalContext = false, string $technicalContext = '', ?int $overrideProviderId = null, bool $includeToolsContext = true, bool $includeAdminNotes = false): array
    {
        // 1. Rate Limit Check
        $this->checkRateLimit();

        // 2. Extract Data
        $scrubPII = !empty($this->settings['compliance_mode']) || !empty($this->settings['pii_scrub_enabled']);
        $extractor = new TicketDataExtractor($this->ticketId, $this->adminId);
        $context = $extractor->getContext($scrubPII, $includeAdminNotes);

        if (!$tone) {
            $tone = $this->settings['tone_default'];
        }

        // Inject intent directive into customInstruction (highest priority)
        $intentDirective = $this->buildIntentDirective($intent);
        if (!empty($intentDirective)) {
            $customInstruction = empty($customInstruction)
                ? $intentDirective
                : $intentDirective . "\n\n" . $customInstruction;
        }

        // Inject technical context if provided
        if (!empty($technicalContext)) {
            $techInstruction = "=== TECHNICAL CONTEXT PROVIDED BY ADMIN ===\n" . $technicalContext;
            $customInstruction = empty($customInstruction) ? $techInstruction : $customInstruction . "\n\n" . $techInstruction;
        }

        if ($includeToolsContext) {
            $toolContext = \Sahdev\Modules\ToolsExecution\ToolsExecutionService::buildPromptContextBlock($this->ticketId);
            if ($toolContext !== '') {
                $customInstruction = empty($customInstruction) ? $toolContext : $customInstruction . "\n\n" . $toolContext;
            }
        }

        // Inject Quality Scorer Schema if enabled
        if (!empty($this->settings['quality_scorer_enabled'])) {
            $scorerInstruction = "=== QUALITY SCORER REQUIREMENT ===\nYou MUST evaluate the overall quality of the CLIENT_REPLY and output the precise fields in your JSON root:\n\"SCORE\": int (0-100 overall rating)\n\"CLARITY\": int (0-100)\n\"TONE_SCORE\": int (0-100)\n\"COMPLETENESS\": int (0-100)\n\"REPLY_NOTES\": \"string (brief explanation of the scores)\"\nMake sure the response is strict JSON.";
            $customInstruction = empty($customInstruction)
                ? $scorerInstruction
                : $customInstruction . "\n\n" . $scorerInstruction;
        }

        // Summarizer injection: replace full message history with condensed summary if toggle is checked
        // We prioritize the toggle if a summary exists, ensuring it works as long as the user can see the panel.
        $summarizerEnabled = !empty($this->settings['summarizer_enabled']);
        if ($useSummaryToggle) {
            $existingSummary = $this->getSummary();
            if ($existingSummary) {
                $context['messages'] = [[
                    'admin'   => false,
                    'date'    => Carbon::now()->toDateTimeString(),
                    'message' => "[AI SUMMARY — Full history condensed for efficiency]\n" . $existingSummary,
                ]];
                $context['_summary_used'] = true;
            }
        }

        $stack        = $this->getProviderStackForTask(TaskProviderResolver::TASK_TICKET_REPLY, $overrideProviderId);
        $callSettings = $stack['call_settings'];
        $effectiveSystemPrompt = $this->buildReplySystemPrompt($includeHistoricalContext);
        $callSettings['system_prompt'] = $effectiveSystemPrompt;

        // 3. Hash Generation for Cache (resolved model + effective system prompt)
        $hashData = serialize([
            $context['subject'],
            $context['messages'], // Includes full message history
            $context['services_summary'] ?? '',
            $context['department'] ?? '',
            $context['client_name'] ?? '',
            $tone,
            $customInstruction,
            $intent,
            $callSettings['model_name'],
            $effectiveSystemPrompt
        ]);
        $hashSignature = hash('sha256', $hashData);

        // 4. Check Cache
        if (!$forceRegenerate) {
            $cached = Capsule::table('tblsahdev_cache')
                ->where('ticket_id', $this->ticketId)
                ->where('hash_signature', $hashSignature)
                ->first();

            if ($cached) {
                return [
                    'status' => 'success',
                    'data' => json_decode($cached->ai_response, true),
                    'cached' => true
                ];
            }
        }

        // 5. Call AI Provider (task primary with global fallback)
        $startTime = microtime(true);

        if ($forceFallback && $stack['fallback']) {
            $fbSettings     = $stack['fallback_call_settings'] ?? $this->mergeSettingsForProviderRow($this->settings, $stack['fallback_row']);
            $fbSettings['system_prompt'] = $effectiveSystemPrompt;
            $response       = $stack['fallback']->generateResponse($context, $fbSettings, $tone, $customInstruction);
            $activeProvider = $stack['fallback'];
        } else {
            try {
                $response       = $stack['primary']->generateResponse($context, $callSettings, $tone, $customInstruction);
                $activeProvider = $stack['primary'];
            } catch (\Exception $e) {
                $this->logRequest($context, null, 0, microtime(true) - $startTime, "Primary Error: " . $e->getMessage());

                if ($stack['fallback'] && $stack['fallback_row']) {
                    $fallbackStart = microtime(true);
                    $fbSettings    = $stack['fallback_call_settings'] ?? $this->mergeSettingsForProviderRow($this->settings, $stack['fallback_row']);
                    $fbSettings['system_prompt'] = $effectiveSystemPrompt;

                    try {
                        $response       = $stack['fallback']->generateResponse($context, $fbSettings, $tone, $customInstruction);
                        $activeProvider = $stack['fallback'];
                    } catch (\Exception $fallbackErr) {
                        $this->logRequest($context, null, 0, microtime(true) - $fallbackStart, "Fallback Error: " . $fallbackErr->getMessage());
                        throw new \Exception("Both Primary and Fallback AI Providers failed. Latest Error: " . $fallbackErr->getMessage());
                    }
                } else {
                    throw $e;
                }
            }
        }

        $executionTimeMs = round((microtime(true) - $startTime) * 1000);
        $tokenUsage = $activeProvider->getLastTokenUsage();
        $tokenDetails = $activeProvider->getLastTokenDetails();

        // 6. Cache the successful result
        Capsule::table('tblsahdev_cache')->insert([
            'ticket_id' => $this->ticketId,
            'hash_signature' => $hashSignature,
            'ai_response' => json_encode($response),
            'created_at' => Carbon::now()
        ]);

        // 6a. Process Quality Score if enabled
        if (!empty($this->settings['quality_scorer_enabled']) && isset($response['SCORE'])) {
            Capsule::table('tblsahdev_quality_scores')->insert([
                'ticket_id' => $this->ticketId,
                'admin_id' => $this->adminId,
                'score' => (int)($response['SCORE'] ?? 0),
                'clarity' => (int)($response['CLARITY'] ?? 0),
                'tone_score' => (int)($response['TONE_SCORE'] ?? 0),
                'completeness' => (int)($response['COMPLETENESS'] ?? 0),
                'notes' => substr($response['REPLY_NOTES'] ?? '', 0, 500),
                'is_ai_generated' => 1,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ]);
        }

        // 6b. Log Audit Trail
        $providerName = $activeProvider instanceof AIProviderInterface ? $activeProvider->getName() : 'Unknown';
        $fullPrompt = json_encode([
            'system' => $effectiveSystemPrompt,
            'tone' => $tone,
            'instruction' => $customInstruction,
            'context' => $context
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $this->logAuditEntry('analysis', $fullPrompt, json_encode($response), $tokenUsage, $executionTimeMs, $providerName);

        // 7. Log the Request
        $this->logRequest($context, $response, $tokenUsage, $executionTimeMs);

        // 8. Update Rate Limit Counter
        $this->incrementRateLimit();

        return [
            'status' => 'success',
            'data' => $response,
            'cached' => false,
            'execution_time_ms' => $executionTimeMs,
            'tokens_used' => $tokenUsage,
            'tokens_details' => $tokenDetails
        ];
    }

    /**
     * Loads all prompt templates from the DB into $this->promptTemplates.
     * Falls back gracefully to empty array if the table doesn't exist yet.
     */
    private function loadPromptTemplates(): void
    {
        $hardcodedDefaults = [
            'system_default'       => $this->settings['system_prompt'] ?? '',
            'user_prompt_template' => $this->settings['user_prompt_template'] ?? '',
            'summarizer'           => "You are a senior technical support analyst. Your job is to create concise, accurate ticket summaries that capture the essential context: root issue, actions taken, client sentiment, and current status.\n\nSUMMARY RULES:\n- Length: adapt dynamically based on ticket complexity (short tickets -> 3-5 lines; complex tickets -> 8-12 lines)\n- Include: the original problem, key technical details exchanged, any steps already tried, current status\n- Do NOT include greetings, small talk, or formatting metadata\n- Write in past-tense, third-person, concise prose\n- Output ONLY the summary text. No labels, no JSON, no prefixes.",
            'historical_context'   => "You are a customer support historian. Analyze the user's past tickets against their current active issue.\nYOUR TASK:\n1. explicitly highlight and summarize any past tickets that are related or similar to the current issue.\n2. briefly group and summarize unrelated tickets just to provide general context on their account health.\nFormat your response purely in Markdown. Do not include JSON. Be concise but helpful for the support agent.",
            'rewrite_reply'        => '',  // built dynamically in rewriteReply
            'score_reply'          => "You are an expert QA Manager scoring support replies. Output strictly a single raw JSON object matching the requested schema.",
            'canned_template'      => "Rewrite the following support ticket reply into a reusable, generalized canned response template.\n- Remove any specific client names, domain names, IP addresses, or highly specific dates.\n- Replace removed specifics with general placeholders like [Client Name], [Domain], [IP Address].\n- Make the tone professional and helpful.\n- DO NOT include any JSON wrapping or preamble, just the raw text template.\n\n=== DRAFT TO GENERALIZE ===\n",
            'tools_evidence_system' => "You are a technical evidence normalizer for hosting support. Convert raw network diagnostic outputs into concise, factual findings. Never fabricate values.",
            'tools_evidence_user' => "Normalize the following raw tool output for support staff:\n\n{{RAW_TOOL_OUTPUT}}",
            'tools_reply_context_wrapper' => "=== TOOLS EXECUTION RESULTS (AUTO-RUN) ===\n{{TOOLS_EVIDENCE}}",
        ];

        // Merge with DB values (DB wins over hardcoded defaults)
        $this->promptTemplates = $hardcodedDefaults;
        try {
            $rows = Capsule::table('tblsahdev_prompt_templates')->get();
            foreach ($rows as $row) {
                if (!empty(trim($row->content))) {
                    $this->promptTemplates[$row->prompt_key] = $row->content;
                }
            }
            // Keep settings in sync (system_default + user_prompt_template are read by providers directly via settings array)
            if (!empty($this->promptTemplates['system_default'])) {
                $this->settings['system_prompt'] = $this->promptTemplates['system_default'];
            }
            if (!empty($this->promptTemplates['user_prompt_template'])) {
                $this->settings['user_prompt_template'] = $this->promptTemplates['user_prompt_template'];
            }
        } catch (\Exception $e) {
            // Table likely doesn't exist yet — silently use hardcoded defaults
        }
    }

    private function checkRateLimit()
    {
        $record = Capsule::table('tblsahdev_rate_limit')
            ->where('admin_id', $this->adminId)
            ->first();

        if ($record) {
            // Optional: Implement a sliding window or hourly limit
            // E.g., max 100 requests per hour per admin
            if ($record->requests_count > 1000) {
                // Hardcap just in case, reset daily via cron if you want
            }
        }
    }

    private function incrementRateLimit()
    {
        Capsule::table('tblsahdev_rate_limit')->updateOrInsert(
            ['admin_id' => $this->adminId],
            [
                'requests_count' => Capsule::raw('requests_count + 1'),
                'last_request_at' => Carbon::now()
            ]
        );
    }

    public function getPayload(string $tone = null, string $customInstruction = null, bool $forceRegenerate = false, string $intent = 'AUTO', bool $useSummaryToggle = true, bool $includeHistoricalContext = false, string $technicalContext = '', ?int $overrideProviderId = null, bool $includeToolsContext = true, bool $includeAdminNotes = false): array
    {
        // 1. Rate Limit Check
        $this->checkRateLimit();

        $stack        = $this->getProviderStackForTask(TaskProviderResolver::TASK_TICKET_REPLY, $overrideProviderId);
        $callSettings = $stack['call_settings'];

        // 2. Extract Data
        $scrubPII = !empty($this->settings['compliance_mode']) || !empty($this->settings['pii_scrub_enabled']);
        $extractor = new TicketDataExtractor($this->ticketId, $this->adminId);
        $context = $extractor->getContext($scrubPII, $includeAdminNotes);

        if (!$tone) {
            $tone = $this->settings['tone_default'];
        }

        // Inject intent directive into customInstruction (highest priority)
        $intentDirective = $this->buildIntentDirective($intent);
        if (!empty($intentDirective)) {
            $customInstruction = empty($customInstruction)
                ? $intentDirective
                : $intentDirective . "\n\n" . $customInstruction;
        }

        // Inject technical context if provided
        if (!empty($technicalContext)) {
            $techInstruction = "=== TECHNICAL CONTEXT PROVIDED BY ADMIN ===\n" . $technicalContext;
            $customInstruction = empty($customInstruction) ? $techInstruction : $customInstruction . "\n\n" . $techInstruction;
        }

        if ($includeToolsContext) {
            $toolContext = \Sahdev\Modules\ToolsExecution\ToolsExecutionService::buildPromptContextBlock($this->ticketId);
            if ($toolContext !== '') {
                $customInstruction = empty($customInstruction) ? $toolContext : $customInstruction . "\n\n" . $toolContext;
            }
        }

        // Inject Quality Scorer Schema if enabled
        if (!empty($this->settings['quality_scorer_enabled'])) {
            $scorerInstruction = "=== QUALITY SCORER REQUIREMENT ===\nYou MUST evaluate the overall quality of the CLIENT_REPLY and output the precise fields in your JSON root:\n\"SCORE\": int (0-100 overall rating)\n\"CLARITY\": int (0-100)\n\"TONE_SCORE\": int (0-100)\n\"COMPLETENESS\": int (0-100)\n\"REPLY_NOTES\": \"string (brief explanation of the scores)\"\nMake sure the response is strict JSON.";
            $customInstruction = empty($customInstruction)
                ? $scorerInstruction
                : $customInstruction . "\n\n" . $scorerInstruction;
        }

        // Summarizer injection: replace full message history with condensed summary if toggle is checked
        $summarizerEnabled = !empty($this->settings['summarizer_enabled']);
        $summaryUsed = false;
        if ($useSummaryToggle) {
            $existingSummary = $this->getSummary();
            if ($existingSummary) {
                $context['messages'] = [[
                    'admin'   => false,
                    'date'    => Carbon::now()->toDateTimeString(),
                    'message' => "[AI SUMMARY — Full history condensed for efficiency]\n" . $existingSummary,
                ]];
                $summaryUsed = true;
            }
        }

        $systemPrompt = $this->buildReplySystemPrompt($includeHistoricalContext);

        // 3. Hash generation for Cache checking
        $hashData = serialize([
            $context['subject'],
            $context['messages'],
            $context['services_summary'] ?? '',
            $context['department'] ?? '',
            $context['client_name'] ?? '',
            $tone,
            $customInstruction,
            $intent,
            $callSettings['model_name'],
            $systemPrompt
        ]);
        $hashSignature = hash('sha256', $hashData);

        // 4. Check Cache
        if (!$forceRegenerate) {
            $cached = Capsule::table('tblsahdev_cache')
                ->where('ticket_id', $this->ticketId)
                ->where('hash_signature', $hashSignature)
                ->first();

            if ($cached) {
                return [
                    'status' => 'success',
                    'cached' => true,
                    'data' => json_decode($cached->ai_response, true)
                ];
            }
        }

        $ptype = $callSettings['provider_type'] ?? '';
        // Return the payload data needed for the browser to make the request
        return [
            'status'               => 'success',
            'cached'               => false,
            'hash_signature'       => $hashSignature,
            'effective_provider_id' => $stack['effective_provider_id'],
            'providers'            => TaskProviderResolver::listActiveProvidersForRouting(),
            'provider'             => $ptype === 'lmstudio' ? 'lmstudio' : ($ptype === 'replicate' ? 'replicate' : 'google'),
            'api_url'              => $callSettings['api_url'] ?? '',
            'model'                => $callSettings['model_name'],
            'temperature'          => (float) $this->settings['temperature'],
            'max_tokens'           => (int) $this->settings['max_tokens'],
            'system_prompt'        => $systemPrompt,
            'user_prompt_template' => $this->settings['user_prompt_template'] ?? null,
            'context'              => $context,
            'tone'                 => $tone,
            'custom_instruction'   => $customInstruction,
            'intent'               => $intent,
            'has_fallback'         => $stack['fallback'] !== null,
            'summary_used'         => $summaryUsed,
            'summary_available'    => $this->getSummary() !== null,
            'message_count'        => count($context['messages'] ?? []),
            'summarizer_threshold' => (int) ($this->settings['summarizer_threshold'] ?? 15),
        ];
    }

    public function getOpenContextPayload(int $userId, string $tone = null, string $customInstruction = null, bool $forceRegenerate = false, string $intent = 'AUTO', string $technicalContext = '', ?int $overrideProviderId = null, bool $includeToolsContext = false, bool $includeAdminNotes = false): array
    {
        $this->checkRateLimit();

        $stack        = $this->getProviderStackForTask(TaskProviderResolver::TASK_TICKET_REPLY, $overrideProviderId);
        $callSettings = $stack['call_settings'];

        $scrubPII = !empty($this->settings['compliance_mode']) || !empty($this->settings['pii_scrub_enabled']);
        $extractor = new TicketDataExtractor($this->ticketId, $this->adminId);
        $context = $extractor->getOpenContextForUser($userId, $scrubPII, $includeAdminNotes);

        if (!$tone) {
            $tone = $this->settings['tone_default'];
        }

        $customInstruction = $this->composeInstructionForIntent($intent, $customInstruction, $technicalContext);
        if ($includeToolsContext && $this->ticketId > 0) {
            $toolContext = \Sahdev\Modules\ToolsExecution\ToolsExecutionService::buildPromptContextBlock($this->ticketId);
            if ($toolContext !== '') {
                $customInstruction = empty($customInstruction) ? $toolContext : $customInstruction . "\n\n" . $toolContext;
            }
        }
        if (!empty($this->settings['quality_scorer_enabled'])) {
            $scorerInstruction = "=== QUALITY SCORER REQUIREMENT ===\nYou MUST evaluate the overall quality of the CLIENT_REPLY and output the precise fields in your JSON root:\n\"SCORE\": int (0-100 overall rating)\n\"CLARITY\": int (0-100)\n\"TONE_SCORE\": int (0-100)\n\"COMPLETENESS\": int (0-100)\n\"REPLY_NOTES\": \"string (brief explanation of the scores)\"\nMake sure the response is strict JSON.";
            $customInstruction = empty($customInstruction) ? $scorerInstruction : $customInstruction . "\n\n" . $scorerInstruction;
        }

        $systemPrompt = $this->settings['system_prompt'];
        $hashData = serialize([
            'open_context',
            $userId,
            $context['subject'] ?? '',
            $context['messages'],
            $tone,
            $customInstruction,
            $intent,
            $callSettings['model_name'],
            $systemPrompt
        ]);
        $hashSignature = hash('sha256', $hashData);

        return [
            'status'               => 'success',
            'cached'               => false,
            'hash_signature'       => $hashSignature,
            'effective_provider_id' => $stack['effective_provider_id'],
            'providers'            => TaskProviderResolver::listActiveProvidersForRouting(),
            'provider'             => ($callSettings['provider_type'] ?? '') === 'lmstudio' ? 'lmstudio' : ((($callSettings['provider_type'] ?? '') === 'replicate') ? 'replicate' : 'google'),
            'api_url'              => $callSettings['api_url'] ?? '',
            'model'                => $callSettings['model_name'],
            'temperature'          => (float) $this->settings['temperature'],
            'max_tokens'           => (int) $this->settings['max_tokens'],
            'system_prompt'        => $systemPrompt,
            'user_prompt_template' => $this->settings['user_prompt_template'] ?? null,
            'context'              => $context,
            'tone'                 => $tone,
            'custom_instruction'   => $customInstruction,
            'intent'               => $intent,
            'has_fallback'         => $stack['fallback'] !== null,
            'summary_used'         => false,
            'summary_available'    => false,
            'message_count'        => count($context['messages'] ?? []),
            'summarizer_threshold' => (int) ($this->settings['summarizer_threshold'] ?? 15),
        ];
    }

    public function getOpenContextAnalysis(int $userId, string $tone = null, string $customInstruction = null, bool $forceRegenerate = false, bool $forceFallback = false, string $intent = 'AUTO', string $technicalContext = '', ?int $overrideProviderId = null, bool $includeToolsContext = false, bool $includeAdminNotes = false): array
    {
        $this->checkRateLimit();

        $scrubPII = !empty($this->settings['compliance_mode']) || !empty($this->settings['pii_scrub_enabled']);
        $extractor = new TicketDataExtractor($this->ticketId, $this->adminId);
        $context = $extractor->getOpenContextForUser($userId, $scrubPII, $includeAdminNotes);

        if (!$tone) {
            $tone = $this->settings['tone_default'];
        }

        $customInstruction = $this->composeInstructionForIntent($intent, $customInstruction, $technicalContext);
        if ($includeToolsContext && $this->ticketId > 0) {
            $toolContext = \Sahdev\Modules\ToolsExecution\ToolsExecutionService::buildPromptContextBlock($this->ticketId);
            if ($toolContext !== '') {
                $customInstruction = empty($customInstruction) ? $toolContext : $customInstruction . "\n\n" . $toolContext;
            }
        }
        if (!empty($this->settings['quality_scorer_enabled'])) {
            $scorerInstruction = "=== QUALITY SCORER REQUIREMENT ===\nYou MUST evaluate the overall quality of the CLIENT_REPLY and output the precise fields in your JSON root:\n\"SCORE\": int (0-100 overall rating)\n\"CLARITY\": int (0-100)\n\"TONE_SCORE\": int (0-100)\n\"COMPLETENESS\": int (0-100)\n\"REPLY_NOTES\": \"string (brief explanation of the scores)\"\nMake sure the response is strict JSON.";
            $customInstruction = empty($customInstruction) ? $scorerInstruction : $customInstruction . "\n\n" . $scorerInstruction;
        }

        $stack = $this->getProviderStackForTask(TaskProviderResolver::TASK_TICKET_REPLY, $overrideProviderId);
        $callSettings = $stack['call_settings'];
        $startTime = microtime(true);

        if ($forceFallback && $stack['fallback']) {
            $fbSettings     = $stack['fallback_call_settings'] ?? $this->mergeSettingsForProviderRow($this->settings, $stack['fallback_row']);
            $response       = $stack['fallback']->generateResponse($context, $fbSettings, $tone, $customInstruction);
            $activeProvider = $stack['fallback'];
        } else {
            try {
                $response = $stack['primary']->generateResponse($context, $callSettings, $tone, $customInstruction);
                $activeProvider = $stack['primary'];
            } catch (\Exception $e) {
                if ($stack['fallback'] && $stack['fallback_row']) {
                    $fbSettings    = $stack['fallback_call_settings'] ?? $this->mergeSettingsForProviderRow($this->settings, $stack['fallback_row']);
                    $response      = $stack['fallback']->generateResponse($context, $fbSettings, $tone, $customInstruction);
                    $activeProvider = $stack['fallback'];
                } else {
                    throw $e;
                }
            }
        }

        $executionTimeMs = round((microtime(true) - $startTime) * 1000);
        $tokenUsage = $activeProvider->getLastTokenUsage();
        $tokenDetails = $activeProvider->getLastTokenDetails();

        $providerName = $activeProvider instanceof AIProviderInterface ? $activeProvider->getName() : 'Unknown';
        $fullPrompt = json_encode([
            'system' => $this->settings['system_prompt'],
            'tone' => $tone,
            'instruction' => $customInstruction,
            'context' => $context,
            'mode' => 'open_context',
            'user_id' => $userId,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $this->logAuditEntry('analysis_open_context', $fullPrompt, json_encode($response), $tokenUsage, $executionTimeMs, $providerName);
        $this->incrementRateLimit();

        return [
            'status' => 'success',
            'data' => $response,
            'cached' => false,
            'execution_time_ms' => $executionTimeMs,
            'tokens_used' => $tokenUsage,
            'tokens_details' => $tokenDetails
        ];
    }

    private function composeInstructionForIntent(string $intent, ?string $customInstruction, ?string $technicalContext = ''): string
    {
        $result = (string) ($customInstruction ?? '');
        $intentDirective = $this->buildIntentDirective($intent);
        if (!empty($intentDirective)) {
            $result = empty($result) ? $intentDirective : $intentDirective . "\n\n" . $result;
        }
        if (!empty($technicalContext)) {
            $techInstruction = "=== TECHNICAL CONTEXT PROVIDED BY ADMIN ===\n" . $technicalContext;
            $result = empty($result) ? $techInstruction : $result . "\n\n" . $techInstruction;
        }
        return $result;
    }

    public function saveResponse(string $hashSignature, array $response, int $tokenUsage, int $executionTimeMs, array $tokenDetails = []): array
    {
        // Cache the successful result
        Capsule::table('tblsahdev_cache')->insert([
            'ticket_id' => $this->ticketId,
            'hash_signature' => $hashSignature,
            'ai_response' => json_encode($response),
            'created_at' => Carbon::now()
        ]);

        // Process Quality Score if enabled
        if (!empty($this->settings['quality_scorer_enabled']) && isset($response['SCORE'])) {
            Capsule::table('tblsahdev_quality_scores')->insert([
                'ticket_id' => $this->ticketId,
                'admin_id' => $this->adminId,
                'score' => (int)($response['SCORE'] ?? 0),
                'clarity' => (int)($response['CLARITY'] ?? 0),
                'tone_score' => (int)($response['TONE_SCORE'] ?? 0),
                'completeness' => (int)($response['COMPLETENESS'] ?? 0),
                'notes' => substr($response['REPLY_NOTES'] ?? '', 0, 500),
                'is_ai_generated' => 1,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ]);
        }

        // Log Audit Trail for frontend-generated responses
        // We reconstruct the prompt vaguely since we don't have the exact frontend string, but close enough.
        $extractor = new TicketDataExtractor($this->ticketId, $this->adminId);
        $context = $extractor->getContext();
        $this->logAuditEntry('frontend_analysis', "Frontend Generated. Context Hash: " . $hashSignature, json_encode($response), $tokenUsage, $executionTimeMs, 'frontend_client');

        // Minimal context for logging
        $extractor = new TicketDataExtractor($this->ticketId);
        $context = $extractor->getContext();

        // Log the Request
        $this->logRequest($context, $response, $tokenUsage, $executionTimeMs);

        // Update Rate Limit Counter
        $this->incrementRateLimit();

        return [
            'status' => 'success'
        ];
    }

    private function logRequest(array $requestPayload, array $responsePayload = null, int $tokenUsage, int $executionTimeMs, string $error = null, array $tokenDetails = [])
    {
        // Avoid inserting full conversation history if it's massive, just essential params
        $strippedRequest = [
            'subject' => $requestPayload['subject'] ?? '',
            'message_count' => count($requestPayload['messages'] ?? []),
        ];

        $payload = ['request' => $strippedRequest];
        if ($error) {
            $payload['error'] = $error;
        }

        Capsule::table('tblsahdev_logs')->insert([
            'ticket_id' => $this->ticketId,
            'admin_id' => $this->adminId,
            'request_payload' => json_encode($payload),
            'response_payload' => $responsePayload ? json_encode($responsePayload) : null,
            'token_usage' => $tokenUsage,
            'execution_time_ms' => $executionTimeMs,
            'created_at' => Carbon::now()
        ]);
    }

    /**
     * Generate an AI-powered summary of the full ticket conversation.
     * Saves result to tblsahdev_summaries. If a summary already exists, it is replaced.
     */
    public function generateSummary(): array
    {
        $stack = $this->getProviderStackForTask(TaskProviderResolver::TASK_SUMMARIZER, null);

        $scrubPII = !empty($this->settings['compliance_mode']) || !empty($this->settings['pii_scrub_enabled']);
        $extractor = new TicketDataExtractor($this->ticketId, $this->adminId);
        $context   = $extractor->getContext($scrubPII);
        $messages  = $context['messages'] ?? [];

        if (empty($messages)) {
            return ['status' => 'error', 'message' => 'No messages found in this ticket to summarize.'];
        }

        $msgCount = count($messages);

        // Build raw conversation text (oldest → newest, capped at 15k chars to fit context)
        $conversationText = '';
        $budget = 15000;
        $used = 0;
        foreach ($messages as $msg) {
            $role  = $msg['admin'] ? 'ADMIN' : 'CLIENT';
            $entry = "[{$role}] ({$msg['date']}):\n" . strip_tags($msg['message'] ?? '') . "\n\n";
            if ($used + strlen($entry) > $budget) break;
            $conversationText .= $entry;
            $used += strlen($entry);
        }

        $systemPrompt = $this->promptTemplates['summarizer'] ?? "You are a senior technical support analyst. Your job is to create concise, accurate ticket summaries that capture the essential context: root issue, actions taken, client sentiment, and current status.\n\nSUMMARY RULES:\n- Length: adapt dynamically based on ticket complexity\n- Include: the original problem, key technical details exchanged, any steps already tried, current status\n- Do NOT include greetings, small talk, or formatting metadata\n- Write in past-tense, third-person, concise prose\n- Output ONLY the summary text. No labels, no JSON, no prefixes.";

        $promptText  = "=== TICKET TO SUMMARIZE ===\n";
        $promptText .= "Subject: " . ($context['subject'] ?? 'Support Ticket') . "\n";
        $promptText .= "Client: " . ($context['client_name'] ?? 'Client') . "\n";
        $promptText .= "Total Messages: {$msgCount}\n\n";
        $promptText .= "=== CONVERSATION ===\n" . $conversationText;
        $promptText .= "=== WRITE SUMMARY BELOW ===\n";

        $fakeContext = [
            'subject'          => $context['subject'] ?? '',
            'client_name'      => $context['client_name'] ?? '',
            'department'       => '',
            'services_summary' => '',
            'attachments_text' => $context['attachments_text'] ?? '',
            'attachments_images' => $context['attachments_images'] ?? [],
            'messages'         => [['admin' => false, 'date' => '', 'message' => $promptText]],
        ];
        $fakeSettings = $stack['call_settings'];
        $fakeSettings['user_prompt_template'] = '{{MESSAGES}}
{{ATTACHMENTS_BLOCK}}';
        $fakeSettings['system_prompt']        = $systemPrompt;
        $fakeSettings['max_tokens']           = 1024; // summaries are short but might need room for file analysis

        $startTime = microtime(true);
        $activeProvider = $stack['primary'];
        
        try {
            $rawResponse = $stack['primary']->generateResponse($fakeContext, $fakeSettings, 'Professional', '');
        } catch (\Exception $e) {
            if ($stack['fallback'] && $stack['fallback_row']) {
                try {
                    $fbSettings = $stack['fallback_call_settings'] ?? $this->mergeSettingsForProviderRow($this->settings, $stack['fallback_row']);
                    $fbSettings['user_prompt_template'] = $fakeSettings['user_prompt_template'];
                    $fbSettings['system_prompt']        = $systemPrompt;
                    $fbSettings['max_tokens']           = 1024;
                    $rawResponse = $stack['fallback']->generateResponse($fakeContext, $fbSettings, 'Professional', '');
                    $activeProvider = $stack['fallback'];
                } catch (\Exception $fe) {
                    return ['status' => 'error', 'message' => 'Summary generation failed. ' . $fe->getMessage()];
                }
            } else {
                return ['status' => 'error', 'message' => 'Summary generation failed. ' . $e->getMessage()];
            }
        }

        $execMs = round((microtime(true) - $startTime) * 1000);

        // Extract plain text from response
        if (is_array($rawResponse) && isset($rawResponse['__raw_text__'])) {
            $summaryText = $rawResponse['__raw_text__'];
        } elseif (is_array($rawResponse) && isset($rawResponse['CLIENT_REPLY'])) {
            $summaryText = $rawResponse['CLIENT_REPLY'];
        } elseif (is_string($rawResponse)) {
            $summaryText = $rawResponse;
        } else {
            $summaryText = implode("\n", array_filter(array_values($rawResponse), 'is_string'));
        }
        $summaryText = trim($summaryText);

        if (empty($summaryText)) {
            return ['status' => 'error', 'message' => 'AI returned an empty summary.'];
        }

        $providerName = $activeProvider instanceof AIProviderInterface ? $activeProvider->getName() : 'Unknown';
        $this->logAuditEntry('summary', $promptText, $summaryText, $activeProvider->getLastTokenUsage() ?? 0, $execMs, $providerName);

        // Upsert: delete any existing summary for this ticket, then insert fresh
        Capsule::table('tblsahdev_summaries')->where('ticket_id', $this->ticketId)->delete();
        Capsule::table('tblsahdev_summaries')->insert([
            'ticket_id'  => $this->ticketId,
            'admin_id'   => $this->adminId,
            'summary'    => $summaryText,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return [
            'status'           => 'success',
            'summary'          => $summaryText,
            'message_count'    => $msgCount,
            'execution_time_ms'=> $execMs,
        ];
    }

    /**
     * Retrieve the latest saved summary for this ticket, or null if none exists.
     */
    public function getSummary(): ?string
    {
        $row = Capsule::table('tblsahdev_summaries')
            ->where('ticket_id', $this->ticketId)
            ->orderBy('id', 'desc')
            ->first();
        return $row ? $row->summary : null;
    }

    /**
     * Delete the saved summary for this ticket.
     */
    public function deleteSummary(): array
    {
        $deleted = Capsule::table('tblsahdev_summaries')->where('ticket_id', $this->ticketId)->delete();
        return ['status' => 'success', 'deleted' => (bool) $deleted];
    }

    /**
     * Generate an AI Memory of the client's past tickets and cache it.
     */
    public function generateHistoricalContext(int $limit = 7): array
    {
        $stack = $this->getProviderStackForTask(TaskProviderResolver::TASK_HISTORICAL_CONTEXT, null);

        // 1. Fetch current ticket details
        $currentTicket = Capsule::table('tbltickets')->where('id', $this->ticketId)->first();
        if (!$currentTicket || empty($currentTicket->userid)) {
            return ['status' => 'error', 'message' => 'Cannot generate context for a guest or missing ticket.'];
        }
        
        $clientId = $currentTicket->userid;

        // 2. Fetch past tickets for this client, excluding current ticket
        $pastTickets = Capsule::table('tbltickets')
            ->where('userid', $clientId)
            ->where('id', '!=', $this->ticketId)
            ->orderBy('date', 'desc')
            ->limit($limit)
            ->get();

        if ($pastTickets->isEmpty()) {
            return ['status' => 'error', 'message' => 'No previous tickets found for this client.'];
        }

        // 3. Build the prompt text for the AI
        $contextText = "=== ACTIVE TICKET ISSUE ===\nSubject: {$currentTicket->title}\n\n";
        $contextText .= "=== PAST PAST CONVERSATIONS (Last {$pastTickets->count()} tickets) ===\n";

        foreach ($pastTickets as $pt) {
            $firstMessage = Capsule::table('tblticketreplies')
                ->where('tid', $pt->id)
                ->orderBy('date', 'asc')
                ->value('message') ?? (($pt->message) ? $pt->message : 'No message body recorded.');
            
            $cleanMsg = strip_tags($firstMessage);
            $cleanMsg = substr($cleanMsg, 0, 500) . (strlen($cleanMsg) > 500 ? '...' : '');

            $contextText .= "- Ticket #{$pt->id} [{$pt->date}] Status: {$pt->status}\n  Subject: {$pt->title}\n  Message: {$cleanMsg}\n\n";
        }

        $systemPrompt = $this->promptTemplates['historical_context'] ?? "You are a customer support historian. Analyze the user's past tickets against their current active issue.\nYOUR TASK:\n1. explicitly highlight and summarize any past tickets that are related or similar to the current issue.\n2. briefly group and summarize unrelated tickets just to provide general context on their account health.\nFormat your response purely in Markdown. Do not include JSON. Be concise but helpful for the support agent.";

        $fakeContext = [
            'subject'          => $currentTicket->title,
            'client_name'      => '',
            'department'       => '',
            'services_summary' => '',
            'attachments_text' => '',
            'messages'         => [['admin' => false, 'date' => '', 'message' => $contextText]],
        ];

        $fakeSettings = $stack['call_settings'];
        $fakeSettings['user_prompt_template'] = '{{MESSAGES}}';
        $fakeSettings['system_prompt']        = $systemPrompt;

        $startTime = microtime(true);
        $activeProvider = $stack['primary'];
        
        try {
            $rawResponse = $stack['primary']->generateResponse($fakeContext, $fakeSettings, 'Professional', '');
        } catch (\Exception $e) {
            if ($stack['fallback'] && $stack['fallback_row']) {
                try {
                    $fbSettings = $stack['fallback_call_settings'] ?? $this->mergeSettingsForProviderRow($this->settings, $stack['fallback_row']);
                    $fbSettings['user_prompt_template'] = '{{MESSAGES}}';
                    $fbSettings['system_prompt']        = $systemPrompt;
                    $rawResponse = $stack['fallback']->generateResponse($fakeContext, $fbSettings, 'Professional', '');
                    $activeProvider = $stack['fallback'];
                } catch (\Exception $fe) {
                    return ['status' => 'error', 'message' => 'Context generation failed. ' . $fe->getMessage()];
                }
            } else {
                return ['status' => 'error', 'message' => 'Context generation failed. ' . $e->getMessage()];
            }
        }

        $execMs = round((microtime(true) - $startTime) * 1000);

        // Extract plain text from response
        if (is_array($rawResponse) && isset($rawResponse['__raw_text__'])) {
            $summaryText = $rawResponse['__raw_text__'];
        } elseif (is_array($rawResponse) && isset($rawResponse['CLIENT_REPLY'])) {
            $summaryText = $rawResponse['CLIENT_REPLY'];
        } elseif (is_string($rawResponse)) {
            $summaryText = $rawResponse;
        } else {
            $summaryText = implode("\n", array_filter(array_values($rawResponse), 'is_string'));
        }
        $summaryText = trim($summaryText);

        if (empty($summaryText)) {
            return ['status' => 'error', 'message' => 'AI returned an empty context summary.'];
        }

        $providerName = $activeProvider instanceof AIProviderInterface ? $activeProvider->getName() : 'Unknown';
        $this->logAuditEntry('historical_context', $contextText, $summaryText, $activeProvider->getLastTokenUsage() ?? 0, $execMs, $providerName);

        // Upsert
        Capsule::table('tblsahdev_client_context_cache')->where('ticket_id', $this->ticketId)->delete();
        Capsule::table('tblsahdev_client_context_cache')->insert([
            'ticket_id'          => $this->ticketId,
            'client_id'          => $clientId,
            'historical_context' => $summaryText,
            'created_at'         => Carbon::now(),
            'updated_at'         => Carbon::now(),
        ]);

        return [
            'status'             => 'success',
            'historical_context' => $summaryText,
            'tickets_analyzed'   => $pastTickets->count(),
            'execution_time_ms'  => $execMs,
        ];
    }

    /**
     * Retrieve the cached historical context.
     */
    public function getHistoricalContext(): ?string
    {
        $row = Capsule::table('tblsahdev_client_context_cache')
            ->where('ticket_id', $this->ticketId)
            ->first();
        return $row ? $row->historical_context : null;
    }

    /**
     * Delete the historical context (to force regeneration).
     */
    public function deleteHistoricalContext(): array
    {
        $deleted = Capsule::table('tblsahdev_client_context_cache')->where('ticket_id', $this->ticketId)->delete();
        return ['status' => 'success', 'deleted' => (bool) $deleted];
    }


    /**
     * Returns provider info + the constructed rewrite prompt so the browser
     * can call LM Studio directly (same pattern as getPayload() for analysis).
     * For Google providers the browser should fall back to server-side rewrite_reply.
     */
    public function getRewritePayload(string $draftText, string $tone = 'Professional', string $instruction = ''): array
    {
        if (empty(trim($draftText))) {
            return ['status' => 'error', 'message' => 'Draft text is empty. Please write a draft in the editor first.'];
        }

        $stack        = $this->getProviderStackForTask(TaskProviderResolver::TASK_REWRITE_REPLY, null);
        $callSettings = $stack['call_settings'];

        $extractor = new TicketDataExtractor($this->ticketId, $this->adminId);
        $context = $extractor->getContext();

        $systemPrompt = $this->settings['system_prompt'] ?? "You are a professional technical support specialist.";

        // Append knowledgebase rules to system prompt (same as getPayload)
        $kbPath = dirname(__DIR__) . '/knowledgebase';
        if (is_dir($kbPath)) {
            $kbRules = "";
            $dir = new \DirectoryIterator($kbPath);
            foreach ($dir as $fileinfo) {
                if (!$fileinfo->isDot() && $fileinfo->getExtension() === 'txt') {
                    $content = @file_get_contents($fileinfo->getPathname());
                    if ($content)
                        $kbRules .= "\n--- Rule: {$fileinfo->getFilename()} ---\n" . trim($content) . "\n";
                }
            }
            if (!empty($kbRules)) {
                $systemPrompt .= "\n\n=== RULES & KNOWLEDGEBASE ===\n" . $kbRules;
            }
        }

        $extraInstruction = !empty(trim($instruction)) ? "\n\nPriority admin instruction: " . trim($instruction) : '';

        // Build rewrite prompt from DB template or fallback
        $rewriteTemplate = $this->promptTemplates['rewrite_reply'] ?? '';
        if (!empty(trim($rewriteTemplate)) && strpos($rewriteTemplate, '{{DRAFT}}') !== false) {
            $rewritePrompt = str_replace(
                ['{{TONE}}', '{{SUBJECT}}', '{{CLIENT_NAME}}', '{{EXTRA_INSTRUCTION}}', '{{DRAFT}}'],
                [$tone, $context['subject'] ?? 'Support Ticket', $context['client_name'] ?? 'Client', $extraInstruction, trim($draftText)],
                $rewriteTemplate
            );
        } else {
            $rewritePrompt  = "=== TASK ===\n";
            $rewritePrompt .= "The admin has written a short rough draft reply for the following support ticket. EXPAND and POLISH it into a complete, fluent, professional client-facing reply.\n\n";
            $rewritePrompt .= "RULES:\n";
            $rewritePrompt .= "- Preserve the original intent and any specific instructions in the draft.\n";
            $rewritePrompt .= "- Do NOT add a greeting (e.g. 'Dear Client') or a sign-off — the signature is handled separately.\n";
            $rewritePrompt .= "- Write in a **{$tone}** tone.\n";
            $rewritePrompt .= "- Output ONLY the final reply body. No extra commentary, no JSON, no prefixes.\n";
            $rewritePrompt .= $extraInstruction . "\n\n";
            $rewritePrompt .= "=== TICKET CONTEXT ===\n";
            $rewritePrompt .= "Subject: " . ($context['subject'] ?? 'Support Ticket') . "\n";
            $rewritePrompt .= "Client: " . ($context['client_name'] ?? 'Client') . "\n\n";
            $rewritePrompt .= "=== ADMIN DRAFT ===\n";
            $rewritePrompt .= trim($draftText) . "\n\n";
            $rewritePrompt .= "=== POLISHED REPLY (output only) ===\n";
        }

        $rwPtype = $callSettings['provider_type'] ?? '';
        return [
            'status' => 'success',
            'effective_provider_id' => $stack['effective_provider_id'],
            'provider' => $rwPtype === 'lmstudio' ? 'lmstudio' : ($rwPtype === 'replicate' ? 'replicate' : 'google'),
            'api_url' => $callSettings['api_url'] ?? '',
            'model' => $callSettings['model_name'],
            'temperature' => (float) $this->settings['temperature'],
            'max_tokens' => (int) $this->settings['max_tokens'],
            'system_prompt' => $systemPrompt,
            'rewrite_prompt' => $rewritePrompt,
            'has_fallback' => $stack['fallback'] !== null,
        ];
    }

    /**
     * Rewrites / expands a short admin draft into a complete, polished client reply.
     * Used server-side only for non-LM Studio (Google) providers.
     */

    public function rewriteReply(string $draftText, string $tone = 'Professional', string $instruction = ''): array
    {
        if (empty(trim($draftText))) {
            return ['status' => 'error', 'message' => 'Draft text is empty. Please write a short draft in the editor first.'];
        }

        $stack = $this->getProviderStackForTask(TaskProviderResolver::TASK_REWRITE_REPLY, null);

        // Build a ticket context snippet for extra grounding
        $scrubPII = !empty($this->settings['compliance_mode']) || !empty($this->settings['pii_scrub_enabled']);
        $extractor = new TicketDataExtractor($this->ticketId, $this->adminId);
        $context = $extractor->getContext($scrubPII);

        $systemPrompt = $this->settings['system_prompt'] ?? "You are a professional technical support specialist.";

        $extraInstruction = !empty(trim($instruction)) ? "\n\nPriority admin instruction: " . trim($instruction) : '';

        // Build the rewrite prompt — use DB template if available (supports {{TONE}}, {{SUBJECT}}, {{CLIENT_NAME}}, {{EXTRA_INSTRUCTION}}, {{DRAFT}})
        $rewriteTemplate = $this->promptTemplates['rewrite_reply'] ?? '';
        if (!empty(trim($rewriteTemplate)) && strpos($rewriteTemplate, '{{DRAFT}}') !== false) {
            $promptText = str_replace(
                ['{{TONE}}', '{{SUBJECT}}', '{{CLIENT_NAME}}', '{{EXTRA_INSTRUCTION}}', '{{DRAFT}}'],
                [$tone, $context['subject'] ?? 'Support Ticket', $context['client_name'] ?? 'Client', $extraInstruction, trim($draftText)],
                $rewriteTemplate
            );
        } else {
            // Fallback to hardcoded template
            $promptText  = "=== TASK ===\n";
            $promptText .= "The admin has written a short rough draft reply for the following ticket. Your job is to EXPAND and POLISH it into a complete, fluent, professional client-facing reply.\n\n";
            $promptText .= "RULES:\n";
            $promptText .= "- Preserve the original intent and any specific instructions in the draft.\n";
            $promptText .= "- Do NOT add a greeting (e.g. 'Dear Client') or a sign-off — the signature is handled separately.\n";
            $promptText .= "- Write in a **{$tone}** tone.\n";
            $promptText .= "- Output ONLY the final reply body. No extra commentary, no JSON, no prefixes.\n";
            $promptText .= $extraInstruction . "\n\n";
            $promptText .= "=== TICKET CONTEXT ===\n";
            $promptText .= "Subject: " . ($context['subject'] ?? 'Support Ticket') . "\n";
            $promptText .= "Client: " . ($context['client_name'] ?? 'Client') . "\n\n";
            $promptText .= "=== ADMIN DRAFT ===\n";
            $promptText .= trim($draftText) . "\n\n";
            $promptText .= "=== POLISHED REPLY (output only) ===\n";
        }

        // Use the resolved task provider in free-text mode (no JSON schema)
        $startTime = microtime(true);
        try {
            $fakeContext = [
                'subject' => $context['subject'] ?? '',
                'client_name' => $context['client_name'] ?? '',
                'department' => '',
                'services_summary' => '',
                'attachments_text' => '',
                'messages' => [['admin' => false, 'date' => '', 'message' => $promptText]],
            ];

            $fakeSettings = $stack['call_settings'];
            $fakeSettings['user_prompt_template'] = '{{MESSAGES}}';
            $fakeSettings['system_prompt'] = $systemPrompt;

            $rawResponse = $stack['primary']->generateResponse($fakeContext, $fakeSettings, $tone, '');
            $execTimeMs = round((microtime(true) - $startTime) * 1000);

            if (is_array($rawResponse) && isset($rawResponse['CLIENT_REPLY'])) {
                $reply = $rawResponse['CLIENT_REPLY'];
            } elseif (is_array($rawResponse) && isset($rawResponse['__raw_text__'])) {
                $reply = $rawResponse['__raw_text__'];
            } elseif (is_array($rawResponse) && isset($rawResponse['reply'])) {
                $reply = $rawResponse['reply'];
            } elseif (is_string($rawResponse)) {
                $reply = $rawResponse;
            } else {
                $reply = implode("\n\n", array_filter(array_values($rawResponse), 'is_string'));
            }

            $tokensUsed = $stack['primary']->getLastTokenUsage() ?? 0;
            $providerName = $stack['primary']->getName() ?? 'Primary';
            $this->logAuditEntry('rewrite', $promptText, $reply, $tokensUsed, $execTimeMs, $providerName);

            return [
                'status' => 'success',
                'reply' => $reply,
                'execution_time_ms' => $execTimeMs,
                'tokens_used' => $tokensUsed,
            ];
        } catch (\Exception $e) {
            if ($stack['fallback'] && $stack['fallback_row']) {
                try {
                    $fakeContext = [
                        'subject' => $context['subject'] ?? '',
                        'client_name' => $context['client_name'] ?? '',
                        'department' => '',
                        'services_summary' => '',
                        'attachments_text' => '',
                        'messages' => [['admin' => false, 'date' => '', 'message' => $promptText]],
                    ];
                    $fbSettings = $stack['fallback_call_settings'] ?? $this->mergeSettingsForProviderRow($this->settings, $stack['fallback_row']);
                    $fbSettings['user_prompt_template'] = '{{MESSAGES}}';
                    $fbSettings['system_prompt'] = $systemPrompt;
                    $rawResponse = $stack['fallback']->generateResponse($fakeContext, $fbSettings, $tone, '');
                    if (is_array($rawResponse) && isset($rawResponse['CLIENT_REPLY'])) {
                        $reply = $rawResponse['CLIENT_REPLY'];
                    } elseif (is_array($rawResponse) && isset($rawResponse['__raw_text__'])) {
                        $reply = $rawResponse['__raw_text__'];
                    } elseif (is_array($rawResponse) && isset($rawResponse['reply'])) {
                        $reply = $rawResponse['reply'];
                    } elseif (is_string($rawResponse)) {
                        $reply = $rawResponse;
                    } else {
                        $reply = implode("\n\n", array_filter(array_values($rawResponse), 'is_string'));
                    }
                    
                    $execTimeMsFallback = round((microtime(true) - $startTime) * 1000);
                    $tokensUsedFallback = $stack['fallback']->getLastTokenUsage() ?? 0;
                    $providerNameFallback = $stack['fallback']->getName() ?? 'Fallback';
                    $this->logAuditEntry('rewrite_fallback', $promptText, $reply, $tokensUsedFallback, $execTimeMsFallback, $providerNameFallback);

                    return ['status' => 'success', 'reply' => $reply, 'execution_time_ms' => $execTimeMsFallback, 'tokens_used' => $tokensUsedFallback];
                } catch (\Exception $fe) {
                    throw new \Exception("Both providers failed to rewrite the reply. Last error: " . $fe->getMessage());
                }
            }
            throw $e;
        }
    }

    /**
     * Evaluates a generated or drafted reply using the AI and stores the score.
     */
    public function scoreReply(string $replyText, bool $isAiGenerated = true): array
    {
        if (empty(trim($replyText))) {
            return ['status' => 'error', 'message' => 'Reply text is empty.'];
        }

        $stack = $this->getProviderStackForTask(TaskProviderResolver::TASK_QUALITY_SCORE, null);

        $extractor = new TicketDataExtractor($this->ticketId, $this->adminId);
        $context = $extractor->getContext();

        $promptText = "=== TASK ===\n";
        $promptText .= "Evaluate the following support ticket reply based on Clarity, Tone, and Completeness.\n";
        $promptText .= "CRITICAL RULE: Output strictly a single raw JSON object. NO extra text, NO markdown headers, NO preamble.\n\n";
        $promptText .= "=== JSON SCHEMA ===\n";
        $promptText .= "{\"SCORE\": 85, \"CLARITY\": 90, \"TONE_SCORE\": 85, \"COMPLETENESS\": 80, \"REPLY_NOTES\": \"Brief feedback here\"}\n\n";
        $promptText .= "=== TICKET CONTEXT ===\n";
        $promptText .= "Subject: " . ($context['subject'] ?? '') . "\n";
        
        // Add the last client message or summary to give context
        $lastMessage = '';
        if (!empty($context['messages'])) {
            $lastIndex = count($context['messages']) - 1;
            // Find the last non-admin message or the summary
            for ($i = $lastIndex; $i >= 0; $i--) {
                if ($context['messages'][$i]['admin'] == false) {
                    $lastMessage = $context['messages'][$i]['message'];
                    break;
                }
            }
        }
        $promptText .= "Latest Client Message: " . $lastMessage . "\n\n";

        $promptText .= "=== REPLY TO EVALUATE ===\n";
        $promptText .= trim($replyText) . "\n\n";
        
        $sysPrompt = $this->promptTemplates['score_reply'] ?? "You are an expert QA Manager scoring support replies. Output strictly a single raw JSON object matching the requested schema.";

        $fakeContext = [
            'subject' => '',
            'client_name' => '',
            'department' => '',
            'services_summary' => '',
            'attachments_text' => '',
            'messages' => [['admin' => false, 'date' => '', 'message' => $promptText]],
        ];

        $parseScoreResponse = function ($rawResponse) {
            $scoreData = ['SCORE' => 0, 'CLARITY' => 0, 'TONE_SCORE' => 0, 'COMPLETENESS' => 0, 'REPLY_NOTES' => 'Parse failed'];
            if (is_array($rawResponse)) {
                $scoreData = array_merge($scoreData, $rawResponse);
            } elseif (is_string($rawResponse)) {
                $cleanStr = trim($rawResponse);
                if (preg_match('/\{[\s\S]*\}/', $cleanStr, $matches)) {
                    $jsonBlock = $matches[0];
                    $decoded = json_decode($jsonBlock, true);
                    if ($decoded && is_array($decoded)) {
                        $scoreData = array_merge($scoreData, $decoded);
                    }
                }
            }
            if (isset($scoreData['score'])) { $scoreData['SCORE'] = $scoreData['score']; }
            if (isset($scoreData['clarity'])) { $scoreData['CLARITY'] = $scoreData['clarity']; }
            if (isset($scoreData['tone_score'])) { $scoreData['TONE_SCORE'] = $scoreData['tone_score']; }
            if (isset($scoreData['completeness'])) { $scoreData['COMPLETENESS'] = $scoreData['completeness']; }
            if (isset($scoreData['notes'])) { $scoreData['REPLY_NOTES'] = $scoreData['notes']; }
            if (isset($scoreData['REPLY_NOTES'])) { $scoreData['notes'] = $scoreData['REPLY_NOTES']; }

            return $scoreData;
        };

        try {
            $startTime = microtime(true);
            $fakeSettings = $stack['call_settings'];
            $fakeSettings['user_prompt_template'] = '{{MESSAGES}}';
            $fakeSettings['system_prompt'] = $sysPrompt;

            try {
                $rawResponse = $stack['primary']->generateResponse($fakeContext, $fakeSettings, 'Professional', '');
            } catch (\Exception $pe) {
                if ($stack['fallback'] && $stack['fallback_row']) {
                    $fbSettings = $stack['fallback_call_settings'] ?? $this->mergeSettingsForProviderRow($this->settings, $stack['fallback_row']);
                    $fbSettings['user_prompt_template'] = '{{MESSAGES}}';
                    $fbSettings['system_prompt'] = $sysPrompt;
                    $rawResponse = $stack['fallback']->generateResponse($fakeContext, $fbSettings, 'Professional', '');
                } else {
                    throw $pe;
                }
            }
            $execTimeMs = round((microtime(true) - $startTime) * 1000);
            
            $scoreData = $parseScoreResponse($rawResponse);

            Capsule::table('tblsahdev_quality_scores')->insert([
                'ticket_id' => $this->ticketId,
                'admin_id' => $this->adminId,
                'score' => (int)($scoreData['SCORE'] ?? 0),
                'clarity' => (int)($scoreData['CLARITY'] ?? 0),
                'tone_score' => (int)($scoreData['TONE_SCORE'] ?? 0),
                'completeness' => (int)($scoreData['COMPLETENESS'] ?? 0),
                'notes' => substr($scoreData['REPLY_NOTES'] ?? '', 0, 500),
                'is_ai_generated' => $isAiGenerated ? 1 : 0,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ]);

            return ['status' => 'success', 'data' => $scoreData, 'execution_time_ms' => $execTimeMs];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'Scoring failed: ' . $e->getMessage()];
        }
    }

    /**
     * Returns a focused intent directive string that gets prepended to customInstruction
     * before being passed to the AI prompt. Returns empty string for AUTO intent.
     */
    private function buildIntentDirective(string $intent): string
    {
        if (empty($intent) || $intent === 'AUTO') {
            return '';
        }

        try {
            $intentRecord = Capsule::table('tblsahdev_intents')
                ->where('intent_key', strtoupper(trim($intent)))
                ->where('is_active', 1)
                ->first();

            if ($intentRecord && !empty($intentRecord->directive)) {
                $directive = (string) $intentRecord->directive;
                if (strtoupper(trim($intent)) === 'ABUSE_REPORT') {
                    $directive .= "\n\nWHITE-LABEL ENFORCEMENT: Do not expose upstream vendor/provider names, partner brands, or third-party internal identities. If source details are uncertain, rewrite neutrally as 'our infrastructure team' or 'our upstream network partner' without naming brands. Keep response human, policy-aware, and suitable for abuse report follow-up conversations.";
                }
                return $directive;
            }
        } catch (\Exception $e) {
            // Fallback gracefully if table not ready or error
        }

        return '';
    }

    /**
     * Log an AI interaction to the audit trail database table.
     */
    private function logAuditEntry(string $actionType, string $prompt, string $response, int $tokensUsed, int $execTimeMs, string $providerName)
    {
        try {
            $safePrompt = $this->redactForAudit($prompt);
            $safeResponse = $this->redactForAudit($response);
            Capsule::table('tblsahdev_audit_trail')->insert([
                'ticket_id' => $this->ticketId,
                'admin_id' => $this->adminId,
                'action_type' => $actionType,
                'prompt_text' => $safePrompt,
                'response_text' => $safeResponse,
                'provider_used' => $providerName,
                'tokens_used' => $tokensUsed,
                'execution_time_ms' => $execTimeMs,
                'created_at' => Carbon::now()
            ]);
        } catch (\Exception $e) {
            // Silently fail audit logging rather than breaking the user flow
            error_log("Sahdev Audit Log Error: " . $e->getMessage());
        }
    }

    private function redactForAudit(string $text): string
    {
        $text = preg_replace('/(?i)(authorization\\s*:\\s*bearer\\s+)[^\\s"\']+/', '$1[REDACTED]', $text);
        $text = preg_replace('/(?i)(api[_-]?key\\s*[":=]+\\s*)[^\\s,"\']+/', '$1[REDACTED]', $text);
        $text = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\.[A-Za-z]{2,}/', '[REDACTED_EMAIL]', $text);
        if (strlen($text) > 2000) {
            $text = substr($text, 0, 2000) . '...[truncated]';
        }
        return $text;
    }

    /**
     * Search for canned responses across Sahdev AI DB, WHMCS Predefined Replies, and WHMCS KB.
     */
    public function searchCannedResponses(string $query): array
    {
        $limit = 10;
        $results = [];
        $queryPattern = '%' . $query . '%';

        try {
            // 1. Sahdev AI Canned Responses
            $aiCanned = Capsule::table('tblsahdev_canned_responses')
                ->where('title', 'LIKE', $queryPattern)
                ->orWhere('template_text', 'LIKE', $queryPattern)
                ->limit($limit)
                ->get();
            
            foreach ($aiCanned as $item) {
                $results[] = [
                    'source' => 'sahdev',
                    'id' => $item->id,
                    'title' => $item->title,
                    'content' => $item->template_text
                ];
            }

            // 2. WHMCS Predefined Replies
            if (Capsule::schema()->hasTable('tblticketpredefinedreplies')) {
                $whmcsPredef = Capsule::table('tblticketpredefinedreplies')
                    ->where('name', 'LIKE', $queryPattern)
                    ->orWhere('reply', 'LIKE', $queryPattern)
                    ->limit($limit)
                    ->get();

                foreach ($whmcsPredef as $item) {
                    $results[] = [
                        'source' => 'whmcs_predef',
                        'id' => $item->id,
                        'title' => $item->name,
                        'content' => $item->reply
                    ];
                }
            }

            // 3. WHMCS Knowledgebase (Articles)
            if (Capsule::schema()->hasTable('tblknowledgebase')) {
                $whmcsKb = Capsule::table('tblknowledgebase')
                    ->where('title', 'LIKE', $queryPattern)
                    ->orWhere('article', 'LIKE', $queryPattern)
                    ->limit($limit)
                    ->get();

                foreach ($whmcsKb as $item) {
                    $results[] = [
                        'source' => 'whmcs_kb',
                        'id' => $item->id,
                        'title' => $item->title,
                        'content' => $item->article
                    ];
                }
            }

            return ['status' => 'success', 'results' => $results];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'Search failed: ' . $e->getMessage()];
        }
    }

    /**
     * Rewrite a specific draft reply into a generalized template using AI.
     */
    public function generateCannedTemplate(string $draftText): array
    {
        if (empty(trim($draftText))) {
            return ['status' => 'error', 'message' => 'Draft text is empty.'];
        }

        $stack = $this->getProviderStackForTask(TaskProviderResolver::TASK_CANNED_TEMPLATE, null);

        $cannedTemplate = $this->promptTemplates['canned_template'] ?? '';
        if (!empty(trim($cannedTemplate))) {
            if (strpos($cannedTemplate, '{{DRAFT}}') !== false) {
                // Template has a {{DRAFT}} placeholder — replace it
                $promptText = str_replace('{{DRAFT}}', trim($draftText), $cannedTemplate);
            } else {
                // No placeholder — append the draft at the end
                $promptText = rtrim($cannedTemplate) . "\n\n" . trim($draftText);
            }
        } else {
            $promptText  = "Rewrite the following support ticket reply into a reusable, generalized canned response template.\n";
            $promptText .= "- Remove any specific client names, domain names, IP addresses, or highly specific dates.\n";
            $promptText .= "- Replace removed specifics with general placeholders like [Client Name], [Domain], [IP Address].\n";
            $promptText .= "- Make the tone professional and helpful.\n";
            $promptText .= "- DO NOT include any JSON wrapping or preamble, just the raw text template.\n\n";
            $promptText .= "=== DRAFT TO GENERALIZE ===\n";
            $promptText .= trim($draftText);
        }

        $fakeSettingsBase = $stack['call_settings'];
        $fakeSettingsBase['user_prompt_template'] = '{{MESSAGES}}'; 
        $fakeSettingsBase['system_prompt'] = "You are an expert technical writer creating generalized canned response templates.";

        $fakeContext = [
            'subject' => '',
            'client_name' => '',
            'department' => '',
            'services_summary' => '',
            'attachments_text' => '',
            'messages' => [['admin' => false, 'date' => '', 'message' => $promptText]],
        ];

        $extractTemplate = function ($rawResponse) {
            if (is_string($rawResponse)) {
                return trim($rawResponse);
            }
            if (is_array($rawResponse) && isset($rawResponse['reply'])) {
                return trim($rawResponse['reply']);
            }
            if (is_array($rawResponse)) {
                return implode("\n\n", array_filter(array_values($rawResponse), 'is_string'));
            }

            return '';
        };

        try {
            $startTime = microtime(true);
            $fakeSettings = $fakeSettingsBase;
            $rawResponse = $stack['primary']->generateResponse($fakeContext, $fakeSettings, 'Professional', '');
            $execTimeMs = round((microtime(true) - $startTime) * 1000);
            $tokensUsed = $stack['primary']->getLastTokenUsage() ?? 0;
            $providerName = $stack['primary']->getName() ?? 'Unknown';

            $template = $extractTemplate($rawResponse);

            if (empty($template) && $stack['fallback'] && $stack['fallback_row']) {
                $fbSettings = $stack['fallback_call_settings'] ?? $this->mergeSettingsForProviderRow($this->settings, $stack['fallback_row']);
                $fbSettings['user_prompt_template'] = '{{MESSAGES}}';
                $fbSettings['system_prompt'] = $fakeSettingsBase['system_prompt'];
                $rawResponseFallback = $stack['fallback']->generateResponse($fakeContext, $fbSettings, 'Professional', '');
                $template = $extractTemplate($rawResponseFallback);
                $tokensUsed = $stack['fallback']->getLastTokenUsage() ?? 0;
                $providerName = $stack['fallback']->getName() ?? 'Fallback';
            }

            if (empty($template)) {
                 return ['status' => 'error', 'message' => 'AI returned an empty template.'];
            }

            $this->logAuditEntry('generate_canned_template', $promptText, $template, $tokensUsed, $execTimeMs, $providerName);

            return ['status' => 'success', 'template' => $template, 'execution_time_ms' => $execTimeMs, 'tokens_used' => $tokensUsed];
        } catch (\Exception $e) {
            if ($stack['fallback'] && $stack['fallback_row']) {
                try {
                    $startTimeFb = microtime(true);
                    $fbSettings = $stack['fallback_call_settings'] ?? $this->mergeSettingsForProviderRow($this->settings, $stack['fallback_row']);
                    $fbSettings['user_prompt_template'] = '{{MESSAGES}}';
                    $fbSettings['system_prompt'] = $fakeSettingsBase['system_prompt'];
                    $rawResponseFallback = $stack['fallback']->generateResponse($fakeContext, $fbSettings, 'Professional', '');
                    $execTimeMsFb = round((microtime(true) - $startTimeFb) * 1000);
                    $tokensUsedFb = $stack['fallback']->getLastTokenUsage() ?? 0;
                    $providerNameFb = $stack['fallback']->getName() ?? 'Fallback';

                    $template = $extractTemplate($rawResponseFallback);

                    if (empty($template)) {
                       throw new \Exception("Fallback AI returned an empty template.");
                    }

                    $this->logAuditEntry('generate_canned_template_fallback', $promptText, $template, $tokensUsedFb, $execTimeMsFb, $providerNameFb);

                    return ['status' => 'success', 'template' => $template, 'execution_time_ms' => $execTimeMsFb, 'tokens_used' => $tokensUsedFb];
                } catch (\Exception $fe) {
                     return ['status' => 'error', 'message' => 'Primary and Fallback AI failed: ' . $fe->getMessage()];
                }
            }
            return ['status' => 'error', 'message' => 'AI generation failed: ' . $e->getMessage()];
        }
    }

    /**
     * Save a generated template to the database.
     */
    public function saveCannedResponse(string $title, string $templateText): array
    {
        if (empty(trim($title)) || empty(trim($templateText))) {
            return ['status' => 'error', 'message' => 'Title and Template Text are required.'];
        }

        try {
            $id = Capsule::table('tblsahdev_canned_responses')->insertGetId([
                'admin_id' => $this->adminId,
                'title' => trim($title),
                'template_text' => trim($templateText),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ]);

            return ['status' => 'success', 'message' => 'Canned response saved successfully.', 'id' => $id];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'Failed to save canned response: ' . $e->getMessage()];
        }
    }

    /**
     * Store a generated article as a Sahdev canned response (KB draft).
     *
     * WHMCS core tables (e.g. tblknowledgebase*) are never written — read-only
     * policy for stock WHMCS schema. Staff can paste into Support → Knowledgebase manually.
     */
    public function saveKbArticle(string $title, string $templateText): array
    {
        if (empty(trim($title)) || empty(trim($templateText))) {
            return ['status' => 'error', 'message' => 'Title and Article text are required.'];
        }

        $kbTitle = '[KB Draft] ' . trim($title);
        $result  = $this->saveCannedResponse($kbTitle, $templateText);
        if (($result['status'] ?? '') === 'success') {
            $result['message'] = 'Saved as a Sahdev canned response (KB draft). WHMCS knowledge base tables are not modified — paste into Support → Knowledgebase if you need it there.';
        }

        return $result;
    }



    /**
     * Retrieve global Analytics for the Sahdev AI Dashboard.
     * Feature 8: AI Performance Analytics
     */
    public function getAnalyticsData(): array
    {
        try {
            // 1. Basic usage stats from Audit Trail
            $totalActions = Capsule::table('tblsahdev_audit_trail')->count();
            $totalTokens = Capsule::table('tblsahdev_audit_trail')->sum('tokens_used');
            $avgExecTime = Capsule::table('tblsahdev_audit_trail')->avg('execution_time_ms');

            // 2. Breakdown by Action Type
            $actionBreakdownRaw = Capsule::table('tblsahdev_audit_trail')
                ->select(Capsule::raw('action_type, COUNT(*) as count'))
                ->groupBy('action_type')
                ->get();
            $actionBreakdown = [];
            foreach ($actionBreakdownRaw as $res) {
                $actionBreakdown[$res->action_type] = $res->count;
            }

            // 3. Quality Scores
            $avgScore = Capsule::table('tblsahdev_quality_scores')->avg('score') ?? 0;
            $avgClarity = Capsule::table('tblsahdev_quality_scores')->avg('clarity') ?? 0;
            $avgTone = Capsule::table('tblsahdev_quality_scores')->avg('tone_score') ?? 0;
            $avgCompleteness = Capsule::table('tblsahdev_quality_scores')->avg('completeness') ?? 0;

            // 4. Comparison: AI vs Manual Scores
            $aiScoreRaw = Capsule::table('tblsahdev_quality_scores')->where('is_ai_generated', 1)->avg('score') ?? 0;
            $manualScoreRaw = Capsule::table('tblsahdev_quality_scores')->where('is_ai_generated', 0)->avg('score') ?? 0;

            // 5. Calculate Time Saved
            // Let's assume on average, an AI rewrite/generation saves 3 minutes of admin typing time.
            $timeSavedMinutes = $totalActions * 3;
            $timeSavedHours = floor($timeSavedMinutes / 60);
            $timeSavedMins = $timeSavedMinutes % 60;
            $timeSavedString = "{$timeSavedHours}h {$timeSavedMins}m";

            // Estimated Cost based on Provider settings (blended avg if not split)
            $estimatedCost = 0.00;
            try {
                $auditRecords = Capsule::table('tblsahdev_audit_trail')
                    ->select(Capsule::raw('provider_used, SUM(tokens_used) as tokens'))
                    ->groupBy('provider_used')
                    ->get();
                    
                $providers = Capsule::table('tblsahdev_providers')->get()->keyBy('name');
                
                foreach ($auditRecords as $rec) {
                    $providerName = $rec->provider_used;
                    $tokens = $rec->tokens ?? 0;
                    
                    if ($providers->has($providerName)) {
                        $p = $providers->get($providerName);
                        // Blended average of input and output since tokens are tracked as a total sum
                        $costPer1M = (float)(($p->cost_input_1m + $p->cost_output_1m) / 2);
                    } else {
                        $costPer1M = 0.50; // default fallback if provider deleted
                    }
                    $estimatedCost += ($tokens / 1000000) * $costPer1M;
                }
            } catch (\Exception $ce) {
                // If calculation fails, fallback
                $estimatedCost = ($totalTokens / 1000000) * 0.50;
            }

            // 6. Tools normalization metrics (best-effort, non-fatal)
            $toolsNorm = [
                'total_tool_runs' => 0,
                'normalized_runs' => 0,
                'raw_fallback_runs' => 0,
                'normalization_errors' => 0,
            ];
            try {
                if (Capsule::schema()->hasTable('tblsahdev_tool_runs')) {
                    $toolsNorm['total_tool_runs'] = (int) Capsule::table('tblsahdev_tool_runs')->count();
                    $toolsNorm['normalized_runs'] = (int) Capsule::table('tblsahdev_tool_runs')->where('normalization_status', 'normalized')->count();
                    $toolsNorm['raw_fallback_runs'] = (int) Capsule::table('tblsahdev_tool_runs')->where('normalization_status', 'raw_fallback')->count();
                    $toolsNorm['normalization_errors'] = (int) Capsule::table('tblsahdev_tool_runs')->whereNotNull('normalization_error')->where('normalization_error', '!=', '')->count();
                }
            } catch (\Exception $ignored) {
            }

            return [
                'status' => 'success',
                'data' => [
                    'total_actions' => $totalActions,
                    'total_tokens' => $totalTokens,
                    'avg_exec_time_ms' => round($avgExecTime),
                    'action_breakdown' => $actionBreakdown,
                    'quality' => [
                        'overall' => round($avgScore, 1),
                        'clarity' => round($avgClarity, 1),
                        'tone' => round($avgTone, 1),
                        'completeness' => round($avgCompleteness, 1),
                        'ai_avg' => round($aiScoreRaw, 1),
                        'manual_avg' => round($manualScoreRaw, 1)
                    ],
                    'roi' => [
                        'time_saved_minutes' => $timeSavedMinutes,
                        'time_saved_string' => $timeSavedString,
                        'estimated_cost_usd' => round($estimatedCost, 4)
                    ],
                    'tools_normalization' => $toolsNorm,
                ]
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'Failed to load analytics: ' . $e->getMessage()];
        }
    }
}
