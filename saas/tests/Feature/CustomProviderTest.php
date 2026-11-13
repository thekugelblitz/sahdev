<?php
namespace Tests\Feature;
use App\Jobs\RunInference;
use App\Services\{ProviderGateway,TenantStore,WalletService};
use Illuminate\Support\Facades\{DB,Http};
use Tests\TestCase;

class CustomProviderTest extends TestCase
{
    private function runJob(string $id): void { (new RunInference($id))->handle(app(WalletService::class),app(ProviderGateway::class),app(TenantStore::class)); }
    public function test_custom_compatible_host_uses_saved_endpoint_and_reports_usage(): void
    {
        $this->fund();
        DB::table('providers')->where('id',$this->model->provider_id)->update(['endpoint'=>'https://api.cheaperinference.com/v1/chat/completions']);
        Http::fake(['api.cheaperinference.com/*'=>Http::response(['choices'=>[['message'=>['content'=>'Connected']]],'usage'=>['prompt_tokens'=>100,'completion_tokens'=>20]])]);
        $r=$this->reserve(); $this->runJob($r->id);
        $this->assertDatabaseHas('reservations',['id'=>$r->id,'state'=>'settled','charged'=>140000]);
        Http::assertSentCount(1);
    }
    public function test_replicate_posts_once_polls_only_approved_origin_and_preserves_reference(): void
    {
        $this->fund();
        DB::table('providers')->where('id',$this->model->provider_id)->update(['driver'=>'replicate','endpoint'=>'https://api.replicate.com/v1']);
        Http::fake([
            'https://api.replicate.com/v1/models/test/text/predictions'=>Http::response(['id'=>'prediction123','status'=>'processing','urls'=>['get'=>'http://127.0.0.1/private']]),
            'https://api.replicate.com/v1/predictions/prediction123'=>Http::response(['id'=>'prediction123','status'=>'succeeded','output'=>['Hello ','world'],'metrics'=>['input_token_count'=>100,'output_token_count'=>20]]),
        ]);
        $r=$this->reserve(); $this->runJob($r->id); $this->runJob($r->id);
        $this->assertDatabaseHas('reservations',['id'=>$r->id,'state'=>'settled','provider_reference'=>'prediction123','charged'=>140000]);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method()==='POST' && $request['input']['max_tokens']===100 && str_contains($request['input']['prompt'],'Hello'));
    }
    public function test_replicate_missing_usage_stays_uncertain_without_fabricated_token_charges(): void
    {
        $this->fund();
        DB::table('providers')->where('id',$this->model->provider_id)->update(['driver'=>'replicate','endpoint'=>'https://api.replicate.com/v1']);
        Http::fake(['api.replicate.com/*'=>Http::response(['id'=>'missingusage123','status'=>'succeeded','output'=>'Some text','metrics'=>['predict_time'=>1.2]])]);
        $r=$this->reserve(); $this->runJob($r->id); $this->runJob($r->id);
        $this->assertDatabaseHas('reservations',['id'=>$r->id,'state'=>'uncertain','provider_reference'=>'missingusage123','charged'=>0]);
        Http::assertSentCount(1);
    }
    public function test_dns_rebinding_blocks_dispatch_even_after_a_custom_provider_was_saved(): void
    {
        $this->fund();
        $this->app->instance(\App\Services\DnsResolver::class,new class extends \App\Services\DnsResolver { public function addresses(string $host): array { return ['10.0.0.2']; } });
        $r=$this->reserve(); $this->runJob($r->id); Http::assertNothingSent();
        $this->assertDatabaseHas('reservations',['id'=>$r->id,'state'=>'uncertain']);
    }
}
