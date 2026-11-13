<?php
namespace Tests\Feature;
use App\Services\ReleaseService;
use Tests\TestCase;
class ReleaseTest extends TestCase
{
    public function test_release_signature_and_file_hash_are_verified_before_download(): void
    {
        $folder=storage_path('app/releases'); if (!is_dir($folder)) mkdir($folder,0700,true);
        $manifest=$folder.'/release.json'; $file=$folder.'/sahdev-test-fixture.zip';
        if (file_exists($manifest) || file_exists($file)) $this->markTestSkipped('An existing release is present; never overwrite it in a test.');
        $keys=sodium_crypto_sign_keypair(); config(['cloud.release_public_key'=>base64_encode(sodium_crypto_sign_publickey($keys))]);
        file_put_contents($file,'fixture');
        $payload=json_encode(['version'=>'fixture','artifacts'=>['module'=>['filename'=>basename($file),'size'=>7,'sha256'=>hash_file('sha256',$file)]]]);
        file_put_contents($manifest,json_encode(['payload'=>base64_encode($payload),'signature'=>base64_encode(sodium_crypto_sign_detached($payload,sodium_crypto_sign_secretkey($keys)))]));
        try {
            $this->actingAs($this->owner)->get('/downloads')->assertOk()->assertSee('Sahdev for WHMCS');
            $this->get('/downloads/module')->assertDownload(basename($file));
            file_put_contents($file,'tampered'); $this->reject(fn()=>app(ReleaseService::class)->artifact('module'),503);
            $envelope=json_decode(file_get_contents($manifest),true); $envelope['signature']=base64_encode(str_repeat('a',64)); file_put_contents($manifest,json_encode($envelope));
            $this->reject(fn()=>app(ReleaseService::class)->catalog(),503);
        } finally { unlink($manifest); unlink($file); }
    }
}
