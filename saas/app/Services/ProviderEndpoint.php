<?php
namespace App\Services;

use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ProviderEndpoint
{
    public static function normalize(string $endpoint, string $driver): string
    {
        $endpoint = rtrim(trim($endpoint),'/');
        try { $host = Egress::providerHost($endpoint,true); }
        catch (HttpException $error) { throw ValidationException::withMessages(['endpoint'=>'Use a public HTTPS API hostname on port 443, without credentials, query parameters or fragments. Private, local and unresolved addresses are blocked.']); }
        $path = parse_url($endpoint,PHP_URL_PATH) ?: '';
        if ($driver === 'replicate') {
            if ($path === '' || $path === '/v1') return 'https://'.$host.'/v1';
            if (preg_match('~^/v1/models/[a-zA-Z0-9_-]+/[a-zA-Z0-9_.-]+/predictions$~D',$path)) return $endpoint;
            throw ValidationException::withMessages(['endpoint'=>'For Replicate use https://api.replicate.com/v1, or a complete /v1/models/owner/model/predictions endpoint.']);
        }
        $suffix = $driver === 'anthropic' ? '/messages' : '/chat/completions';
        $known = [
            'api.openai.com'=>['driver'=>'openai_compatible','base'=>'/v1'],
            'openrouter.ai'=>['driver'=>'openai_compatible','base'=>'/api/v1'],
            'generativelanguage.googleapis.com'=>['driver'=>'openai_compatible','base'=>'/v1beta/openai'],
            'api.anthropic.com'=>['driver'=>'anthropic','base'=>'/v1'],
        ];
        if (isset($known[$host]) && $known[$host]['driver'] !== $driver) throw ValidationException::withMessages(['driver'=>'This provider host requires the '.$known[$host]['driver'].' protocol.']);
        if ($host === 'api.replicate.com') throw ValidationException::withMessages(['driver'=>'Select Replicate Predictions for the native Replicate API.']);
        if (isset($known[$host]) && in_array($path,['',$known[$host]['base']],true)) return 'https://'.$host.$known[$host]['base'].$suffix;
        if (str_ends_with($path,$suffix)) return $endpoint;
        if (str_ends_with($path,'/responses') || str_ends_with($path,'/messages') || str_ends_with($path,'/chat/completions')) throw ValidationException::withMessages(['endpoint'=>'The endpoint does not match the selected API protocol. Use '.$suffix.' for this protocol.']);
        return $endpoint.($path === '' ? '/v1' : '').$suffix;
    }
}
