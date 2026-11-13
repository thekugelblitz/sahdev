<?php
/** Destructive ONLY to a new loopback database with the explicitly guarded test name. */
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\User;
use App\Services\{OrganizationService,WalletService,TenantStore};
use Illuminate\Support\Facades\{Artisan,DB};
use Illuminate\Support\Str;

$cfg=config('database.connections.pgsql');
if (config('database.default')!=='pgsql' || !in_array($cfg['host'],['localhost','127.0.0.1'],true) || !preg_match('/^sahdev_cloud_test(?:_[a-z0-9_]+)?$/D',$cfg['database'])) throw new RuntimeException('Use a disposable loopback sahdev_cloud_test database.');
$check=function($condition,$message) { if (!$condition) throw new RuntimeException($message); echo 'PASS '.$message."\n"; };
$mode=$argv[1] ?? 'parent';
if ($mode==='child') {
    $fixture=json_decode(file_get_contents($argv[2]),true); $index=$argv[3];
    file_put_contents($argv[2].'.ready'.$index,'ready'); $deadline=microtime(true)+15;
    while (!file_exists($argv[2].'.go')) { if (microtime(true)>$deadline) throw new RuntimeException('Barrier timed out.'); usleep(20000); }
    try {
        $wallet=app(WalletService::class);
        if ($fixture['operation']==='reserve') {
            $price=DB::table('model_prices')->where('id',$fixture['price'])->first();
            $r=$wallet->reserve($fixture['org'],$fixture['brand'],$price,$fixture['payload'],$fixture['same_key'] ? 'same-request-key' : 'request-key-'.$index,$fixture['actor']);
            $out=['status'=>200,'id'=>$r->id];
        } elseif ($fixture['operation']==='claim') $out=['status'=>200,'claimed'=>(bool)$wallet->claim($fixture['reservation'])];
        else { $wallet->settle($fixture['reservation'],100,20,['content'=>'Done']); $out=['status'=>200]; }
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { $out=['status'=>$e->getStatusCode()]; }
    echo json_encode($out); exit;
}
Artisan::call('migrate:fresh',['--force'=>true]); Artisan::call('db:seed',['--force'=>true]);
$user=User::create(['name'=>'Race test','email'=>'race@example.com','password'=>Str::random(40)]); $user->forceFill(['email_verified_at'=>now()])->save();
$org=app(OrganizationService::class)->create($user,'Race fixture'); DB::table('plans')->update(['concurrency_limit'=>10]);
$brand=app(OrganizationService::class)->addBrand($org,['name'=>'A','whmcs_url'=>'https://a.example.com','credit_mode'=>'pooled','monthly_budget'=>100000000],$user->id);
$provider=(string)Str::uuid(); $model=(string)Str::uuid(); $price=(string)Str::uuid();
DB::table('providers')->insert(['id'=>$provider,'name'=>'Fixture','driver'=>'openai_compatible','endpoint'=>'https://openrouter.ai/api/v1/chat/completions','secret'=>'unused','active'=>true,'created_at'=>now(),'updated_at'=>now()]);
DB::table('ai_models')->insert(['id'=>$model,'provider_id'=>$provider,'name'=>'Fixture','remote_model'=>'fixture','active'=>true,'current_price_id'=>$price,'created_at'=>now(),'updated_at'=>now()]);
DB::table('model_prices')->insert(['id'=>$price,'model_id'=>$model,'input_rate'=>1000000000,'output_rate'=>2000000000,'cost_input_rate'=>1,'cost_output_rate'=>1,'created_at'=>now()]);
DB::table('plan_models')->insert(['plan_id'=>DB::table('organizations')->where('id',$org)->value('plan_id'),'model_id'=>$model]);
$wallet=app(WalletService::class); $wallet->grant($org,8000000,'fixture-grant','fixture','Race fixture');
$fixture=['org'=>$org,'brand'=>$brand,'actor'=>$user->id,'price'=>$price,'same_key'=>false,'operation'=>'reserve','payload'=>['messages'=>[['role'=>'user','content'=>'hello']],'tools'=>[],'max_output_tokens'=>100]];
$race=function(array $fixture) {
    $file=storage_path('framework/race-'.Str::uuid().'.json'); file_put_contents($file,json_encode($fixture)); $children=[];
    for ($i=0;$i<2;$i++) { $pipes=[]; $process=proc_open([PHP_BINARY,__FILE__,'child',$file,(string)$i],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,base_path()); fclose($pipes[0]); $children[]=[$process,$pipes]; }
    $deadline=microtime(true)+15;
    while (!is_file($file.'.ready0') || !is_file($file.'.ready1')) { if (microtime(true)>$deadline) throw new RuntimeException('Children did not become ready.'); usleep(20000); }
    file_put_contents($file.'.go','go'); $results=[];
    foreach ($children as [$process,$pipes]) { $stdout=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($process); if ($exit!==0) throw new RuntimeException('Race child failed: '.$stderr.$stdout); $results[]=json_decode($stdout,true,512,JSON_THROW_ON_ERROR); }
    foreach ([$file,$file.'.go',$file.'.ready0',$file.'.ready1'] as $path) unlink($path); return $results;
};
$results=$race($fixture); $statuses=array_column($results,'status'); sort($statuses);
$check($statuses===[200,402],'competing processes cannot overspend the wallet');
$r=DB::table('reservations')->first(); $check(DB::table('reservations')->count()===1,'only one reservation persisted');
$results=$race($fixture); // Wallet is still held: both requests are denied or idempotently returned.
$check(DB::table('reservations')->count()===1,'retries never add a second hold');
$claimed=$race(array_replace($fixture,['operation'=>'claim','reservation'=>$r->id]));
$check(count(array_filter($claimed,fn($v)=>$v['claimed']))===1,'a queued request is claimed by exactly one worker');
$race(array_replace($fixture,['operation'=>'settle','reservation'=>$r->id]));
$check(DB::table('ledger_entries')->where('kind','usage')->count()===1,'concurrent settlement writes one debit');
$check(Artisan::call('credits:reconcile')===0,'PostgreSQL ledger and holds reconcile');
$wallet->grant($org,20000000,'second-grant','fixture','Second race');
$duplicate=$race(array_replace($fixture,['same_key'=>true]));
$check($duplicate[0]['id']===$duplicate[1]['id'],'concurrent duplicate request keys return the same reservation');
$second=DB::table('reservations')->where('id',$duplicate[0]['id'])->first(); $wallet->claim($second->id);
$before=$wallet->wallet($org,'pool');
$inject=true;
DB::listen(function($q) use (&$inject) { if ($inject && str_contains($q->sql,'insert into "ledger_entries"')) { $inject=false; throw new RuntimeException('Injected failure after ledger insert'); } });
try { $wallet->settle($second->id,100,20,['content'=>'Done']); throw new RuntimeException('Expected injected failure'); } catch (RuntimeException $e) { if ($e->getMessage()!=='Injected failure after ledger insert') throw $e; }
$after=$wallet->wallet($org,'pool');
$check($before->balance===$after->balance && $before->reserved===$after->reserved && DB::table('reservations')->where('id',$second->id)->value('state')==='dispatched','injected settlement failure rolls back debit, ledger and state atomically');
$wallet->settle($second->id,100,20,['content'=>'Done']);
if (getenv('SAHDEV_PROVISION_DSN')) {
    $other=app(OrganizationService::class)->addBrand($org,['name'=>'B','whmcs_url'=>'https://b.example.com','credit_mode'=>'pooled','monthly_budget'=>100000000],$user->id);
    $check(Artisan::call('brand:provision',['brand'=>$brand])===0,'brand A PostgreSQL database provisions');
    $check(Artisan::call('brand:provision',['brand'=>$other])===0,'brand B PostgreSQL database provisions');
    $a=DB::table('brand_databases')->where('brand_id',$brand)->first(); $b=DB::table('brand_databases')->where('brand_id',$other)->first();
    $dsn='pgsql:host='.config('cloud.tenant_host').';port='.config('cloud.tenant_port').';dbname='.$b->database_name;
    $denied=false; try { new PDO($dsn,$a->username,Illuminate\Support\Facades\Crypt::decryptString($a->password)); } catch (PDOException $e) { $denied=true; }
    $check($denied,'brand A database credentials cannot connect to brand B');
    $check(Artisan::call('brand:provision',['brand'=>$brand])===0,'brand provisioning retries are idempotent');
}
$check(Artisan::call('credits:reconcile')===0,'all final accounting invariants reconcile');
