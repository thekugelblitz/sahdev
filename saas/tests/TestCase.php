<?php
namespace Tests;
use App\Models\User;
use App\Services\{DnsResolver,OrganizationService,TenantStore,WalletService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Crypt,DB,Http,Notification};
use Illuminate\Support\Str;
abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase
{
    use RefreshDatabase;
    protected User $owner;
    protected string $org;
    protected object $brand;
    protected object $price;
    protected object $model;
    private array $tenantFiles = [];
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        return $app;
    }
    protected function setUp(): void
    {
        parent::setUp(); Notification::fake(); Http::preventStrayRequests();
        $this->app->instance(DnsResolver::class,new class extends DnsResolver { public function addresses(string $host): array { return ['8.8.8.8']; } });
        $this->seed();
        $this->owner = $this->user(); $this->org = app(OrganizationService::class)->create($this->owner,'Fixture Hosting');
        $this->brand = $this->brand($this->org,'Fixture Brand');
        $pid = (string)Str::uuid(); $mid = (string)Str::uuid(); $rate = (string)Str::uuid();
        DB::table('providers')->insert(['id'=>$pid,'name'=>'Fixture provider','driver'=>'openai_compatible','endpoint'=>'https://openrouter.ai/api/v1/chat/completions','secret'=>Crypt::encryptString('fixture-api-secret-do-not-display'),'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('ai_models')->insert(['id'=>$mid,'provider_id'=>$pid,'name'=>'Fixture text model','remote_model'=>'test/text','current_price_id'=>$rate,'max_input_bytes'=>16000,'max_output_tokens'=>2048,'supports_tools'=>true,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('model_prices')->insert(['id'=>$rate,'model_id'=>$mid,'input_rate'=>1000000000,'output_rate'=>2000000000,'cost_input_rate'=>1000000,'cost_output_rate'=>2000000,'created_at'=>now()]);
        DB::table('plan_models')->insert(['plan_id'=>DB::table('organizations')->where('id',$this->org)->value('plan_id'),'model_id'=>$mid]);
        $this->price = DB::table('model_prices')->where('id',$rate)->first(); $this->model = DB::table('ai_models')->where('id',$mid)->first();
    }
    protected function tearDown(): void
    {
        parent::tearDown(); foreach ($this->tenantFiles as $file) if (is_file($file)) unlink($file);
    }
    protected function user(bool $verified = true): User
    {
        $u = User::create(['name'=>'Fixture User','email'=>Str::uuid().'@example.com','password'=>'Fixture-password-12345']);
        if ($verified) $u->forceFill(['email_verified_at'=>now()])->save(); return $u;
    }
    protected function brand(string $org,string $name): object
    {
        $id = app(OrganizationService::class)->addBrand($org,['name'=>$name,'whmcs_url'=>'https://b'.Str::lower(Str::random(10)).'.example.com','credit_mode'=>'pooled','monthly_budget'=>100000000],$this->owner->id);
        DB::table('brands')->where('id',$id)->update(['domain_verified_at'=>now()]);
        $file = storage_path('framework/brand-'.$id.'.sqlite'); touch($file); $this->tenantFiles[] = $file;
        DB::table('brand_databases')->insert(['brand_id'=>$id,'database_name'=>$file,'username'=>'test','password'=>Crypt::encryptString('test'),'schema_version'=>1,'created_at'=>now()]);
        app(TenantStore::class)->migrate(DB::table('brand_databases')->where('brand_id',$id)->first());
        return DB::table('brands')->where('id',$id)->first();
    }
    protected function fund(int $amount = 100000000): void { app(WalletService::class)->grant($this->org,$amount,'fixture:'.Str::uuid(),'fixture','Test grant'); }
    protected function payload(): array { return ['messages'=>[['role'=>'user','content'=>'Hello']],'tools'=>[],'max_output_tokens'=>100,'provider_id'=>$this->model->provider_id,'remote_model'=>$this->model->remote_model,'token_parameter'=>'max_tokens']; }
    protected function reserve(?string $key = null, ?string $brand = null): object { return app(WalletService::class)->reserve($this->org,$brand ?? $this->brand->id,$this->price,$this->payload(),$key ?? (string)Str::uuid(),$this->owner->id); }
    protected function signedHeaders(object $i,string $secret,string $method,string $path,string $body='',?string $nonce=null): array
    {
        $time = (string)time(); $nonce = $nonce ?? bin2hex(random_bytes(16));
        return ['X-Sahdev-Installation'=>$i->id,'X-Sahdev-Timestamp'=>$time,'X-Sahdev-Nonce'=>$nonce,'X-Sahdev-Signature'=>\App\Services\InstallationService::signature($i->id,$time,$nonce,$method,$path,$body,$secret),'Accept'=>'application/json'];
    }
    protected function reject(callable $action,int $status): void
    {
        try { $action(); $this->fail('Expected request rejection.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame($status,$e->getStatusCode()); }
    }
}
