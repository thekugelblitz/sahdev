<?php
namespace Tests\Feature;

use App\Services\{InstallationService,TenantStore};
use Illuminate\Support\Facades\DB;
use SahdevConnector\{Client,LegacyArchive,LegacyArchiveTransport};
use Tests\TestCase;

class LegacyArchiveTest extends TestCase
{
    public function test_archive_resumes_after_a_lost_ack_and_recovers_every_record(): void
    {
        $source = dirname(__DIR__,3).'/connector/sahdev_cloud/src/';
        foreach (['Client','LegacyArchive','LegacyArchiveTransport'] as $file) require_once $source.$file.'.php';
        $directory = storage_path('framework/legacy-'.bin2hex(random_bytes(8)));
        $recovery = $directory.'-recovered';
        mkdir($directory,0700);
        mkdir($recovery,0700);
        try {
            $secretValue = 'sentinel-not-visible-to-cloud';
            // Incompressible data forces one row to span several independently authenticated chunks.
            $row = [base64_encode('18446744073709551615'),base64_encode(random_bytes(180000)),null,base64_encode($secretValue)];
            $records = [
                ['table'=>'tblsahdev_chat_messages','ddl'=>'fixture DDL','columns'=>['id','binary','optional','extra']],
                ['row'=>$row],['row'=>$row],['end'=>'tblsahdev_chat_messages','rows'=>2],
                ['table'=>'tblsahdev_empty','ddl'=>'fixture empty DDL','columns'=>['id']],
                ['end'=>'tblsahdev_empty','rows'=>0],
            ];
            $manifest = LegacyArchive::capture($directory,$records);
            $this->assertGreaterThan(1,$manifest['chunks']);
            $this->assertSame(2,LegacyArchive::verify($directory)['rows']);
            $this->assertSame($records,iterator_to_array(LegacyArchive::records($directory),false));

            config(['cloud.thin_connector_preview'=>true]);
            $secret = str_repeat('c',64);
            $install = function (object $brand) use ($secret) {
                $service = app(InstallationService::class);
                $issued = $service->issue($brand,$this->owner->id);
                $service->enroll($issued['installation_id'],$issued['token'],$brand->whmcs_url,'fixture',$secret);
                return DB::table('installations')->find($issued['installation_id']);
            };
            $installation = $install($this->brand);
            $send = function (object $installed, string $method, string $path, array $input = []) use ($secret) {
                $body = $method === 'GET' ? '' : json_encode($input,JSON_THROW_ON_ERROR);
                $headers = $this->signedHeaders($installed,$secret,$method,$path,$body)+['Content-Type'=>'application/json'];
                return $this->call($method,$path,[],[],[],$this->transformHeadersToServerVars($headers),$body);
            };
            $base = '/api/v1/legacy-archives/'.$manifest['id'];
            $transfer = array_intersect_key($manifest,array_flip(['chunks','bytes','chain']));
            $send($installation,'POST',$base.'/start',$transfer)->assertStatus(503);
            $this->artisan('brand:provision',['brand'=>$this->brand->id])->assertSuccessful();
            $send($installation,'POST',$base.'/start',$transfer)->assertOk()->assertJsonPath('received_chunks',0);
            $chunks = iterator_to_array(LegacyArchive::chunks($directory.'/chunks'),false);
            $send($installation,'POST',$base.'/chunks/2',['ciphertext'=>$chunks[1]])->assertConflict();
            $send($installation,'POST',$base.'/complete')->assertConflict();
            $send($installation,'GET',$base.'/chunks/1')->assertNotFound();
            $send($installation,'POST',$base.'/chunks/1',['ciphertext'=>$chunks[0]])->assertOk();
            // The client did not see the first acknowledgement. The same manifest resumes at chunk two.
            $send($installation,'POST',$base.'/chunks/1',['ciphertext'=>$chunks[1]])->assertConflict();
            $send($installation,'POST',$base.'/chunks/1',['ciphertext'=>$chunks[0]])->assertOk()->assertJsonPath('received_chunks',1);
            $client = new class(fn ($method,$path,$input) => $send($installation,$method,$path,$input)->assertOk()->json()) extends Client {
                public array $paths = [];
                private $send;
                public function __construct(callable $send) { $this->send = $send; }
                public function request(string $method,string $path,array $data=[],bool $signed=true): array
                {
                    $this->paths[] = $path;
                    return ($this->send)($method,$path,$data);
                }
            };
            (new LegacyArchiveTransport($client))->upload($directory);
            $this->assertNotContains($base.'/chunks/1',$client->paths);
            $send($installation,'POST',$base.'/complete')->assertOk()->assertJsonPath('completed',true);
            $this->getJson($base.'/chunks/1')->assertUnauthorized();
            $other = $this->brand($this->org,'Other database');
            $this->artisan('brand:provision',['brand'=>$other->id])->assertSuccessful();
            $send($install($other),'GET',$base.'/chunks/1')->assertNotFound();

            copy($directory.'/manifest.json',$recovery.'/manifest.json');
            copy($directory.'/key',$recovery.'/key');
            $this->assertSame(2,(new LegacyArchiveTransport($client))->recover($recovery)['rows']);
            $this->assertSame($records,iterator_to_array(LegacyArchive::records($recovery),false));
            app(TenantStore::class)->run($this->brand->id,function ($db) use ($manifest,$secretValue) {
                $this->assertSame($manifest['chunks'],$db->table('legacy_archive_chunks')->count());
                $this->assertStringNotContainsString($secretValue,$db->table('legacy_archive_chunks')->value('ciphertext'));
            });
            file_put_contents($recovery.'/chunks',implode("\n",array_reverse($chunks))."\n");
            $this->expectException(\RuntimeException::class);
            LegacyArchive::verify($recovery);
        } finally {
            foreach ([$directory,$recovery] as $folder) {
                foreach (['key','manifest.json','chunks'] as $file) if (is_file($folder.'/'.$file)) unlink($folder.'/'.$file);
                rmdir($folder);
            }
        }
    }
}
