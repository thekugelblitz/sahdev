<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;
use Carbon\Carbon;

require_once __DIR__ . '/AIProviderInterface.php';
require_once __DIR__ . '/GoogleAIProvider.php';
require_once __DIR__ . '/LMStudioAIProvider.php';
require_once __DIR__ . '/ReplicateAIProvider.php';
require_once __DIR__ . '/TicketDataExtractor.php';

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

        if ($providerData->provider_type === 'google') {
            $apiKey = !empty($providerData->api_key) ? decrypt($providerData->api_key) : '';
            if (empty($apiKey))
                throw new \Exception("Google AI Provider '{$providerData->name}' lacks an API Key.");
            return new GoogleAIProvider($apiKey);
        } elseif ($providerData->provider_type === 'lmstudio') {
            if (empty($providerData->api_url))
                throw new \Exception("Local AI Provider '{$providerData->name}' lacks an API URL.");
            require_once __DIR__ . '/LMStudioAIProvider.php';
            $apiKey = !empty($providerData->api_key) ? decrypt($providerData->api_key) : '';
            return new LMStudioAIProvider($providerData->api_url, $apiKey);
        } elseif ($providerData->provider_type === 'replicate') {
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

    public function getAnalysis(string $tone = null, string $customInstruction = null, bool $forceRegenerate = false, bool $forceFallback = false, string $intent = 'AUTO', bool $useSummaryToggle = true, bool $includeHistoricalContext = false): array
    {
        // 1. Rate Limit Check
        $this->checkRateLimit();

        // 2. Extract Data
        $scrubPII = !empty($this->settings['compliance_mode']) || !empty($this->settings['pii_scrub_enabled']);
        $extractor = new TicketDataExtractor($this->ticketId, $this->adminId);
        $context = $extractor->getContext($scrubPII);

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

        // 3. Hash Generation for Cache
        $hashData = serialize([
            $context['subject'],
            $context['messages'], // Includes full message history
            $tone,
            $customInstruction,
            $intent,
            $this->settings['model_name'],
            $this->settings['system_prompt']
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

        // 5. Call AI Provider (Primary with Fallback logic)
        $startTime = microtime(true);
        $usedFallback = false;

        try {
            if ($forceFallback && $this->fallbackProvider) {
                throw new \Exception("Manual fallback requested via frontend.");
            }
            // Attempt Primary Note: The provider utilizes $this->settings['model_name']
            $response = $this->provider->generateResponse(
                $context,
                $this->settings,
                $tone,
                $customInstruction
            );
            $activeProvider = $this->provider;
        } catch (\Exception $e) {
            $this->logRequest($context, null, 0, microtime(true) - $startTime, "Primary Error: " . $e->getMessage());

            if ($this->fallbackProvider) {
                // Attempt Fallback
                $usedFallback = true;
                $fallbackStart = microtime(true);
                // Temporarily swap model_name to the fallback's model string if the fallback provider configures it like that
                $this->settings['model_name'] = $this->settings['fallback_model_name'];

                try {
                    $response = $this->fallbackProvider->generateResponse(
                        $context,
                        $this->settings,
                        $tone,
                        $customInstruction
                    );
                    $activeProvider = $this->fallbackProvider;
                } catch (\Exception $fallbackErr) {
                    $this->logRequest($context, null, 0, microtime(true) - $fallbackStart, "Fallback Error: " . $fallbackErr->getMessage());
                    throw new \Exception("Both Primary and Fallback AI Providers failed. Latest Error: " . $fallbackErr->getMessage());
                }
            } else {
                throw $e;
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
            'system' => $this->settings['system_prompt'],
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

    public function getPayload(string $tone = null, string $customInstruction = null, bool $forceRegenerate = false, string $intent = 'AUTO', bool $useSummaryToggle = true, bool $includeHistoricalContext = false): array
    {
        // 1. Rate Limit Check
        $this->checkRateLimit();

        // 2. Extract Data
        $scrubPII = !empty($this->settings['compliance_mode']) || !empty($this->settings['pii_scrub_enabled']);
        $extractor = new TicketDataExtractor($this->ticketId, $this->adminId);
        $context = $extractor->getContext($scrubPII);

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

        $systemPrompt = $this->settings['system_prompt'];

        // Append Knowledgebase Rules
        $kbPath = dirname(__DIR__) . '/knowledgebase';
        if (is_dir($kbPath)) {
            $kbRules = "";
            $dir = new \DirectoryIterator($kbPath);
            foreach ($dir as $fileinfo) {
                if (!$fileinfo->isDot() && $fileinfo->getExtension() === 'txt') {
                    $content = @file_get_contents($fileinfo->getPathname());
                    if ($content) {
                        $kbRules .= "\n--- Rule: {$fileinfo->getFilename()} ---\n" . trim($content) . "\n";
                    }
                }
            }
            if (!empty($kbRules)) {
                $systemPrompt .= "\n\n=== RULES & KNOWLEDGEBASE ===\n" .
                    "The following facts, rules, and guidelines MUST be strictly adhered to when crafting the CLIENT_REPLY:\n" .
                    $kbRules;
            }
        }

        // Feature: Inject Historical Client Context (Memory)
        if ($includeHistoricalContext) {
            $historicalContext = $this->getHistoricalContext();
            if ($historicalContext) {
                $systemPrompt .= "\n\n=== HISTORICAL CLIENT CONTEXT ===\n" .
                    "The following is an AI-generated summary of the client's past tickets. Use this to understand their history and tailor your response if their past issues are related to the current ticket:\n" .
                    $historicalContext;
            }
        }

        // 3. Hash generation for Cache checking
        $hashData = serialize([
            $context['subject'],
            $context['messages'],
            $tone,
            $customInstruction,
            $intent,
            $this->settings['model_name'],
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

        // Return the payload data needed for the browser to make the request
        return [
            'status'               => 'success',
            'cached'               => false,
            'hash_signature'       => $hashSignature,
            'provider'             => $this->settings['provider_type'] === 'lmstudio' ? 'lmstudio' : ($this->settings['provider_type'] === 'replicate' ? 'replicate' : 'google'),
            'api_url'              => $this->settings['api_url'] ?? '',
            'api_key'              => $this->settings['api_key'] ?? '',
            'model'                => $this->settings['model_name'],
            'temperature'          => (float) $this->settings['temperature'],
            'max_tokens'           => (int) $this->settings['max_tokens'],
            'system_prompt'        => $systemPrompt,
            'user_prompt_template' => $this->settings['user_prompt_template'] ?? null,
            'context'              => $context,
            'tone'                 => $tone,
            'custom_instruction'   => $customInstruction,
            'intent'               => $intent,
            'has_fallback'         => $this->fallbackProvider !== null,
            'fallback_api_key'     => $this->settings['fallback_api_key'] ?? '',
            'summary_used'         => $summaryUsed,
            'summary_available'    => $this->getSummary() !== null,
            'message_count'        => count($context['messages'] ?? []),
            'summarizer_threshold' => $summarizerThreshold,
        ];
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
        $fakeSettings = $this->settings;
        $fakeSettings['user_prompt_template'] = '{{MESSAGES}}
{{ATTACHMENTS_BLOCK}}';
        $fakeSettings['system_prompt']        = $systemPrompt;
        $fakeSettings['max_tokens']           = 1024; // summaries are short but might need room for file analysis

        $startTime = microtime(true);
        $activeProvider = $this->provider;
        
        try {
            $rawResponse = $this->provider->generateResponse($fakeContext, $fakeSettings, 'Professional', '');
        } catch (\Exception $e) {
            if ($this->fallbackProvider) {
                try {
                    $fakeSettings['model_name'] = $this->settings['fallback_model_name'] ?? $this->settings['model_name'];
                    $rawResponse = $this->fallbackProvider->generateResponse($fakeContext, $fakeSettings, 'Professional', '');
                    $activeProvider = $this->fallbackProvider;
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

        $fakeSettings = $this->settings;
        $fakeSettings['user_prompt_template'] = '{{MESSAGES}}';
        $fakeSettings['system_prompt']        = $systemPrompt;

        $startTime = microtime(true);
        $activeProvider = $this->provider;
        
        try {
            $rawResponse = $this->provider->generateResponse($fakeContext, $fakeSettings, 'Professional', '');
        } catch (\Exception $e) {
            if ($this->fallbackProvider) {
                try {
                    $fakeSettings['model_name'] = $this->settings['fallback_model_name'] ?? $this->settings['model_name'];
                    $rawResponse = $this->fallbackProvider->generateResponse($fakeContext, $fakeSettings, 'Professional', '');
                    $activeProvider = $this->fallbackProvider;
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

        return [
            'status' => 'success',
            'provider' => $this->settings['provider_type'] === 'lmstudio' ? 'lmstudio' : ($this->settings['provider_type'] === 'replicate' ? 'replicate' : 'google'),
            'api_url' => $this->settings['api_url'] ?? '',
            'api_key' => $this->settings['api_key'] ?? '',
            'model' => $this->settings['model_name'],
            'temperature' => (float) $this->settings['temperature'],
            'max_tokens' => (int) $this->settings['max_tokens'],
            'system_prompt' => $systemPrompt,
            'rewrite_prompt' => $rewritePrompt,
            'has_fallback' => $this->fallbackProvider !== null,
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

        // Use the primary AI provider in free-text mode (no JSON schema)
        $startTime = microtime(true);
        try {
            // We call generateResponse but override the prompt using a special "freeform" approach.
            // Since GoogleAIProvider / LMStudioAIProvider both accept raw context+settings,
            // we build a minimal context that carries our custom prompt as the sole message.
            $fakeContext = [
                'subject' => $context['subject'] ?? '',
                'client_name' => $context['client_name'] ?? '',
                'department' => '',
                'services_summary' => '',
                'attachments_text' => '',
                'messages' => [['admin' => false, 'date' => '', 'message' => $promptText]],
            ];

            $fakeSettings = $this->settings;
            $fakeSettings['user_prompt_template'] = '{{MESSAGES}}'; // pass our custom prompt straight through
            $fakeSettings['system_prompt'] = $systemPrompt;

            $rawResponse = $this->provider->generateResponse($fakeContext, $fakeSettings, $tone, '');
            $execTimeMs = round((microtime(true) - $startTime) * 1000);

            // generateResponse returns a parsed JSON array; for rewrite we prefer CLIENT_REPLY if present.
            // Replicate freeform responses come back as ['__raw_text__' => '...'].
            if (is_array($rawResponse) && isset($rawResponse['CLIENT_REPLY'])) {
                $reply = $rawResponse['CLIENT_REPLY'];
            } elseif (is_array($rawResponse) && isset($rawResponse['__raw_text__'])) {
                $reply = $rawResponse['__raw_text__'];
            } elseif (is_array($rawResponse) && isset($rawResponse['reply'])) {
                $reply = $rawResponse['reply'];
            } elseif (is_string($rawResponse)) {
                $reply = $rawResponse;
            } else {
                // The whole array might be the result — stringify it best we can
                $reply = implode("\n\n", array_filter(array_values($rawResponse), 'is_string'));
            }

            $tokensUsed = $this->provider->getLastTokenUsage() ?? 0;
            $providerName = $this->provider->getName() ?? 'Primary';
            $this->logAuditEntry('rewrite', $promptText, $reply, $tokensUsed, $execTimeMs, $providerName);

            return [
                'status' => 'success',
                'reply' => $reply,
                'execution_time_ms' => $execTimeMs,
                'tokens_used' => $tokensUsed,
            ];
        } catch (\Exception $e) {
            // Try fallback if available
            if ($this->fallbackProvider) {
                try {
                    $fakeContext = [
                        'subject' => $context['subject'] ?? '',
                        'client_name' => $context['client_name'] ?? '',
                        'department' => '',
                        'services_summary' => '',
                        'attachments_text' => '',
                        'messages' => [['admin' => false, 'date' => '', 'message' => $promptText]],
                    ];
                    $fakeSettings = $this->settings;
                    $fakeSettings['model_name'] = $this->settings['fallback_model_name'] ?? $this->settings['model_name'];
                    $fakeSettings['user_prompt_template'] = '{{MESSAGES}}';
                    $fakeSettings['system_prompt'] = $systemPrompt;
                    $rawResponse = $this->fallbackProvider->generateResponse($fakeContext, $fakeSettings, $tone, '');
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
                    $tokensUsedFallback = $this->fallbackProvider->getLastTokenUsage() ?? 0;
                    $providerNameFallback = $this->fallbackProvider->getName() ?? 'Fallback';
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
        
        $fakeSettings = $this->settings;
        $fakeSettings['user_prompt_template'] = '{{MESSAGES}}';
        $fakeSettings['system_prompt'] = $this->promptTemplates['score_reply'] ?? "You are an expert QA Manager scoring support replies. Output strictly a single raw JSON object matching the requested schema.";

        $fakeContext = [
            'subject' => '',
            'client_name' => '',
            'department' => '',
            'services_summary' => '',
            'attachments_text' => '',
            'messages' => [['admin' => false, 'date' => '', 'message' => $promptText]],
        ];

        try {
            $startTime = microtime(true);
            $rawResponse = $this->provider->generateResponse($fakeContext, $fakeSettings, 'Professional', '');
            $execTimeMs = round((microtime(true) - $startTime) * 1000);
            
            $scoreData = ['SCORE' => 0, 'CLARITY' => 0, 'TONE_SCORE' => 0, 'COMPLETENESS' => 0, 'REPLY_NOTES' => 'Parse failed'];
            
            if (is_array($rawResponse)) {
                $scoreData = array_merge($scoreData, $rawResponse);
            } elseif (is_string($rawResponse)) {
                $cleanStr = trim($rawResponse);
                // Be more aggressive — look for { ... } block
                if (preg_match('/\{[\s\S]*\}/', $cleanStr, $matches)) {
                    $jsonBlock = $matches[0];
                    $decoded = json_decode($jsonBlock, true);
                    if ($decoded && is_array($decoded)) {
                        $scoreData = array_merge($scoreData, $decoded);
                    }
                }
            }

            // Unify keys in case AI outputs lowercase (for robustness)
            if (isset($scoreData['score'])) { $scoreData['SCORE'] = $scoreData['score']; }
            if (isset($scoreData['clarity'])) { $scoreData['CLARITY'] = $scoreData['clarity']; }
            if (isset($scoreData['tone_score'])) { $scoreData['TONE_SCORE'] = $scoreData['tone_score']; }
            if (isset($scoreData['completeness'])) { $scoreData['COMPLETENESS'] = $scoreData['completeness']; }
            if (isset($scoreData['notes'])) { $scoreData['REPLY_NOTES'] = $scoreData['notes']; }
            if (isset($scoreData['REPLY_NOTES'])) { $scoreData['notes'] = $scoreData['REPLY_NOTES']; } // Back-compatibility for DB if needed

            // Save to DB
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
        $directives = [
            'RESOLVE' => "REPLY INTENT — RESOLVED: The admin confirms this issue has been resolved. Write CLIENT_REPLY as a confident closing message. Acknowledge what was fixed, thank the client for their patience, and advise them to reopen the ticket if the issue recurs. Do NOT ask further questions.",
            'INVESTIGATE' => "REPLY INTENT — INVESTIGATING: The admin is still actively investigating this issue. Write CLIENT_REPLY to acknowledge the issue empathetically, confirm the support team is actively working on it, and set realistic expectations without making firm time commitments. Keep the client reassured.",
            'MORE_INFO' => "REPLY INTENT — NEED MORE INFORMATION: The admin needs additional details before proceeding. Write CLIENT_REPLY to clearly and politely list exactly what specific information, logs, screenshots, credentials, or steps are required from the client. Be precise — avoid vague requests.",
            'GUIDE' => "REPLY INTENT — GUIDE TO SOLUTION: The admin wants to guide the client to self-resolve. Write CLIENT_REPLY as a clear, step-by-step guide in simple language the client can follow independently. Use numbered steps. Anticipate likely stumbling points and address them proactively.",
            'OUT_OF_SCOPE' => "REPLY INTENT — OUT OF SUPPORT SCOPE: This issue falls outside the support boundaries. Write CLIENT_REPLY to clearly but respectfully explain that this specific issue is not covered under the current support scope or plan. Where applicable, point to relevant resources, documentation, or upgrade options. Be firm yet courteous — avoid leaving the client feeling dismissed.",
            'DUPLICATE' => "REPLY INTENT — DUPLICATE TICKET: This is a duplicate of an existing ticket. Write CLIENT_REPLY to politely inform the client that this appears to be a duplicate of an existing ticket they have already submitted. Instruct them to continue communication on the original ticket to avoid confusion and ensure continuity of support. Close this ticket gracefully.",
        ];

        $key = strtoupper(trim($intent));
        return $directives[$key] ?? ''; // Returns '' for 'AUTO' or unknown values
    }

    /**
     * Log an AI interaction to the audit trail database table.
     */
    private function logAuditEntry(string $actionType, string $prompt, string $response, int $tokensUsed, int $execTimeMs, string $providerName)
    {
        try {
            Capsule::table('tblsahdev_audit_trail')->insert([
                'ticket_id' => $this->ticketId,
                'admin_id' => $this->adminId,
                'action_type' => $actionType,
                'prompt_text' => $prompt,
                'response_text' => $response,
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

        $fakeSettings = $this->settings;
        $fakeSettings['user_prompt_template'] = '{{MESSAGES}}'; 
        $fakeSettings['system_prompt'] = "You are an expert technical writer creating generalized canned response templates.";

        $fakeContext = [
            'subject' => '',
            'client_name' => '',
            'department' => '',
            'services_summary' => '',
            'attachments_text' => '',
            'messages' => [['admin' => false, 'date' => '', 'message' => $promptText]],
        ];

        try {
            $startTime = microtime(true);
            $rawResponse = $this->provider->generateResponse($fakeContext, $fakeSettings, 'Professional', '');
            $execTimeMs = round((microtime(true) - $startTime) * 1000);
            $tokensUsed = $this->provider->getLastTokenUsage() ?? 0;
            $providerName = $this->provider->getName() ?? 'Unknown';

            // Ensure we handle arrays back from providers gracefully
            $template = '';
            if (is_string($rawResponse)) {
                $template = trim($rawResponse);
            } elseif (is_array($rawResponse) && isset($rawResponse['reply'])) {
                $template = trim($rawResponse['reply']);
            } elseif (is_array($rawResponse)) {
                $template = implode("\n\n", array_filter(array_values($rawResponse), 'is_string'));
            }

            // Fallback provider attempt if template is empty
            if (empty($template) && $this->fallbackProvider) {
                 $rawResponseFallback = $this->fallbackProvider->generateResponse($fakeContext, $fakeSettings, 'Professional', '');
                 if (is_string($rawResponseFallback)) {
                     $template = trim($rawResponseFallback);
                 } elseif (is_array($rawResponseFallback) && isset($rawResponseFallback['reply'])) {
                     $template = trim($rawResponseFallback['reply']);
                 } elseif (is_array($rawResponseFallback)) {
                     $template = implode("\n\n", array_filter(array_values($rawResponseFallback), 'is_string'));
                 }
                 $tokensUsed = $this->fallbackProvider->getLastTokenUsage() ?? 0;
                 $providerName = $this->fallbackProvider->getName() ?? 'Fallback';
            }

            if (empty($template)) {
                 return ['status' => 'error', 'message' => 'AI returned an empty template.'];
            }

            $this->logAuditEntry('generate_canned_template', $promptText, $template, $tokensUsed, $execTimeMs, $providerName);

            return ['status' => 'success', 'template' => $template, 'execution_time_ms' => $execTimeMs, 'tokens_used' => $tokensUsed];
        } catch (\Exception $e) {
            // Log fallback attempt on actual exception
            if ($this->fallbackProvider) {
                try {
                    $startTimeFb = microtime(true);
                    $rawResponseFallback = $this->fallbackProvider->generateResponse($fakeContext, $fakeSettings, 'Professional', '');
                    $execTimeMsFb = round((microtime(true) - $startTimeFb) * 1000);
                    $tokensUsedFb = $this->fallbackProvider->getLastTokenUsage() ?? 0;
                    $providerNameFb = $this->fallbackProvider->getName() ?? 'Fallback';

                    $template = '';
                    if (is_string($rawResponseFallback)) {
                        $template = trim($rawResponseFallback);
                    } elseif (is_array($rawResponseFallback) && isset($rawResponseFallback['reply'])) {
                        $template = trim($rawResponseFallback['reply']);
                    } elseif (is_array($rawResponseFallback)) {
                        $template = implode("\n\n", array_filter(array_values($rawResponseFallback), 'is_string'));
                    }

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
     * Save a generated template as a WHMCS Knowledgebase Article.
     */
    public function saveKbArticle(string $title, string $templateText): array
    {
        if (empty(trim($title)) || empty(trim($templateText))) {
            return ['status' => 'error', 'message' => 'Title and Article text are required.'];
        }

        try {
            // Find or create 'Sahdev AI Generated' category to put articles in
            $catId = 0;
            if (Capsule::schema()->hasTable('tblknowledgebasecats')) {
                 $cat = Capsule::table('tblknowledgebasecats')->where('name', 'Sahdev AI Generated')->first();
                 if ($cat) {
                     $catId = $cat->id;
                 } else {
                     try {
                         $catId = Capsule::table('tblknowledgebasecats')->insertGetId([
                             'parentid' => 0,
                             'name' => 'Sahdev AI Generated',
                             'description' => 'Articles automatically generated by Sahdev AI Templates',
                             'hidden' => 'on',
                         ]);
                     } catch (\Exception $e) {
                         // Fallback in case 'hidden' column missing or different schema
                         $catId = Capsule::table('tblknowledgebasecats')->insertGetId([
                             'parentid' => 0,
                             'name' => 'Sahdev AI Generated',
                             'description' => 'Articles automatically generated by Sahdev AI Templates'
                         ]);
                     }
                 }
            }

            $articleData = [
                'title' => trim($title),
                'article' => trim($templateText),
                'views' => 0,
            ];

            // Some WHMCS versions require categoryid right on the article table
            if (Capsule::schema()->hasColumn('tblknowledgebase', 'categoryid')) {
                $articleData['categoryid'] = $catId;
            }

            if (Capsule::schema()->hasColumn('tblknowledgebase', 'language')) {
                $articleData['language'] = '';
            }

            $articleId = Capsule::table('tblknowledgebase')->insertGetId($articleData);

            // WHMCS v7+ uses tblknowledgebaselinks for categories
            if (Capsule::schema()->hasTable('tblknowledgebaselinks') && $catId > 0) {
                Capsule::table('tblknowledgebaselinks')->insert([
                    'categoryid' => $catId,
                    'articleid' => $articleId
                ]);
            }

            return ['status' => 'success', 'message' => 'Saved to Knowledgebase successfully.', 'article_id' => $articleId];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'Failed to save KB article: ' . $e->getMessage()];
        }
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
                    ]
                ]
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'Failed to load analytics: ' . $e->getMessage()];
        }
    }
}
