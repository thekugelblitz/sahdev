<?php

namespace Sahdev\Lib;

/**
 * Class LMStudioAIProvider
 * Implementation of LM Studio integration (OpenAI compatible API).
 */
class LMStudioAIProvider implements AIProviderInterface
{
    private $apiUrl;
    private $apiKey;
    private $lastTokenUsage = 0;
    private $lastTokenDetails = ['input' => 0, 'output' => 0];
    private $maxRetries = 1;
    private $timeout = 120; // local models can take longer

    public function __construct(string $apiUrl, string $apiKey = '')
    {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->apiKey = $apiKey;
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

        $systemMessage = $settings['system_prompt'] ?? "You are a helpful Senior Technical Support Engineer.";
        $userPromptTemplate = $settings['user_prompt_template'] ?? null;

        $prompt = $this->buildPrompt($context, $tone, $customInstruction, $userPromptTemplate);

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

        if (isset($response['usage'])) {
            $this->lastTokenUsage = $response['usage']['total_tokens'] ?? 0;
            $this->lastTokenDetails['input'] = $response['usage']['prompt_tokens'] ?? 0;
            $this->lastTokenDetails['output'] = $response['usage']['completion_tokens'] ?? 0;
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
    public function getLastTokenDetails(): array
    {
        return $this->lastTokenDetails;
    }

    /**
     * @inheritDoc
     */
    public function getProviderType(): string
    {
        return 'lmstudio';
    }

    /**
     * @inheritDoc
     */
    public function getApiUrl(): string
    {
        return $this->apiUrl;
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
        $headers = [
            'Content-Type: application/json',
        ];

        // Use the provided API key if available, otherwise fallback to 'local' for auth-less setups
        $token = !empty($this->apiKey) ? $this->apiKey : 'local';
        $headers[] = 'Authorization: Bearer ' . $token;

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
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

    private function buildPrompt(array $context, string $tone, string $customInstruction, ?string $userPromptTemplate = null): string
    {
        // Build re-usable blocks
        $msgs = array_reverse($context['messages'] ?? []);
        $used = 0; $budget = 8000; $lines = [];
        foreach ($msgs as $msg) {
            $type = $msg['admin'] ? 'ADMIN' : 'CLIENT';
            $entry = "[{$type}] ({$msg['date']}):\n" . ($msg['message'] ?? '') . "\n\n";
            if ($used + strlen($entry) > $budget) break;
            $lines[] = $entry; $used += strlen($entry);
        }
        $messagesBlock = implode('', array_reverse($lines));
        $servicesBlock = !empty($context['services_summary']) ? "Services:\n{$context['services_summary']}\n" : '';
        $attachmentsBlock = !empty($context['attachments_text'])
            ? "\n=== ATTACHMENT CONTEXT ===\n" . substr($context['attachments_text'], 0, 2000) . "\n"
            : '';

        // Custom instruction is SUPREME PRIORITY — always rendered first
        $customInstructionBlock = '';
        if (!empty($customInstruction)) {
            $customInstructionBlock = "\u26a0\ufe0f PRIORITY OVERRIDE \u2014 ADMIN INSTRUCTION \u26a0\ufe0f\n"
                . "This instruction supersedes all other context. Re-interpret all ticket data through this lens.\n"
                . trim($customInstruction) . "\n"
                . str_repeat('\u2501', 40) . "\n\n";
        }

        // Use admin-defined template if available
        if (!empty($userPromptTemplate)) {
            return str_replace(
                ['{{CUSTOM_INSTRUCTION_BLOCK}}', '{{TONE}}', '{{CLIENT_NAME}}', '{{DEPARTMENT}}', '{{SUBJECT}}', '{{SERVICES_BLOCK}}', '{{MESSAGES}}', '{{ATTACHMENTS_BLOCK}}'],
                [$customInstructionBlock, $tone, $context['client_name'] ?? 'Unknown Client', $context['department'] ?? 'Support', $context['subject'] ?? 'Ticket', $servicesBlock, $messagesBlock, $attachmentsBlock],
                $userPromptTemplate
            );
        }

        // Fallback hardcoded prompt
        $prompt  = $customInstructionBlock;
        $prompt .= "=== TASK ===\nAnalyze the provided technical support ticket and output ONLY a valid JSON object. No extra text.\n\n";
        $prompt .= "=== SCHEMA ===\n{\n";
        $prompt .= "  \"ROOT_CAUSE\": \"string (brief technical analysis)\",\n";
        $prompt .= "  \"RESPONSIBILITY\": \"string (Client, Host, or 3rd Party)\",\n";
        $prompt .= "  \"RISK_LEVEL\": \"string (Low, Medium, High, or Critical)\",\n";
        $prompt .= "  \"INTERNAL_ACTION_PLAN\": \"string (detailed steps for the support team)\",\n";
        $prompt .= "  \"CLIENT_REPLY\": \"string (reply to client in Markdown — body only, no greeting or sign-off)\"\n}\n\n";
        $prompt .= "=== TONE ===\nWrite CLIENT_REPLY in a {$tone} tone.\n\n";
        $prompt .= "=== TICKET DATA ===\n";
        $prompt .= "Client: " . ($context['client_name'] ?? 'Unknown Client') . "\n";
        $prompt .= "Department: " . ($context['department'] ?? 'Support') . "\n";
        $prompt .= "Subject: " . ($context['subject'] ?? 'Ticket') . "\n";
        if ($servicesBlock) $prompt .= $servicesBlock;
        $prompt .= "\n=== CONVERSATION ===\n" . $messagesBlock;
        if ($attachmentsBlock) $prompt .= $attachmentsBlock;
        return $prompt;
    }
}
