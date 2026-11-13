<?php
namespace Sahdev\Lib;
require_once __DIR__.'/AIProviderInterface.php';
require_once __DIR__.'/LMStudioAIProvider.php';
require_once __DIR__.'/CloudConnector.php';

/** Reuses the module's prompt/response contracts; all provider secrets and metering stay in SaaS. */
class CloudAIProvider extends LMStudioAIProvider
{
    private $connector;
    public function __construct(?CloudConnector $connector = null)
    {
        parent::__construct('https://cloud.invalid/api/v1');
        $this->maxRetries = 0;
        $this->connector = $connector ?: new CloudConnector();
    }
    public function getProviderType(): string { return 'cloud'; }
    public function getName(): string { return 'Sahdev Cloud'; }
    public function getApiUrl(): string { return 'Sahdev Cloud (signed installation connection)'; }
    public function getAvailableModels(string $apiKey): array
    {
        $data = $this->connector->request('GET','/api/v1/models');
        return array_column($data['models'] ?? [],'id');
    }
    protected function makeRequest(string $endpoint,string $payload): array
    {
        $data = json_decode($payload,true);
        if (!preg_match('/^[a-f0-9-]{36}$/D',$data['model'] ?? '')) throw new \RuntimeException('Use the model UUID from Sahdev Cloud Command Station in Model Identifier.');
        $messages = $data['messages'];
        foreach ($messages as &$message) if (is_array($message['content'] ?? null)) {
            $text = [];
            foreach ($message['content'] as $part) {
                if (($part['type'] ?? '') !== 'text') throw new \RuntimeException('This Sahdev Cloud release supports text and function tools. Image/audio pricing is not enabled.');
                $text[] = (string)$part['text'];
            }
            $message['content'] = implode("\n",$text);
        }
        unset($message);
        $result = $this->connector->infer(['model_id'=>$data['model'],'messages'=>$messages,'tools'=>$data['tools'] ?? [],'max_output_tokens'=>(int)($data['max_tokens'] ?? 2048)]);
        return ['choices'=>[['message'=>['content'=>$result['content'] ?? '', 'tool_calls'=>$result['tool_calls'] ?? []]]],'model'=>$result['model'] ?? $data['model'],'usage'=>($result['usage'] ?? [])+['total_tokens'=>(int)($result['usage']['prompt_tokens'] ?? 0)+(int)($result['usage']['completion_tokens'] ?? 0)]];
    }
}
