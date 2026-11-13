<?php
namespace App\Services;

use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ProviderEndpoint
{
    public static function normalize(string $endpoint, string $driver): string
    {
        $endpoint = rtrim(trim($endpoint),'/');
        try { $host = Egress::validate($endpoint,config('cloud.provider_hosts')); }
        catch (HttpException $error) { throw ValidationException::withMessages(['endpoint'=>'Use HTTPS and an approved API host. Current hosts: '.implode(', ',config('cloud.provider_hosts')).'.']); }
        $path = parse_url($endpoint,PHP_URL_PATH) ?: '';
        $suffix = $driver === 'anthropic' ? '/messages' : '/chat/completions';
        $known = [
            'api.openai.com'=>['driver'=>'openai_compatible','base'=>'/v1'],
            'openrouter.ai'=>['driver'=>'openai_compatible','base'=>'/api/v1'],
            'generativelanguage.googleapis.com'=>['driver'=>'openai_compatible','base'=>'/v1beta/openai'],
            'api.anthropic.com'=>['driver'=>'anthropic','base'=>'/v1'],
        ];
        if (isset($known[$host]) && $known[$host]['driver'] !== $driver) throw ValidationException::withMessages(['driver'=>'This provider host requires the '.$known[$host]['driver'].' protocol.']);
        if (isset($known[$host]) && in_array($path,['',$known[$host]['base']],true)) return 'https://'.$host.$known[$host]['base'].$suffix;
        if (str_ends_with($path,$suffix)) return $endpoint;
        throw ValidationException::withMessages(['endpoint'=>'Enter the provider base URL or its complete '.$suffix.' endpoint. This protocol does not use /responses.']);
    }
}
