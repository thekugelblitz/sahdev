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
        $userPromptTemplate = $settings['user_prompt_template'] ?? null;
        $prompt = $this->buildPrompt($context, $tone, $customInstruction, $settings['system_prompt'], $userPromptTemplate);

        $parts = [
            ['text' => $prompt]
        ];

        if (!empty($context['attachments_images'])) {
            foreach ($context['attachments_images'] as $img) {
                $partsArray = explode(',', $img['url'], 2);
                if (count($partsArray) === 2) {
                    $mime = str_replace(['data:', ';base64'], '', $partsArray[0]);
                    $base64 = $partsArray[1];
                    $parts[] = [
                        'inlineData' => [
                            'mimeType' => $mime,
                            'data' => $base64
                        ]
                    ];
                }
            }
        }

        $isAutopilotRaw = !empty($context['__autopilot_raw_reply__']);
        $payload = [
            'contents' => [
                [
                    'parts' => $parts
                ]
            ],
            'generationConfig' => [
                'temperature' => (float) $settings['temperature'],
                'maxOutputTokens' => (int) $settings['max_tokens'],
            ],
            'systemInstruction' => [
                'parts' => [
                    ['text' => $settings['system_prompt']]
                ]
            ]
        ];

        // Only enforce JSON if not in Autopilot Raw mode
        if (!$isAutopilotRaw) {
            $payload['generationConfig']['responseMimeType'] = 'application/json';
        }

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

        if ($isAutopilotRaw) {
            // In Autopilot mode, we return the text directly wrapped to avoid JSON parsing errors
            return ['__raw_text__' => trim($responseText)];
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
    public function getName(): string
    {
        return 'Google Gemini';
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
    private function buildPrompt(array $context, string $tone, string $customInstruction, string $systemPrompt, ?string $userPromptTemplate = null): string
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
                . str_repeat("\u2501", 40) . "\n\n";
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

    private function sanitizeMessageBody(array $msg): string
    {
        return $this->sanitizeForPrompt($msg['message'] ?? '');
    }
}
