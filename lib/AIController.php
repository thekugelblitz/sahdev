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
        }
        throw new \Exception("Unsupported AI Provider Type: " . $providerData->provider_type);
    }

    private function loadSettings()
    {
        // Auto-migration check bypassing WHMCS schema cache
        try {
            Capsule::table('tblsahdev_providers')->first();
            Capsule::table('tblsahdev_settings')->select('primary_provider_id')->first();
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

    public function getAnalysis(string $tone = null, string $customInstruction = null, bool $forceRegenerate = false, bool $forceFallback = false, string $intent = 'AUTO'): array
    {
        // 1. Rate Limit Check
        $this->checkRateLimit();

        // 2. Extract Data
        $extractor = new TicketDataExtractor($this->ticketId, $this->adminId);
        $context = $extractor->getContext();

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

    public function getPayload(string $tone = null, string $customInstruction = null, bool $forceRegenerate = false, string $intent = 'AUTO'): array
    {
        // 1. Rate Limit Check
        $this->checkRateLimit();

        // 2. Extract Data
        $extractor = new TicketDataExtractor($this->ticketId, $this->adminId);
        $context = $extractor->getContext();

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
            'status' => 'success',
            'cached' => false,
            'hash_signature' => $hashSignature,
            'provider' => $this->settings['provider_type'] === 'lmstudio' ? 'lmstudio' : 'google',
            'api_url' => $this->settings['api_url'] ?? '',
            'api_key' => $this->settings['api_key'] ?? '',
            'model' => $this->settings['model_name'],
            'temperature' => (float) $this->settings['temperature'],
            'max_tokens' => (int) $this->settings['max_tokens'],
            'system_prompt' => $systemPrompt,
            'user_prompt_template' => $this->settings['user_prompt_template'] ?? null,
            'context' => $context,
            'tone' => $tone,
            'custom_instruction' => $customInstruction,
            'intent' => $intent,
            'has_fallback' => $this->fallbackProvider !== null,
            'fallback_api_key' => $this->settings['fallback_api_key'] ?? '',
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
     * Returns a focused intent directive string that gets prepended to customInstruction
     * before being passed to the AI prompt. Returns empty string for AUTO intent.
     */
    private function buildIntentDirective(string $intent): string
    {
        $directives = [
            'RESOLVE'      => "REPLY INTENT — RESOLVED: The admin confirms this issue has been resolved. Write CLIENT_REPLY as a confident closing message. Acknowledge what was fixed, thank the client for their patience, and advise them to reopen the ticket if the issue recurs. Do NOT ask further questions.",
            'INVESTIGATE'  => "REPLY INTENT — INVESTIGATING: The admin is still actively investigating this issue. Write CLIENT_REPLY to acknowledge the issue empathetically, confirm the support team is actively working on it, and set realistic expectations without making firm time commitments. Keep the client reassured.",
            'MORE_INFO'    => "REPLY INTENT — NEED MORE INFORMATION: The admin needs additional details before proceeding. Write CLIENT_REPLY to clearly and politely list exactly what specific information, logs, screenshots, credentials, or steps are required from the client. Be precise — avoid vague requests.",
            'GUIDE'        => "REPLY INTENT — GUIDE TO SOLUTION: The admin wants to guide the client to self-resolve. Write CLIENT_REPLY as a clear, step-by-step guide in simple language the client can follow independently. Use numbered steps. Anticipate likely stumbling points and address them proactively.",
            'OUT_OF_SCOPE' => "REPLY INTENT — OUT OF SUPPORT SCOPE: This issue falls outside the support boundaries. Write CLIENT_REPLY to clearly but respectfully explain that this specific issue is not covered under the current support scope or plan. Where applicable, point to relevant resources, documentation, or upgrade options. Be firm yet courteous — avoid leaving the client feeling dismissed.",
            'DUPLICATE'    => "REPLY INTENT — DUPLICATE TICKET: This is a duplicate of an existing ticket. Write CLIENT_REPLY to politely inform the client that this appears to be a duplicate of an existing ticket they have already submitted. Instruct them to continue communication on the original ticket to avoid confusion and ensure continuity of support. Close this ticket gracefully.",
        ];

        $key = strtoupper(trim($intent));
        return $directives[$key] ?? ''; // Returns '' for 'AUTO' or unknown values
    }
}
