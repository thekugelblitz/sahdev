<?php

namespace Sahdev\Lib;

/**
 * Class LMStudioAIProvider
 * Implementation of LM Studio integration (OpenAI compatible API).
 */
class LMStudioAIProvider implements AIProviderInterface
{
    private $apiUrl;
    private $lastTokenUsage = 0;
    private $maxRetries = 1;
    private $timeout = 120; // local models can take longer

    public function __construct(string $apiUrl)
    {
        $this->apiUrl = rtrim($apiUrl, '/');
        // If they just put http://localhost:1234, append /v1/chat/completions
        if (substr($this->apiUrl, -1) !== 's' && substr($this->apiUrl, -4) !== 'chat' && strpos($this->apiUrl, 'v1') === false) {
            $this->apiUrl .= '/v1/chat/completions';
        }
    }

    /**
     * @inheritDoc
     */
    public function generateResponse(array $context, array $settings, string $tone, string $customInstruction): array
    {
        $model = $settings['model_name'] ?: 'local-model'; // LM Studio often ignores this but requires it

        $systemMessage = $settings['system_prompt'] ?? "You are a helpful assistant.";
        
        $prompt = $this->buildPrompt($context, $tone, $customInstruction);

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemMessage],
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => (float) $settings['temperature'],
            'max_tokens' => (int) $settings['max_tokens'],
            'stream' => false
        ];

        // Some versions of LM Studio support 'response_format' for JSON
        if (strpos($this->apiUrl, 'v1/chat/completions') !== false) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $jsonPayload = json_encode($payload);

        $attempts = 0;
        $response = null;
        $exception = null;

        while ($attempts <= $this->maxRetries) {
            $attempts++;
            try {
                $response = $this->makeRequest($this->apiUrl, $jsonPayload);

                if (isset($response['choices'][0]['message']['content'])) {
                    break;
                } else if (isset($response['error'])) {
                    throw new \Exception("LM Studio Error: " . ($response['error']['message'] ?? 'Unknown Error'));
                }
            } catch (\Exception $e) {
                $exception = $e;
                if ($attempts <= $this->maxRetries) {
                    sleep(2);
                }
            }
        }

        if (!$response && $exception) {
            throw $exception;
        }

        $responseText = $response['choices'][0]['message']['content'] ?? '';

        if (isset($response['usage']['total_tokens'])) {
            $this->lastTokenUsage = $response['usage']['total_tokens'];
        }

        // Clean up response if the model didn't strictly follow JSON output block
        $responseText = trim($responseText);
        if (strpos($responseText, '```json') !== false) {
            $responseText = preg_replace('/```json\s*/', '', $responseText);
            $responseText = preg_replace('/```\s*$/', '', $responseText);
            $responseText = trim($responseText);
        }
        
        // Strip out thought block if deepseek r1 format is used by local model
        $responseText = preg_replace('/<think>.*?<\/think>/s', '', $responseText);
        $responseText = trim($responseText);

        $parsed = json_decode($responseText, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Failed to decode JSON from LM Studio response: " . json_last_error_msg() . "\nRaw Output: " . substr($responseText, 0, 100));
        }

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
    public function getAvailableModels(string $apiKey): array
    {
        // For LM Studio, the models endpoint is typically /v1/models
        $baseUrl = parse_url($this->apiUrl, PHP_URL_SCHEME) . '://' . parse_url($this->apiUrl, PHP_URL_HOST);
        $port = parse_url($this->apiUrl, PHP_URL_PORT);
        if ($port) {
            $baseUrl .= ':' . $port;
        }
        
        $endpoint = $baseUrl . '/v1/models';
        
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

        if (isset($decoded['data']) && is_array($decoded['data'])) {
            foreach ($decoded['data'] as $model) {
                if (isset($model['id'])) {
                    $models[] = $model['id'];
                }
            }
        }

        return $models;
    }

    private function makeRequest(string $endpoint, string $payload): array
    {
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            // Some local apis require a bearer token even if just dummy
            'Authorization: Bearer dummy_token' 
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        $result = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            throw new \Exception("LM Studio Request Error (cURL): $error. Ensure LM Studio is running and API is enabled.");
        }

        $decoded = json_decode($result, true);

        if ($httpCode >= 400 && $httpCode < 600) {
            $msg = $decoded['error']['message'] ?? 'Unknown API Error';
            throw new \Exception("LM Studio returned $httpCode: $msg. Ensure the exact API endpoint URL is provided.");
        }

        return $decoded ?: [];
    }

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

    private function buildPrompt(array $context, string $tone, string $customInstruction): string
    {
        $prompt = "Analyze the given ticket and output strictly in a valid JSON object matching this schema without any markdown formatting block:\n";
        $prompt .= "{\n";
        $prompt .= "  \"ROOT_CAUSE\": \"string (brief analysis)\",\n";
        $prompt .= "  \"RESPONSIBILITY\": \"string (Client, Host, 3rd Party)\",\n";
        $prompt .= "  \"RISK_LEVEL\": \"string (Low, Medium, High, Critical)\",\n";
        $prompt .= "  \"INTERNAL_ACTION_PLAN\": \"string (steps team needs to take)\",\n";
        $prompt .= "  \"CLIENT_REPLY\": \"string (html formatted reply to be sent to user)\"\n";
        $prompt .= "}\n\n";

        if (!empty($tone)) {
            $prompt .= "The generated CLIENT_REPLY must have a {$tone} tone.\n";
        }

        if (!empty($customInstruction)) {
            $prompt .= "CUSTOM ADMIN INSTRUCTION (Follow strictly): {$customInstruction}\n\n";
        }

        $prompt .= "=== TICKET DATA ===\n";
        $prompt .= "Client Name: " . ($context['client_name'] ?? 'Unknown') . "\n";
        $prompt .= "Department: " . ($context['department'] ?? 'Unknown') . "\n";
        $prompt .= "Subject: " . ($context['subject'] ?? 'Unknown') . "\n";

        if (!empty($context['services_summary'])) {
            $prompt .= "Relevant Services: " . $context['services_summary'] . "\n";
        }

        $prompt .= "\n--- MESSAGES HISTORY ---\n";
        if (!empty($context['messages'])) {
            foreach ($context['messages'] as $msg) {
                $type = $msg['admin'] ? 'ADMIN/SUPPORT' : 'CLIENT';
                $prompt .= "[{$type}] {$msg['date']}:\n{$msg['message']}\n------------\n";
            }
        }

        if (!empty($context['attachments_text'])) {
            $prompt .= "\n--- ATTACHMENT EXCERPTS ---\n";
            $prompt .= $context['attachments_text'] . "\n";
        }

        return $prompt;
    }
}
