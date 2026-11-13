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
        $zip = new \ZipArchive(); $zip->open($file,\ZipArchive::CREATE);
        foreach (config('distribution.files') as $name) $zip->addFromString(config('distribution.prefix').$name,'fixture');
        $zip->close();
        $sign = function (string $channel = 'stable', string $distribution = 'thin-connector') use ($file,$manifest,$keys) {
            clearstatcache(true,$file);
            $payload=json_encode(['version'=>'fixture','distribution'=>$distribution,'channel'=>$channel,'artifacts'=>['module'=>['filename'=>basename($file),'size'=>filesize($file),'sha256'=>hash_file('sha256',$file)]]]);
            file_put_contents($manifest,json_encode(['payload'=>base64_encode($payload),'signature'=>base64_encode(sodium_crypto_sign_detached($payload,sodium_crypto_sign_secretkey($keys)))]));
        };
        $sign();
        try {
            $this->assertSame([],app(ReleaseService::class)->catalog(),'Incomplete migration cannot publish a stable release.');
            config(['migration.features'=>['fixture'=>['status'=>'verified']]]);
            $sign('preview'); $this->assertSame([],app(ReleaseService::class)->catalog());
            $sign('stable','legacy'); $this->reject(fn()=>app(ReleaseService::class)->catalog(),503);
            $sign();
            $this->actingAs($this->owner)->get('/downloads')->assertOk()->assertSee('Sahdev for WHMCS');
            $this->get('/downloads/module')->assertDownload(basename($file));
            // Even a correctly signed ZIP must never contain proprietary server files.
            $zip->open($file); $zip->addFromString('modules/addons/sahdev_cloud/lib/AIController.php','private methodology'); $zip->close(); $sign();
            $this->reject(fn()=>app(ReleaseService::class)->artifact('module'),503);
            file_put_contents($file,'tampered'); clearstatcache(true,$file); $this->reject(fn()=>app(ReleaseService::class)->artifact('module'),503);
            $envelope=json_decode(file_get_contents($manifest),true); $envelope['signature']=base64_encode(str_repeat('a',64)); file_put_contents($manifest,json_encode($envelope));
            $this->reject(fn()=>app(ReleaseService::class)->catalog(),503);
        } finally { unlink($manifest); unlink($file); }
    }
}
