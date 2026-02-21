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
            return new LMStudioAIProvider($providerData->api_url);
        }
        throw new \Exception("Unsupported AI Provider Type: " . $providerData->provider_type);
    }

    private function loadSettings()
    {
        // Auto-migration check
        if (!Capsule::schema()->hasTable('tblsahdev_providers') || !Capsule::schema()->hasColumn('tblsahdev_settings', 'primary_provider_id')) {
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
        $this->provider = $this->initializeProvider($primaryData);

        // Load Fallback Provider (Optional)
        $fallbackId = $this->settings['fallback_provider_id'] ?? 0;
        if ($fallbackId > 0 && $fallbackId !== $primaryId) {
            $fallbackData = Capsule::table('tblsahdev_providers')->where('id', $fallbackId)->first();
            if ($fallbackData) {
                // We keep the settings instance mostly the same but initialize the second provider
                $this->fallbackProvider = $this->initializeProvider($fallbackData);
                $this->settings['fallback_model_name'] = $fallbackData->model_name;
            }
        }
    }

    public function getAnalysis(string $tone = null, string $customInstruction = null, bool $forceRegenerate = false): array
    {
        // 1. Rate Limit Check
        $this->checkRateLimit();

        // 2. Extract Data
        $extractor = new TicketDataExtractor($this->ticketId, $this->adminId);
        $context = $extractor->getContext();

        if (!$tone) {
            $tone = $this->settings['tone_default'];
        }

        // 3. Hash Generation for Cache
        $hashData = serialize([
            $context['subject'],
            $context['messages'], // Includes full message history
            $tone,
            $customInstruction,
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
        $tokenUsage = $this->provider->getLastTokenUsage();
        $tokenDetails = $this->provider->getLastTokenDetails();

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

    public function getPayload(string $tone = null, string $customInstruction = null, bool $forceRegenerate = false): array
    {
        // 1. Rate Limit Check
        $this->checkRateLimit();

        // 2. Extract Data
        $extractor = new TicketDataExtractor($this->ticketId);
        $context = $extractor->getContext();

        if (!$tone) {
            $tone = $this->settings['tone_default'];
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
            'provider' => $this->provider instanceof LMStudioAIProvider ? 'lmstudio' : 'google',
            'api_url' => $this->settings['api_url'] ?? '',
            'model' => $this->settings['model_name'],
            'temperature' => (float) $this->settings['temperature'],
            'max_tokens' => (int) $this->settings['max_tokens'],
            'system_prompt' => $systemPrompt,
            'context' => $context,
            'has_fallback' => $this->fallbackProvider !== null,
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
}
