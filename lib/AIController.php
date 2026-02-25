<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;
use Carbon\Carbon;

class AIController
{
    private $ticketId;
    private $adminId;
    private $provider;
    private $settings;

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

    public function getAnalysis(string $tone = null, string $customInstruction = null, bool $forceRegenerate = false, bool $forceFallback = false, string $intent = 'AUTO', bool $useSummaryToggle = true): array
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

        // Summarizer injection: replace full message history with condensed summary if toggle is checked
        $summarizerEnabled = !empty($this->settings['summarizer_enabled']);
        if ($summarizerEnabled && $useSummaryToggle) {
            $existingSummary = $this->getSummary();
            if ($existingSummary) {
                $context['messages'] = [[
                    'admin'   => false,
                    'date'    => '',
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

    public function getPayload(string $tone = null, string $customInstruction = null, bool $forceRegenerate = false, string $intent = 'AUTO', bool $useSummaryToggle = true): array
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

        // Summarizer injection: replace full message history with condensed summary if toggle is checked
        $summarizerEnabled = !empty($this->settings['summarizer_enabled']);
        $summaryUsed = false;
        if ($summarizerEnabled && $useSummaryToggle) {
            $existingSummary = $this->getSummary();
            if ($existingSummary) {
                $context['messages'] = [[
                    'admin'   => false,
                    'date'    => '',
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
            'message_count'        => count($extractor->getContext()['messages'] ?? []),
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

        $systemPrompt = "You are a senior technical support analyst. Your job is to create concise, accurate ticket summaries that capture the essential context: root issue, actions taken, client sentiment, and current status.

SUMMARY RULES:
- Length: adapt dynamically based on ticket complexity (short tickets → 3-5 lines; complex tickets → 8-12 lines)
- Include: the original problem, key technical details exchanged, any steps already tried, current status
- Do NOT include greetings, small talk, or formatting metadata
- Write in past-tense, third-person, concise prose
- Output ONLY the summary text. No labels, no JSON, no prefixes.";

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
            'attachments_text' => '',
            'messages'         => [['admin' => false, 'date' => '', 'message' => $promptText]],
        ];
        $fakeSettings = $this->settings;
        $fakeSettings['user_prompt_template'] = '{{MESSAGES}}';
        $fakeSettings['system_prompt']        = $systemPrompt;
        $fakeSettings['max_tokens']           = 512; // summaries are short

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

        $rewritePrompt = "=== TASK ===\n";
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

        $promptText = "=== TASK ===\n";
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
        $promptText .= "Provide a score from 0 to 100 for each, an overall score, and brief constructive feedback.\n\n";
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
        $fakeSettings['system_prompt'] = "You are an expert QA Manager scoring support replies. Output strictly valid JSON matching this schema:\n{\"score\": 85, \"clarity\": 90, \"tone_score\": 85, \"completeness\": 80, \"notes\": \"Brief feedback here\"}";

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
            
            $scoreData = ['score' => 0, 'clarity' => 0, 'tone_score' => 0, 'completeness' => 0, 'notes' => 'Parse failed'];
            
            if (is_array($rawResponse)) {
                $scoreData = array_merge($scoreData, $rawResponse);
            } elseif (is_string($rawResponse)) {
                $cleanStr = preg_replace('/```json|```/', '', $rawResponse);
                $decoded = json_decode(trim($cleanStr), true);
                if ($decoded && is_array($decoded)) {
                    $scoreData = array_merge($scoreData, $decoded);
                }
            }

            // Save to DB
            Capsule::table('tblsahdev_quality_scores')->insert([
                'ticket_id' => $this->ticketId,
                'admin_id' => $this->adminId,
                'score' => (int)($scoreData['score'] ?? 0),
                'clarity' => (int)($scoreData['clarity'] ?? 0),
                'tone_score' => (int)($scoreData['tone_score'] ?? 0),
                'completeness' => (int)($scoreData['completeness'] ?? 0),
                'notes' => substr($scoreData['notes'] ?? '', 0, 500),
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
}
