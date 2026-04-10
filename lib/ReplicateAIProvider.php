<?php

namespace Sahdev\Lib;

/**
 * Class ReplicateAIProvider
 * Implementation of Replicate (https://replicate.com) AI integration.
 *
 * Replicate uses a Predictions API that is NOT OpenAI-compatible:
 *  - Endpoint: POST https://api.replicate.com/v1/models/{owner}/{model}/predictions
 *  - Input: {"input": {"prompt": "...", "system_prompt": "...", "temperature": 0.7, "max_tokens": 2048}}
 *  - Response: {"status": "succeeded", "output": "...", ...}
 *  - Auth: Bearer token in Authorization header
 *  - Sync mode: Prefer: wait=60 header
 */
class ReplicateAIProvider implements AIProviderInterface
{
    private $apiUrl;
    private $apiKey;
    private $lastTokenUsage = 0;
    private $lastTokenDetails = ['input' => 0, 'output' => 0];
    private $maxRetries = 2;
    private $timeout = 120;
    private $pollInterval = 2; // seconds between poll attempts
    private $maxPollAttempts = 60; // max poll attempts (2s * 60 = 120s max wait)

    public function __construct(string $apiUrl, string $apiKey)
    {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->apiKey = $apiKey;

        // If the user only provided the base URL, help them out
        // Expected format: https://api.replicate.com/v1/models/{owner}/{model}/predictions
        // If they just put the base API URL, we can't auto-complete without knowing the model
        if (empty($this->apiKey)) {
            throw new \Exception("Replicate API Key is required. Get one at https://replicate.com/account/api-tokens");
        }
    }

    /**
     * @inheritDoc
     */
    public function generateResponse(array $context, array $settings, string $tone, string $customInstruction): array
    {
        $systemMessage = $settings['system_prompt'] ?? "You are a helpful Senior Technical Support Engineer.";
        $userPromptTemplate = $settings['user_prompt_template'] ?? null;

        $prompt = $this->buildPrompt($context, $tone, $customInstruction, $userPromptTemplate);

        // Build Replicate prediction input
        // OpenAI models on Replicate accept: prompt, system_prompt, temperature, max_tokens
        $input = [
            'prompt' => $prompt,
            'system_prompt' => $systemMessage,
            'temperature' => (float) $settings['temperature'],
            'max_tokens' => (int) $settings['max_tokens'],
        ];

        $modelName = strtolower($settings['model_name'] ?? '');

        // Gemini models on Replicate do not support 'system_prompt' or 'max_tokens' natively in the same way.
        // And they use 'images' array or 'media' string.
        if (strpos($modelName, 'google') !== false || strpos($modelName, 'gemini') !== false) {
            unset($input['system_prompt']);
            
            // Map max_tokens to max_output_tokens
            if (isset($input['max_tokens'])) {
                $input['max_output_tokens'] = $input['max_tokens'];
                unset($input['max_tokens']);
            }
            
            // Re-inject system_prompt into prompt for Gemini on Replicate
            $input['prompt'] = "System Instruction: " . $systemMessage . "\n\n" . $prompt;
        }

        if (!empty($context['attachments_images'])) {
            $base64Image = $context['attachments_images'][0]['url'];
            
            if (strpos($modelName, 'google') !== false || strpos($modelName, 'gemini') !== false) {
                // Gemini on Replicate typically expects 'images' (list) or 'media' (string)
                $input['images'] = [$base64Image]; // Replicate Gemini 1.5 accepts list of images
            } else {
                // Standard default (LLaVA, MiniGPT-4, etc.)
                $input['image'] = $base64Image;
            }
        }

        $payload = [
            'input' => $input,
        ];

        $jsonPayload = json_encode($payload);

        // Retry logic
        $attempts = 0;
        $responseText = null;
        $exception = null;

        while ($attempts <= $this->maxRetries) {
            $attempts++;
            try {
                $result = $this->createPrediction($this->apiUrl, $jsonPayload);

                // Replicate returns status: starting, processing, succeeded, failed, canceled
                $status = $result['status'] ?? '';

                if ($status === 'succeeded') {
                    $responseText = $this->extractOutput($result);
                    break;
                } elseif ($status === 'failed') {
                    $error = $result['error'] ?? 'Unknown Replicate error';
                    throw new \Exception("Replicate prediction failed: " . $error);
                } elseif ($status === 'canceled') {
                    throw new \Exception("Replicate prediction was canceled.");
                } elseif (in_array($status, ['starting', 'processing'])) {
                    // Need to poll for completion
                    $getUrl = $result['urls']['get'] ?? null;
                    if (!$getUrl) {
                        throw new \Exception("Replicate returned status '{$status}' but no polling URL.");
                    }
                    $finalResult = $this->pollForCompletion($getUrl);
                    $responseText = $this->extractOutput($finalResult);
                    break;
                } else {
                    throw new \Exception("Replicate returned unexpected status: " . $status);
                }
            } catch (\Exception $e) {
                $exception = $e;
                if ($attempts <= $this->maxRetries) {
                    sleep(2);
                }
            }
        }

        if ($responseText === null && $exception) {
            throw $exception;
        }

        if ($responseText === null) {
            throw new \Exception("Replicate returned no output after all attempts.");
        }

        // Clean up response
        $responseText = trim($responseText);

        // Strip markdown code fences if present
        if (strpos($responseText, '```json') !== false) {
            $responseText = preg_replace('/```json\s*/', '', $responseText);
            $responseText = preg_replace('/```\s*$/', '', $responseText);
            $responseText = trim($responseText);
        }

        // Strip think blocks (DeepSeek-style)
        $responseText = preg_replace('/<think>.*?<\/think>/s', '', $responseText);
        $responseText = trim($responseText);

        // Estimate token usage (Replicate doesn't always provide token counts)
        $this->lastTokenDetails['input'] = (int) ceil(strlen($prompt . $systemMessage) / 4);
        $this->lastTokenDetails['output'] = (int) ceil(strlen($responseText) / 4);
        $this->lastTokenUsage = $this->lastTokenDetails['input'] + $this->lastTokenDetails['output'];

        // Try to extract and decode JSON object from within the response
        $firstBrace = strpos($responseText, '{');
        $lastBrace  = strrpos($responseText, '}');
        if ($firstBrace !== false && $lastBrace !== false && $firstBrace < $lastBrace) {
            $candidate = substr($responseText, $firstBrace, $lastBrace - $firstBrace + 1);
            $parsed    = json_decode($candidate, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                return $this->validateStructure($parsed);
            }
        }

        // Response is plain text (e.g. freeform rewrite mode — JSON was not requested).
        // Return it wrapped so callers can detect it via isset($response['__raw_text__']).
        return ['__raw_text__' => $responseText];
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
        return 'replicate';
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'Replicate AI';
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
        // Replicate doesn't have a simple /v1/models list like OpenAI.
        // Return some well-known models hosted on Replicate.
        return [
            'openai/gpt-4o-mini',
            'openai/gpt-4o',
            'meta/meta-llama-3-70b-instruct',
            'meta/meta-llama-3-8b-instruct',
            'mistralai/mistral-7b-instruct-v0.2',
        ];
    }

    /**
     * Create a prediction via Replicate API with sync mode (Prefer: wait).
     */
    private function createPrediction(string $endpoint, string $payload): array
    {
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
            'Prefer: wait=60', // Sync mode: wait up to 60s for completion
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        // Replicate requires SSL
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $result = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            throw new \Exception("Replicate API cURL Error: $error");
        }

        $decoded = json_decode($result, true);
        if (!$decoded) {
            throw new \Exception("Replicate returned invalid JSON. HTTP $httpCode. Raw: " . substr($result, 0, 200));
        }

        if ($httpCode >= 400 && $httpCode < 600) {
            $msg = $decoded['detail'] ?? $decoded['error'] ?? 'Unknown API Error';
            if (is_array($msg)) {
                $msg = json_encode($msg);
            }
            throw new \Exception("Replicate API returned HTTP $httpCode: $msg");
        }

        return $decoded;
    }

    /**
     * Poll the Replicate GET endpoint until the prediction completes.
     */
    private function pollForCompletion(string $getUrl): array
    {
        $attempts = 0;

        while ($attempts < $this->maxPollAttempts) {
            $attempts++;
            sleep($this->pollInterval);

            $ch = curl_init($getUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $this->apiKey,
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

            $result = curl_exec($ch);
            $error = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($error) {
                throw new \Exception("Replicate poll error: $error");
            }

            $decoded = json_decode($result, true);
            if (!$decoded) {
                continue; // Retry on invalid response
            }

            $status = $decoded['status'] ?? '';

            if ($status === 'succeeded') {
                return $decoded;
            } elseif ($status === 'failed') {
                $err = $decoded['error'] ?? 'Unknown error';
                throw new \Exception("Replicate prediction failed: $err");
            } elseif ($status === 'canceled') {
                throw new \Exception("Replicate prediction was canceled.");
            }
            // Still processing, continue polling...
        }

        throw new \Exception("Replicate prediction timed out after " . ($this->maxPollAttempts * $this->pollInterval) . " seconds.");
    }

    /**
     * Extract the text output from a Replicate prediction response.
     * The output field can be a string, an array of strings (streamed tokens), or other formats.
     */
    private function extractOutput(array $result): string
    {
        $output = $result['output'] ?? null;

        if ($output === null) {
            throw new \Exception("Replicate prediction succeeded but returned no output.");
        }

        // Output can be a string (complete response)
        if (is_string($output)) {
            return $output;
        }

        // Output can be an array of strings (streamed token chunks)
        if (is_array($output)) {
            // Check if it's an array of strings (token stream)
            $allStrings = true;
            foreach ($output as $item) {
                if (!is_string($item)) {
                    $allStrings = false;
                    break;
                }
            }
            if ($allStrings) {
                return implode('', $output);
            }

            // Some models return structured output
            return json_encode($output);
        }

        return (string) $output;
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

        // Custom instruction is SUPREME PRIORITY — always rendered first
        $customInstructionBlock = '';
        if (!empty($customInstruction)) {
            $customInstructionBlock = "\u{26a0}\u{fe0f} PRIORITY OVERRIDE \u{2014} ADMIN INSTRUCTION \u{26a0}\u{fe0f}\n"
                . "This instruction supersedes all other context. Re-interpret all ticket data through this lens.\n"
                . trim($customInstruction) . "\n"
                . str_repeat("\u{2501}", 40) . "\n\n";
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
        $prompt .= "  \"CLIENT_REPLY\": \"string (reply to client in Markdown — body only, no greeting or sign-off)\",\n";
        $prompt .= "  \"SCORE\": \"int (Optional 0-100 rating)\",\n";
        $prompt .= "  \"CLARITY\": \"int (Optional 0-100)\",\n";
        $prompt .= "  \"TONE_SCORE\": \"int (Optional 0-100)\",\n";
        $prompt .= "  \"COMPLETENESS\": \"int (Optional 0-100)\",\n";
        $prompt .= "  \"REPLY_NOTES\": \"string (Optional brief explanation of the score)\"\n}\n\n";
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

    /**
     * Sanitize input text before adding to the AI prompt.
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
