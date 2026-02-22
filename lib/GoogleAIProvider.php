<?php

namespace Sahdev\Lib;

/**
 * Class GoogleAIProvider
 * Implementation of Google Gemini AI integration.
 */
class GoogleAIProvider implements AIProviderInterface
{
    private $apiKey;
    private $lastTokenUsage = 0;
    private $lastTokenDetails = ['input' => 0, 'output' => 0];
    private $maxRetries = 2;
    private $timeout = 30; // seconds

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * @inheritDoc
     */
    public function generateResponse(array $context, array $settings, string $tone, string $customInstruction): array
    {
        $model = $settings['model_name'] ?: 'models/gemini-1.5-pro';
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/{$model}:generateContent?key=" . $this->apiKey;

        // Build the prompt containing structure for the LLM
        $prompt = $this->buildPrompt($context, $tone, $customInstruction, $settings['system_prompt']);

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => (float) $settings['temperature'],
                'maxOutputTokens' => (int) $settings['max_tokens'],
                'responseMimeType' => 'application/json',
            ],
            'systemInstruction' => [
                'parts' => [
                    ['text' => $settings['system_prompt']]
                ]
            ]
        ];

        $jsonPayload = json_encode($payload);

        // Retry logic
        $attempts = 0;
        $response = null;
        $exception = null;

        while ($attempts <= $this->maxRetries) {
            $attempts++;
            try {
                $response = $this->makeRequest($endpoint, $jsonPayload);

                // If successful, break
                if (isset($response['candidates'][0]['content']['parts'][0]['text'])) {
                    break;
                } else if (isset($response['error'])) {
                    throw new \Exception("Google AI Error: " . ($response['error']['message'] ?? 'Unknown Error'));
                }
            } catch (\Exception $e) {
                $exception = $e;
                if ($attempts <= $this->maxRetries) {
                    sleep(1); // Wait 1 second before retrying
                }
            }
        }

        if (!$response && $exception) {
            throw $exception;
        }

        $responseText = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';

        // Update token usage calculation
        if (isset($response['usageMetadata'])) {
            $this->lastTokenUsage = $response['usageMetadata']['totalTokenCount'] ?? 0;
            $this->lastTokenDetails['input'] = $response['usageMetadata']['promptTokenCount'] ?? 0;
            $this->lastTokenDetails['output'] = $response['usageMetadata']['candidatesTokenCount'] ?? 0;
        }

        // Parse JSON
        $parsed = json_decode($responseText, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Unlikely with responseMimeType: application/json but good fallback
            throw new \Exception("Failed to decode JSON from AI response: " . json_last_error_msg());
        }

        // Validate structure
        return $this->validateStructure($parsed);
    }

    /**
     * @inheritDoc
     */
    public function getLastTokenUsage(): int
    {
        return $this->lastTokenUsage;
    }

    /**
     * @inheritDoc
     */
    public function getLastTokenDetails(): array
    {
        return $this->lastTokenDetails;
    }

    /**
     * @inheritDoc
     */
    public function getProviderType(): string
    {
        return 'google';
    }

    /**
     * @inheritDoc
     */
    public function getApiUrl(): string
    {
        return "https://generativelanguage.googleapis.com";
    }

    /**
     * @inheritDoc
     */
    public function getAvailableModels(string $apiKey): array
    {
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models?key=" . $apiKey;
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$result) {
            return [];
        }

        $decoded = json_decode($result, true);
        $models = [];

        if (isset($decoded['models']) && is_array($decoded['models'])) {
            foreach ($decoded['models'] as $model) {
                if (strpos($model['name'], 'gemini') !== false) {
                    $models[] = $model['name'];
                }
            }
        }

        return $models;
    }

    /**
     * Makes cURL request
     */
    private function makeRequest(string $endpoint, string $payload): array
    {
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        $result = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            throw new \Exception("cURL Error: $error");
        }

        $decoded = json_decode($result, true);

        if ($httpCode >= 400 && $httpCode < 600) {
            $msg = $decoded['error']['message'] ?? 'Unknown API Error';
            throw new \Exception("API returned $httpCode: $msg");
        }

        return $decoded;
    }

    /**
     * Ensures we return a valid structure
     */
    private function validateStructure($parsed): array
    {
        $defaults = [
            'ROOT_CAUSE' => 'Analysis failed.',
            'RESPONSIBILITY' => 'Unknown',
            'RISK_LEVEL' => 'Unknown',
            'INTERNAL_ACTION_PLAN' => 'Review raw response.',
            'CLIENT_REPLY' => 'No reply generated.',
        ];

        return array_merge($defaults, $parsed ?: []);
    }

    /**
     * Build the prompt text safely
     */
    private function buildPrompt(array $context, string $tone, string $customInstruction, string $systemPrompt): string
    {
        $prompt = "=== TASK ===\n";
        $prompt .= "Analyze the provided technical support ticket and output strictly in a valid JSON object matching the schema below. Do not include any extra text, markdown blocks, or commentary.\n\n";
        
        $prompt .= "=== SCHEMA ===\n";
        $prompt .= "{\n";
        $prompt .= "  \"ROOT_CAUSE\": \"string (brief technical analysis of the issue)\",\n";
        $prompt .= "  \"RESPONSIBILITY\": \"string (Client, Host, or 3rd Party)\",\n";
        $prompt .= "  \"RISK_LEVEL\": \"string (Low, Medium, High, or Critical)\",\n";
        $prompt .= "  \"INTERNAL_ACTION_PLAN\": \"string (detailed steps for the support team)\",\n";
        $prompt .= "  \"CLIENT_REPLY\": \"string (The direct reply to the client, formatted in simple Markdown)\"\n";
        $prompt .= "}\n\n";

        $prompt .= "=== CONTEXTUAL DIRECTIVES ===\n";
        if (!empty($tone)) {
            $prompt .= "- TONE: Write the CLIENT_REPLY in a {$tone} tone.\n";
        }
        if (!empty($customInstruction)) {
            $prompt .= "- CUSTOM ADMIN INSTRUCTION (MANDATORY): {$customInstruction}\n";
        }
        $prompt .= "- CLIENT_REPLY CONTENT: Generate ONLY the body. Skip all greetings and sign-offs.\n\n";

        $prompt .= "=== TICKET DATA ===\n";
        $prompt .= "Client Name: " . ($context['client_name'] ?? 'Unknown Client') . "\n";
        $prompt .= "Department: " . ($context['department'] ?? 'Support') . "\n";
        $prompt .= "Subject: " . ($context['subject'] ?? 'Ticket') . "\n";

        if (!empty($context['services_summary'])) {
            $prompt .= "Services Information:\n" . $context['services_summary'] . "\n";
        }

        $prompt .= "\n=== CONVERSATION HISTORY ===\n";
        if (!empty($context['messages'])) {
            // Fill from newest -> oldest for context priority
            $msgs = array_reverse($context['messages']);
            $used = 0;
            $budget = 8000;
            $lines = [];
            foreach ($msgs as $msg) {
                $type = $msg['admin'] ? 'ADMIN' : 'CLIENT';
                $entry = "[{$type}] ({$msg['date']}):\n" . ($msg['message'] ?? '') . "\n\n";
                if ($used + strlen($entry) > $budget) break;
                $lines[] = $entry;
                $used += strlen($entry);
            }
            $prompt .= implode("", array_reverse($lines));
        }

        if (!empty($context['attachments_text'])) {
            $prompt .= "\n=== ATTACHMENT CONTEXT ===\n" . substr($context['attachments_text'], 0, 2000) . "\n";
        }

        return $prompt;
    }
}
