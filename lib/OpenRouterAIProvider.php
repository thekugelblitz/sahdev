<?php

namespace Sahdev\Lib;

require_once __DIR__ . '/AIProviderInterface.php';

/**
 * Class OpenRouterAIProvider
 *
 * High-performance integration with OpenRouter (https://openrouter.ai).
 * Supports OpenAI-standard chat completions, function/tool calling,
 * dynamic model discovery, curated presets, and real-time SSE token streaming.
 */
class OpenRouterAIProvider implements AIProviderInterface
{
    private string $apiKey;
    private string $apiUrl;
    private int $lastTokenUsage = 0;
    private array $lastTokenDetails = ['input' => 0, 'output' => 0];
    private int $timeout = 60;

    public const DEFAULT_API_URL = 'https://openrouter.ai/api/v1/chat/completions';
    public const MODELS_API_URL  = 'https://openrouter.ai/api/v1/models';

    /**
     * Curated top models with labels and descriptions for quick selection.
     */
    public const CURATED_PRESETS = [
        'anthropic/claude-3.5-sonnet' => [
            'name'        => 'Anthropic: Claude 3.5 Sonnet',
            'context'     => 200000,
            'description' => 'Gold standard for reasoning, coding, and structured tool operations.',
            'cost_in'     => 3.00,
            'cost_out'    => 15.00,
        ],
        'openai/gpt-4o' => [
            'name'        => 'OpenAI: GPT-4o',
            'context'     => 128000,
            'description' => 'Flagship multi-modal model with fast response times and high reliability.',
            'cost_in'     => 2.50,
            'cost_out'    => 10.00,
        ],
        'openai/gpt-4o-mini' => [
            'name'        => 'OpenAI: GPT-4o Mini',
            'context'     => 128000,
            'description' => 'Ultra-fast, low cost, ideal for high-volume customer chats and safe ops.',
            'cost_in'     => 0.15,
            'cost_out'    => 0.60,
        ],
        'deepseek/deepseek-chat' => [
            'name'        => 'DeepSeek: V3',
            'context'     => 64000,
            'description' => 'State-of-the-art open weights model with incredible reasoning at low cost.',
            'cost_in'     => 0.14,
            'cost_out'    => 0.28,
        ],
        'deepseek/deepseek-r1' => [
            'name'        => 'DeepSeek: R1 (Reasoning)',
            'context'     => 64000,
            'description' => 'Chain-of-thought specialized model for complex server diagnosis and root cause.',
            'cost_in'     => 0.55,
            'cost_out'    => 2.19,
        ],
        'meta-llama/llama-3.3-70b-instruct' => [
            'name'        => 'Meta: Llama 3.3 70B Instruct',
            'context'     => 128000,
            'description' => 'Open-source champion with great instruction following and speed.',
            'cost_in'     => 0.40,
            'cost_out'    => 0.40,
        ],
        'google/gemini-2.0-flash-001' => [
            'name'        => 'Google: Gemini 2.0 Flash',
            'context'     => 1000000,
            'description' => 'Massive 1M token context window, ultra-fast streaming, and tool support.',
            'cost_in'     => 0.10,
            'cost_out'    => 0.40,
        ],
    ];

    public function __construct(string $apiKey, string $apiUrl = '')
    {
        $this->apiKey = trim($apiKey);
        $this->apiUrl = !empty($apiUrl) ? rtrim($apiUrl, '/') : self::DEFAULT_API_URL;
    }

    public function getProviderType(): string
    {
        return 'openrouter';
    }

    public function getName(): string
    {
        return 'OpenRouter AI';
    }

    public function getApiUrl(): string
    {
        return $this->apiUrl;
    }

    public function getLastTokenUsage(): int
    {
        return $this->lastTokenUsage;
    }

    public function getLastTokenDetails(): array
    {
        return $this->lastTokenDetails;
    }

    /**
     * Standard ticket analysis method satisfying AIProviderInterface.
     */
    public function generateResponse(array $context, array $settings, string $tone, string $customInstruction): array
    {
        $model = !empty($settings['model_name']) ? $settings['model_name'] : 'anthropic/claude-3.5-sonnet';
        $systemPrompt = $settings['system_prompt'] ?? "You are a helpful Senior Technical Support Engineer.";
        $userPrompt = $this->buildPrompt($context, $tone, $customInstruction, $settings['user_prompt_template'] ?? null);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];

        $payload = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => (float) ($settings['temperature'] ?? 0.7),
            'max_tokens'  => (int) ($settings['max_tokens'] ?? 2048),
        ];

        if (empty($context['__autopilot_raw_reply__'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $res = $this->executeHttpRequest($this->apiUrl, $payload);
        $content = $res['choices'][0]['message']['content'] ?? '';

        if (!empty($res['usage'])) {
            $this->lastTokenDetails = [
                'input'  => (int) ($res['usage']['prompt_tokens'] ?? 0),
                'output' => (int) ($res['usage']['completion_tokens'] ?? 0),
            ];
            $this->lastTokenUsage = (int) ($res['usage']['total_tokens'] ?? 0);
        }

        if (!empty($context['__autopilot_raw_reply__'])) {
            return [
                'ROOT_CAUSE'           => '',
                'RESPONSIBILITY'       => '',
                'RISK_LEVEL'           => '',
                'INTERNAL_ACTION_PLAN' => '',
                'CLIENT_REPLY'         => trim($content),
            ];
        }

        $parsed = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
            return [
                'ROOT_CAUSE'           => $parsed['ROOT_CAUSE'] ?? '',
                'RESPONSIBILITY'       => $parsed['RESPONSIBILITY'] ?? '',
                'RISK_LEVEL'           => $parsed['RISK_LEVEL'] ?? '',
                'INTERNAL_ACTION_PLAN' => $parsed['INTERNAL_ACTION_PLAN'] ?? '',
                'CLIENT_REPLY'         => $parsed['CLIENT_REPLY'] ?? $content,
            ];
        }

        return [
            'ROOT_CAUSE'           => 'Analysis parsed from text',
            'RESPONSIBILITY'       => 'Host',
            'RISK_LEVEL'           => 'Low',
            'INTERNAL_ACTION_PLAN' => 'Review raw output',
            'CLIENT_REPLY'         => $content,
        ];
    }

    /**
     * Multi-turn chat completion with optional tools / functions.
     *
     * @param array $messages Array of ['role' => 'system'|'user'|'assistant'|'tool', 'content' => '...']
     * @param array $tools OpenAI standard function tools definition
     * @param array $settings Model settings (model_name, temperature, max_tokens)
     * @return array ['content' => string, 'tool_calls' => array, 'usage' => array]
     */
    public function generateChat(array $messages, array $tools = [], array $settings = []): array
    {
        $model = !empty($settings['model_name']) ? $settings['model_name'] : 'anthropic/claude-3.5-sonnet';

        $payload = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => (float) ($settings['temperature'] ?? 0.7),
            'max_tokens'  => (int) ($settings['max_tokens'] ?? 2048),
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $res = $this->executeHttpRequest($this->apiUrl, $payload);
        $message = $res['choices'][0]['message'] ?? [];

        if (!empty($res['usage'])) {
            $this->lastTokenDetails = [
                'input'  => (int) ($res['usage']['prompt_tokens'] ?? 0),
                'output' => (int) ($res['usage']['completion_tokens'] ?? 0),
            ];
            $this->lastTokenUsage = (int) ($res['usage']['total_tokens'] ?? 0);
        }

        return [
            'content'    => $message['content'] ?? '',
            'tool_calls' => $message['tool_calls'] ?? [],
            'usage'      => $this->lastTokenDetails,
            'model'      => $res['model'] ?? $model,
        ];
    }

    /**
     * Real-time Server-Sent Events (SSE) streaming chat completion.
     * Invokes $onChunk for every incoming token delta.
     *
     * @param array $messages
     * @param array $tools
     * @param array $settings
     * @param callable $onChunk function(string $tokenChunk, array $meta = []): void
     * @return array Accumulated result ['content' => string, 'tool_calls' => array]
     */
    public function generateChatStream(array $messages, array $tools = [], array $settings = [], callable $onChunk = null): array
    {
        $model = !empty($settings['model_name']) ? $settings['model_name'] : 'anthropic/claude-3.5-sonnet';

        $payload = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => (float) ($settings['temperature'] ?? 0.7),
            'max_tokens'  => (int) ($settings['max_tokens'] ?? 2048),
            'stream'      => true,
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $accumulatedContent = '';
        $accumulatedToolCalls = [];

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'HTTP-Referer: https://sahdev.hostingspell.com',
            'X-Title: Sahdev WHMCS AI Assistant',
        ];

        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_WRITEFUNCTION  => function ($curl, $chunk) use (&$accumulatedContent, &$accumulatedToolCalls, $onChunk) {
                $lines = explode("\n", $chunk);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (strpos($line, 'data: ') !== 0) {
                        continue;
                    }
                    $dataStr = substr($line, 6);
                    if ($dataStr === '[DONE]') {
                        if ($onChunk) {
                            $onChunk('', ['done' => true]);
                        }
                        break;
                    }

                    $json = json_decode($dataStr, true);
                    if (!is_array($json)) {
                        continue;
                    }

                    $delta = $json['choices'][0]['delta'] ?? [];
                    if (isset($delta['content']) && $delta['content'] !== '') {
                        $accumulatedContent .= $delta['content'];
                        if ($onChunk) {
                            $onChunk($delta['content'], ['type' => 'text']);
                        }
                    }

                    // Handle streamed tool calls
                    if (!empty($delta['tool_calls'])) {
                        foreach ($delta['tool_calls'] as $tc) {
                            $idx = $tc['index'] ?? 0;
                            if (!isset($accumulatedToolCalls[$idx])) {
                                $accumulatedToolCalls[$idx] = [
                                    'id'       => $tc['id'] ?? '',
                                    'type'     => $tc['type'] ?? 'function',
                                    'function' => [
                                        'name'      => $tc['function']['name'] ?? '',
                                        'arguments' => $tc['function']['arguments'] ?? '',
                                    ],
                                ];
                            } else {
                                if (!empty($tc['function']['arguments'])) {
                                    $accumulatedToolCalls[$idx]['function']['arguments'] .= $tc['function']['arguments'];
                                }
                            }
                        }
                    }
                }
                return strlen($chunk);
            },
        ]);

        curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            throw new \Exception("OpenRouter Stream Connection Error: " . $err);
        }
        if ($code >= 400) {
            throw new \Exception("OpenRouter API returned HTTP {$code}");
        }

        return [
            'content'    => $accumulatedContent,
            'tool_calls' => array_values($accumulatedToolCalls),
        ];
    }

    /**
     * Fetch dynamic model list from OpenRouter /api/v1/models endpoint.
     */
    public function getAvailableModels(string $apiKey = ''): array
    {
        $key = !empty($apiKey) ? trim($apiKey) : $this->apiKey;
        if (empty($key)) {
            return self::getPresetModelList();
        }

        $headers = [
            'Authorization: Bearer ' . $key,
            'HTTP-Referer: https://sahdev.hostingspell.com',
            'X-Title: Sahdev WHMCS AI Assistant',
        ];

        $ch = curl_init(self::MODELS_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && !empty($raw)) {
            $json = json_decode($raw, true);
            if (!empty($json['data']) && is_array($json['data'])) {
                $models = [];
                foreach ($json['data'] as $item) {
                    $id = $item['id'] ?? '';
                    if (empty($id)) continue;
                    $name = $item['name'] ?? $id;
                    $ctx = $item['context_length'] ?? 0;
                    $promptPrice = isset($item['pricing']['prompt']) ? ((float) $item['pricing']['prompt'] * 1000000) : 0;
                    $completionPrice = isset($item['pricing']['completion']) ? ((float) $item['pricing']['completion'] * 1000000) : 0;

                    $models[$id] = [
                        'id'          => $id,
                        'name'        => $name,
                        'context'     => $ctx,
                        'cost_in'     => round($promptPrice, 4),
                        'cost_out'    => round($completionPrice, 4),
                        'description' => $item['description'] ?? '',
                    ];
                }
                return $models;
            }
        }

        return self::getPresetModelList();
    }

    /**
     * Returns curated presets formatted as list.
     */
    public static function getPresetModelList(): array
    {
        $out = [];
        foreach (self::CURATED_PRESETS as $id => $info) {
            $out[$id] = [
                'id'          => $id,
                'name'        => $info['name'],
                'context'     => $info['context'],
                'cost_in'     => $info['cost_in'],
                'cost_out'    => $info['cost_out'],
                'description' => $info['description'],
            ];
        }
        return $out;
    }

    private function executeHttpRequest(string $url, array $payload): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'HTTP-Referer: https://sahdev.hostingspell.com',
            'X-Title: Sahdev WHMCS AI Assistant',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $responseRaw = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            throw new \Exception("OpenRouter Connection Error: " . $error);
        }

        $decoded = json_decode($responseRaw, true);
        if ($httpCode >= 400) {
            $msg = $decoded['error']['message'] ?? ("HTTP " . $httpCode);
            throw new \Exception("OpenRouter Error ({$httpCode}): " . $msg);
        }

        if (!is_array($decoded)) {
            throw new \Exception("Invalid JSON response received from OpenRouter.");
        }

        return $decoded;
    }

    private function buildPrompt(array $context, string $tone, string $customInstruction, ?string $template): string
    {
        $out = "TICKET CONTEXT:\n";
        $out .= "Client: " . ($context['client_name'] ?? 'Unknown') . "\n";
        $out .= "Subject: " . ($context['subject'] ?? 'No Subject') . "\n";
        $out .= "Department: " . ($context['department'] ?? 'General') . "\n\n";

        if (!empty($context['messages'])) {
            $out .= "CONVERSATION:\n";
            foreach ($context['messages'] as $m) {
                $out .= "[" . ($m['user'] ?? 'User') . "]: " . ($m['message'] ?? '') . "\n";
            }
        }

        $out .= "\nTONE: " . $tone . "\n";
        if (!empty($customInstruction)) {
            $out .= "SPECIAL INSTRUCTIONS: " . $customInstruction . "\n";
        }

        return $out;
    }
}
