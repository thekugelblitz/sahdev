<?php
namespace App\Services;
use Illuminate\Support\Facades\Crypt;
final class ProviderGateway
{
    public function generate(object $provider, array $payload): array
    {
        $request = app(Egress::class)->request($provider->endpoint,config('cloud.provider_hosts'));
        $key = Crypt::decryptString($provider->secret);
        $body = ['model'=>$payload['remote_model'],'messages'=>$payload['messages'],'max_tokens'=>$payload['max_output_tokens']];
        if (!empty($payload['tools'])) $body['tools'] = $payload['tools'];
        if ($provider->driver === 'anthropic') {
            $body = $this->anthropicBody($payload);
            $request = $request->withHeaders(['x-api-key'=>$key,'anthropic-version'=>'2023-06-01']);
        } else {
            $request = $request->withToken($key);
            if (($payload['token_parameter'] ?? 'max_tokens') === 'max_completion_tokens') { $body['max_completion_tokens'] = $body['max_tokens']; unset($body['max_tokens']); }
        }
        $response = $request->post($provider->endpoint,$body);
        // Rejections are safely distinguishable from ambiguous transport failures. Never expose vendor bodies.
        if (in_array($response->status(),[400,401,403,404,413,422,429],true)) return ['rejected'=>true,'status'=>$response->status()];
        if (!$response->successful() || strlen($response->body()) > 2097152) throw new \RuntimeException('provider_outcome_unknown');
        $data = $response->json(); $usage = $data['usage'] ?? [];
        if ($provider->driver === 'anthropic') {
            $content = ''; $calls = [];
            foreach ($data['content'] ?? [] as $part) {
                if (($part['type'] ?? '') === 'text') $content .= $part['text'];
                if (($part['type'] ?? '') === 'tool_use') $calls[] = ['id'=>$part['id'],'type'=>'function','function'=>['name'=>$part['name'],'arguments'=>json_encode($part['input'],JSON_THROW_ON_ERROR)]];
            }
            $input = isset($usage['input_tokens']) ? $usage['input_tokens'] + ($usage['cache_creation_input_tokens'] ?? 0) + ($usage['cache_read_input_tokens'] ?? 0) : null;
            $output = $usage['output_tokens'] ?? null;
        } else {
            $content = $data['choices'][0]['message']['content'] ?? '';
            $calls = $data['choices'][0]['message']['tool_calls'] ?? [];
            $input = $usage['prompt_tokens'] ?? null; $output = $usage['completion_tokens'] ?? null;
        }
        if (!is_int($input) || !is_int($output) || $input < 0 || $output < 0 || $input > 1000000 || $output > 1000000 || !is_string($content) || !is_array($calls) || count($calls) > 12 || ($content === '' && !$calls)) throw new \RuntimeException('provider_usage_or_result_invalid');
        $names = array_column(array_column($payload['tools'] ?? [],'function'),'name');
        foreach ($calls as $call) if (!is_string($call['id'] ?? null) || ($call['type'] ?? null) !== 'function' || !in_array($call['function']['name'] ?? '',$names,true) || !is_string($call['function']['arguments'] ?? null)) throw new \RuntimeException('provider_tool_result_invalid');
        return ['input'=>$input,'output'=>$output,'result'=>['content'=>$content,'tool_calls'=>$calls,'model'=>$payload['remote_model'],'usage'=>['prompt_tokens'=>$input,'completion_tokens'=>$output]]];
    }
    private function anthropicBody(array $p): array
    {
        $messages = []; $system = [];
        foreach ($p['messages'] as $m) {
            if ($m['role'] === 'system') { $system[] = $m['content']; continue; }
            if ($m['role'] === 'tool') { $messages[] = ['role'=>'user','content'=>[['type'=>'tool_result','tool_use_id'=>$m['tool_call_id'],'content'=>$m['content']]]]; continue; }
            $parts = []; if (!empty($m['content'])) $parts[] = ['type'=>'text','text'=>$m['content']];
            foreach ($m['tool_calls'] ?? [] as $call) $parts[] = ['type'=>'tool_use','id'=>$call['id'],'name'=>$call['function']['name'],'input'=>json_decode($call['function']['arguments'],false,512,JSON_THROW_ON_ERROR)];
            $messages[] = ['role'=>$m['role'],'content'=>$parts];
        }
        $body = ['model'=>$p['remote_model'],'max_tokens'=>$p['max_output_tokens'],'messages'=>$messages];
        if ($system) $body['system'] = implode("\n\n",$system);
        if (!empty($p['tools'])) $body['tools'] = array_map(fn ($tool) => ['name'=>$tool['function']['name'],'description'=>$tool['function']['description'] ?? '', 'input_schema'=>$tool['function']['parameters']],$p['tools']);
        return $body;
    }
}
