<?php
namespace App\Services;
final class ReleaseService
{
    public function catalog(): array
    {
        $root = storage_path('app/releases'); $path = $root.'/release.json';
        if (!is_file($path)) return [];
        $envelope = json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        $key = base64_decode((string)config('cloud.release_public_key'),true);
        $payload = base64_decode($envelope['payload'] ?? '',true); $signature = base64_decode($envelope['signature'] ?? '',true);
        abort_unless(is_string($key) && strlen($key)===SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && is_string($signature) && strlen($signature)===SODIUM_CRYPTO_SIGN_BYTES && is_string($payload) && sodium_crypto_sign_verify_detached($signature,$payload,$key),503,'Release signature verification failed.');
        $release = json_decode($payload,true,512,JSON_THROW_ON_ERROR);
        abort_unless(($release['distribution'] ?? '') === 'thin-connector',503,'Legacy full-module distributions cannot be published.');
        if (($release['channel'] ?? '') !== 'stable' || !app(LaunchReadiness::class)->migrationComplete()) return [];
        abort_unless(is_array($release['artifacts'] ?? null),503);
        foreach ($release['artifacts'] as $kind=>$artifact) {
            abort_unless(in_array($kind,['module','android'],true) && preg_match('/^sahdev-[a-zA-Z0-9.-]+\.(zip|apk)$/D',$artifact['filename'] ?? '') && preg_match('/^[a-f0-9]{64}$/D',$artifact['sha256'] ?? '') && is_int($artifact['size'] ?? null),503);
        }
        return $release;
    }
    public function artifact(string $kind): array
    {
        $catalog = $this->catalog(); $artifact = $catalog['artifacts'][$kind] ?? abort(404);
        $root = realpath(storage_path('app/releases')); $path = realpath($root.'/'.$artifact['filename']);
        abort_unless($path && dirname($path)===$root && is_file($path) && filesize($path)===$artifact['size'] && hash_equals($artifact['sha256'],hash_file('sha256',$path)),503,'Release file verification failed.');
        if ($kind === 'module') {
            try { ConnectorArchive::verify($path); } catch (\Throwable $error) { abort(503,'Connector archive verification failed.'); }
        }
        return [$path,$artifact];
    }
}
