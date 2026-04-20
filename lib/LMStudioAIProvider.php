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

        $userContent = [];
        $userContent[] = ['type' => 'text', 'text' => $prompt];

        if (!empty($context['attachments_images'])) {
            foreach ($context['attachments_images'] as $img) {
                $userContent[] = [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => $img['url']
                    ]
                ];
            }
        }

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemMessage],
                ['role' => 'user', 'content' => $userContent]
            ],
            'temperature' => (float) $settings['temperature'],
            'max_tokens' => (int) $settings['max_tokens'],
            'stream' => false
        ];

        $isAutopilotRaw = !empty($context['__autopilot_raw_reply__']);
        // Some versions of LM Studio support 'response_format' for JSON
        if (!$isAutopilotRaw && strpos($this->apiUrl, 'v1/chat/completions') !== false) {
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

        if ($isAutopilotRaw) {
            return ['__raw_text__' => trim($responseText)];
        }

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
    public function getName(): string
    {
        return 'LM Studio (Local)';
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
            $body = $this->sanitizeForPrompt($msg['message'] ?? '');
            $entry = "[{$type}] ({$msg['date']}):\n{$body}\n\n";
            if ($used + strlen($entry) > $budget) break;
            $lines[] = $entry; $used += strlen($entry);
        }
        $messagesBlock = implode('', array_reverse($lines));
        $servicesBlock = !empty($context['services_summary'])
            ? "Services:\n" . $this->sanitizeForPrompt($context['services_summary']) . "\n"
            : '';
        $attachmentsBlock = !empty($context['attachments_text'])
            ? "\n=== ATTACHMENT CONTEXT ===\n" . $this->sanitizeForPrompt(substr($context['attachments_text'], 0, 2000)) . "\n"
            : '';
        $adminNotesBlock = !empty($context['admin_notes'])
            ? "\n=== PRIVATE ADMIN NOTES ===\n" . $this->sanitizeForPrompt($context['admin_notes']) . "\n"
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
                ['{{CUSTOM_INSTRUCTION_BLOCK}}', '{{TONE}}', '{{CLIENT_NAME}}', '{{DEPARTMENT}}', '{{SUBJECT}}', '{{SERVICES_BLOCK}}', '{{MESSAGES}}', '{{ATTACHMENTS_BLOCK}}', '{{TOOLS_OUTPUT}}'],
                [$customInstructionBlock, $tone, $context['client_name'] ?? 'Unknown Client', $context['department'] ?? 'Support', $context['subject'] ?? 'Ticket', $servicesBlock, $messagesBlock, $attachmentsBlock, $context['tools_output'] ?? ''],
                $userPromptTemplate
            );
        }

        // Fallback hardcoded prompt
        $isAutopilotRaw = !empty($context['__autopilot_raw_reply__']);
        $prompt  = $customInstructionBlock;

        if ($isAutopilotRaw) {
            $prompt .= "=== TASK ===\nAnalyze the support ticket below and generate a professional, helpful, and technically accurate reply to the client.\n\n";
            $prompt .= "=== RULES ===\n";
            $prompt .= "- OUTPUT ONLY the reply text itself.\n";
            $prompt .= "- DO NOT wrap in JSON.\n";
            $prompt .= "- DO NOT include any preamble or notes.\n";
            $prompt .= "- Use Markdown for formatting.\n";
            $prompt .= "- Include a professional greeting, but NO sign-off.\n\n";
        } else {
            $prompt .= "=== TASK ===\nAnalyze the provided technical support ticket and output ONLY a valid JSON object. No extra text.\n\n";
            $prompt .= "=== SCHEMA ===\n{\n";
            $prompt .= "  \"ROOT_CAUSE\": \"string (brief technical analysis)\",\n";
            $prompt .= "  \"RESPONSIBILITY\": \"string (Client, Host, or 3rd Party)\",\n";
            $prompt .= "  \"RISK_LEVEL\": \"string (Low, Medium, High, or Critical)\",\n";
            $prompt .= "  \"INTERNAL_ACTION_PLAN\": \"string (detailed steps for the support team)\",\n";
            $prompt .= "  \"CLIENT_REPLY\": \"string (reply to client in Markdown — including a professional greeting, but no sign-off)\",\n";
            $prompt .= "  \"SCORE\": \"int (Optional 0-100 rating)\",\n";
            $prompt .= "  \"CLARITY\": \"int (Optional 0-100)\",\n";
            $prompt .= "  \"TONE_SCORE\": \"int (Optional 0-100)\",\n";
            $prompt .= "  \"COMPLETENESS\": \"int (Optional 0-100)\",\n";
            $prompt .= "  \"REPLY_NOTES\": \"string (Optional brief explanation of the score)\"\n}\n\n";
        }
        $prompt .= "=== TONE ===\nWrite CLIENT_REPLY in a {$tone} tone.\n\n";
        $prompt .= "=== TICKET DATA ===\n";
        $prompt .= "Client: " . ($context['client_name'] ?? 'Unknown Client') . "\n";
        $prompt .= "Department: " . ($context['department'] ?? 'Support') . "\n";
        $prompt .= "Subject: " . ($context['subject'] ?? 'Ticket') . "\n";
        $prompt .= "{$servicesBlock}\n";

        if (!empty($context['tools_output'])) {
            $prompt .= $this->sanitizeForPrompt($context['tools_output']) . "\n\n";
        }

        $prompt .= "=== ACCOUNT DATA (read-only) ===\n";
        $prompt .= "Recent invoices:\n" . ($context['invoices_summary'] ?? "No invoices found.") . "\n\n";
        $prompt .= "Hosting addons:\n" . ($context['addons_summary'] ?? "No addons found.") . "\n\n";
        $prompt .= "Custom fields:\n" . ($context['custom_fields_summary'] ?? "No custom fields.") . "\n\n";

        $prompt .= "=== CONVERSATION ===\n";
        $prompt .= "{$messagesBlock}";
        $prompt .= $attachmentsBlock;
        $prompt .= $adminNotesBlock;
        return $prompt;
    }

    /**
     * Sanitize input text before adding to the AI prompt.
     * Removes HTML tags, control characters, code fences, and excess whitespace.
     */
    private function sanitizeForPrompt(string $text): string
    {
        $s = strip_tags($text);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/```[\s\S]*?```/', '[code block removed]', $s);
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
        $s = preg_replace('/\r\n|\r/', "\n", $s);
        $s = preg_replace('/\n{4,}/', "\n\n\n", $s);
        $s = preg_replace('/ {3,}/', '  ', $s);
        return trim($s);
    }
}
