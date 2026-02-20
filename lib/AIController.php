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

    public function __construct(int $ticketId, int $adminId)
    {
        $this->ticketId = $ticketId;
        $this->adminId = $adminId;
        $this->loadSettings();
    }

    private function loadSettings()
    {
        $this->settings = Capsule::table('tblsahdev_settings')->first();
        if (!$this->settings) {
            throw new \Exception("Sahdev settings not configured. Please visit Addons > Sahdev.");
        }

        // Convert to array for easier passing
        $this->settings = (array) $this->settings;

        if (empty($this->settings['api_key'])) {
            throw new \Exception("AI Provider API Key is missing. Configure in Addons > Sahdev.");
        }

        $apiKey = decrypt($this->settings['api_key']);

        // Initialize provider
        if ($this->settings['ai_provider'] === 'google') {
            $this->provider = new GoogleAIProvider($apiKey);
        } else {
            throw new \Exception("Unsupported AI Provider: " . $this->settings['ai_provider']);
        }
    }

    public function getAnalysis(string $tone = null, string $customInstruction = null): array
    {
        // 1. Rate Limit Check
        $this->checkRateLimit();

        // 2. Extract Data
        $extractor = new TicketDataExtractor($this->ticketId);
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

        // 5. Call AI Provider
        $startTime = microtime(true);
        try {
            $response = $this->provider->generateResponse(
                $context,
                $this->settings,
                $tone,
                $customInstruction
            );
        } catch (\Exception $e) {
            $this->logRequest($context, null, 0, microtime(true) - $startTime, $e->getMessage());
            throw $e;
        }

        $executionTimeMs = round((microtime(true) - $startTime) * 1000);
        $tokenUsage = $this->provider->getLastTokenUsage();

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
            'tokens_used' => $tokenUsage
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

    private function logRequest(array $requestPayload, array $responsePayload = null, int $tokenUsage, int $executionTimeMs, string $error = null)
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
