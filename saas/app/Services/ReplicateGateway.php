<?php
namespace App\Services;

use Illuminate\Support\Facades\Crypt;

/** Native text predictions; only provider-reported token counts are billable. */
final class ReplicateGateway
{
    public function generate(object $provider, array $payload, ?callable $reference = null): array
    {
        if (!empty($payload['tools']) || !preg_match('~^[a-zA-Z0-9_-]+/[a-zA-Z0-9_.-]+$~D',$payload['remote_model'])) throw new \RuntimeException('replicate_text_model_required');
        $host = Egress::providerHost($provider->endpoint); $base = 'https://'.$host.'/v1';
        $url = $base.'/models/'.$payload['remote_model'].'/predictions';
        if (!str_ends_with($provider->endpoint,'/v1') && $provider->endpoint !== $url) throw new \RuntimeException('replicate_endpoint_model_mismatch');
        $key = Crypt::decryptString($provider->secret);
        $parameter = $payload['token_parameter'];
        if (!in_array($parameter,['max_tokens','max_output_tokens','max_new_tokens'],true)) throw new \RuntimeException('replicate_output_parameter_invalid');
        $prompt = implode("\n\n",array_map(function ($message) {
            if (!in_array($message['role'],['system','user','assistant'],true) || !empty($message['tool_calls'])) throw new \RuntimeException('replicate_tools_unsupported');
            return strtoupper($message['role']).":\n".($message['content'] ?? '');
        },$payload['messages']));
        $request = fn ($target) => app(Egress::class)->request($target,[$host])->withToken($key);
        // Never repeat POST after a timeout. A known prediction reference survives polling failures.
        $response = $request($url)->timeout(65)->withHeaders(['Prefer'=>'wait=60','Cancel-After'=>'90s'])->post($url,['input'=>['prompt'=>$prompt,$parameter=>$payload['max_output_tokens']]]);
        if (in_array($response->status(),[400,401,403,404,413,422,429],true)) return ['rejected'=>true,'status'=>$response->status()];
        if (!$response->successful()) throw new \RuntimeException('replicate_outcome_unknown');
        $data = $response->json(); $id = $data['id'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{1,100}$/D',$id)) throw new \RuntimeException('replicate_reference_invalid');
        if ($reference) $reference($id);
        for ($attempt=0; in_array($data['status'] ?? '',['starting','processing'],true) && $attempt<4; $attempt++) {
            usleep(500000);
            // Derive the URL from the approved origin and validated ID, never response.urls.get.
            $pollUrl = $base.'/predictions/'.$id;
            $response = $request($pollUrl)->timeout(5)->get($pollUrl);
            if (!$response->successful()) throw new \RuntimeException('replicate_poll_unknown');
            $data = $response->json();
            if (($data['id'] ?? null) !== $id) throw new \RuntimeException('replicate_reference_mismatch');
        }
        if (($data['status'] ?? '') !== 'succeeded') throw new \RuntimeException('replicate_outcome_requires_review');
        $output = $data['output'] ?? null;
        if (is_array($output) && array_is_list($output) && collect($output)->every(fn ($part) => is_string($part))) $output = implode('',$output);
        $inputTokens = $data['metrics']['input_token_count'] ?? null;
        $outputTokens = $data['metrics']['output_token_count'] ?? null;
        if (!is_string($output) || trim($output)==='' || strlen($output)>1048576 || !is_int($inputTokens) || !is_int($outputTokens) || $inputTokens<0 || $outputTokens<0 || $inputTokens>1000000 || $outputTokens>1000000) throw new \RuntimeException('replicate_usage_or_text_missing');
        return ['input'=>$inputTokens,'output'=>$outputTokens,'result'=>['content'=>$output,'tool_calls'=>[],'model'=>$payload['remote_model'],'provider_reference'=>$id,'usage'=>['prompt_tokens'=>$inputTokens,'completion_tokens'=>$outputTokens]]];
    }
}
