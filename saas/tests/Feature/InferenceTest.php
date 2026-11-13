<?php
namespace Tests\Feature;
use App\Jobs\RunInference;
use App\Services\{InferenceService,InstallationService,ProviderGateway,TenantStore,WalletService};
use Illuminate\Support\Facades\{Crypt,DB,Http,Queue};
use Illuminate\Support\Str;
use Tests\TestCase;
class InferenceTest extends TestCase
{
    private function runJob(string $id): void { (new RunInference($id))->handle(app(WalletService::class),app(ProviderGateway::class),app(TenantStore::class)); }
    private function requestInput(): array { return ['model_id'=>$this->model->id,'messages'=>[['role'=>'user','content'=>'Hello']],'max_output_tokens'=>100,'idempotency_key'=>(string)Str::uuid()]; }
    public function test_queue_payload_contains_only_id_and_duplicate_jobs_call_provider_once(): void
    {
        $this->fund();
        Http::fake(['openrouter.ai/*'=>Http::response(['choices'=>[['message'=>['content'=>'Hello back']]],'usage'=>['prompt_tokens'=>100,'completion_tokens'=>20]])]);
        $r = app(InferenceService::class)->submit($this->brand,$this->requestInput(),$this->owner->id);
        $queue = DB::table('jobs')->value('payload'); $this->assertStringNotContainsString('fixture-api-secret',$queue); $this->assertStringNotContainsString('Hello',$queue);
        $this->runJob($r->id); $this->runJob($r->id); Http::assertSentCount(1);
        $saved = DB::table('reservations')->where('id',$r->id)->first(); $this->assertSame('settled',$saved->state); $this->assertSame('', $saved->payload);
        $this->assertSame('Hello back',app(InferenceService::class)->result($saved)['result']['content']);
        $this->assertSame(140000,$saved->charged); $this->artisan('credits:reconcile')->assertExitCode(0);
        $this->assertNull(app(TenantStore::class)->run($this->brand->id,fn($db)=>$db->table('inference_records')->value('content')));
    }
    public function test_missing_usage_and_timeout_hold_credits_without_retry(): void
    {
        $this->fund(); Http::fake(['openrouter.ai/*'=>Http::response(['choices'=>[['message'=>['content'=>'Missing usage']]]])]);
        $r = $this->reserve(); $this->runJob($r->id);
        $this->assertDatabaseHas('reservations',['id'=>$r->id,'state'=>'uncertain']);
        $this->assertSame($r->amount,app(WalletService::class)->wallet($this->org,'pool')->reserved);
        $this->runJob($r->id); Http::assertSentCount(1);
    }
    public function test_provider_rejection_releases_hold_without_leaking_vendor_error(): void
    {
        $this->fund(); Http::fake(['openrouter.ai/*'=>Http::response(['error'=>['message'=>'SECRET-UPSTREAM-ERROR']],401)]);
        $r = $this->reserve(); $this->runJob($r->id);
        $saved = DB::table('reservations')->where('id',$r->id)->first();
        $this->assertSame('released',$saved->state); $this->assertSame('provider_rejected_401',$saved->error_code);
        $this->assertStringNotContainsString('SECRET-UPSTREAM-ERROR',json_encode(app(InferenceService::class)->result($saved)));
        $this->artisan('credits:reconcile')->assertExitCode(0);
    }
    public function test_queued_work_rechecks_plan_access_before_calling_provider(): void
    {
        $this->fund(); $r = $this->reserve(); DB::table('plan_models')->delete(); $this->runJob($r->id);
        Http::assertNothingSent(); $this->assertDatabaseHas('reservations',['id'=>$r->id,'state'=>'released']);
    }
    public function test_payload_rejects_external_tools_and_multimodal_content(): void
    {
        $this->fund(); $this->actingAs($this->owner)->withSession(['organization_id'=>$this->org]);
        $this->postJson('/brands/'.$this->brand->id.'/inference',['message'=>'Hi','model_id'=>$this->model->id,'idempotency_key'=>(string)Str::uuid(),'max_output_tokens'=>999999])->assertUnprocessable();
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(InferenceService::class)->submit($this->brand,$this->requestInput()+['tools'=>[['type'=>'web_search','url'=>'http://127.0.0.1']]],$this->owner->id);
    }
    public function test_anthropic_protocol_translates_function_results_and_usage(): void
    {
        $this->fund(); DB::table('providers')->where('id',$this->model->provider_id)->update(['driver'=>'anthropic','endpoint'=>'https://api.anthropic.com/v1/messages']);
        Http::fake(['api.anthropic.com/*'=>Http::response(['content'=>[['type'=>'text','text'=>'Done']],'usage'=>['input_tokens'=>90,'cache_read_input_tokens'=>10,'output_tokens'=>20]])]);
        $r = $this->reserve(); $this->runJob($r->id);
        $this->assertDatabaseHas('reservations',['id'=>$r->id,'state'=>'settled','input_tokens'=>100,'output_tokens'=>20]);
        Http::assertSent(fn($r)=>$r->hasHeader('anthropic-version','2023-06-01') && $r['model']==='test/text' && $r['max_tokens']===100);
    }
    public function test_enrollment_is_url_bound_retryable_and_secret_never_returned(): void
    {
        $issued = app(InstallationService::class)->issue($this->brand,$this->owner->id); $secret = bin2hex(random_bytes(32));
        $data = ['installation_id'=>$issued['installation_id'],'token'=>$issued['token'],'secret'=>$secret,'whmcs_url'=>$this->brand->whmcs_url,'version'=>'test'];
        $this->postJson('/api/v1/installations/enroll',array_replace($data,['whmcs_url'=>'https://wrong.example.com']))->assertUnauthorized();
        $this->postJson('/api/v1/installations/enroll',$data)->assertOk()->assertDontSee($secret);
        $this->postJson('/api/v1/installations/enroll',$data)->assertOk();
        $this->postJson('/api/v1/installations/enroll',array_replace($data,['secret'=>bin2hex(random_bytes(32))]))->assertConflict();
        $this->assertNotSame($secret,DB::table('installations')->where('id',$issued['installation_id'])->value('secret'));
    }
    public function test_signed_requests_reject_replays_body_changes_and_revoked_credentials(): void
    {
        $issued = app(InstallationService::class)->issue($this->brand,$this->owner->id); $secret = bin2hex(random_bytes(32));
        app(InstallationService::class)->enroll($issued['installation_id'],$issued['token'],$this->brand->whmcs_url,'test',$secret);
        $i = DB::table('installations')->where('id',$issued['installation_id'])->first();
        $headers = $this->signedHeaders($i,$secret,'GET','/api/v1/models');
        $this->get('/api/v1/models',$headers)->assertOk()->assertSee($this->model->id)->assertDontSee('fixture-api-secret');
        $this->get('/api/v1/models',$headers)->assertConflict();
        $headers = $this->signedHeaders($i,$secret,'GET','/api/v1/models'); $headers['X-Sahdev-Signature'] = str_repeat('0',64);
        $this->get('/api/v1/models',$headers)->assertUnauthorized();
        $data = $this->requestInput()+['actor'=>'admin:1']; $body = json_encode($data);
        $headers = $this->signedHeaders($i,$secret,'POST','/api/v1/inference',$body);
        $this->postJson('/api/v1/inference',array_replace($data,['actor'=>'admin:2']),$headers)->assertUnauthorized();
        DB::table('installations')->where('id',$i->id)->update(['status'=>'revoked']);
        $this->get('/api/v1/models',$this->signedHeaders($i,$secret,'GET','/api/v1/models'))->assertUnauthorized();
    }
    public function test_installation_cannot_read_a_different_installations_result(): void
    {
        $this->fund(); $r = $this->reserve();
        $issued = app(InstallationService::class)->issue($this->brand,$this->owner->id); $secret = bin2hex(random_bytes(32));
        app(InstallationService::class)->enroll($issued['installation_id'],$issued['token'],$this->brand->whmcs_url,'test',$secret);
        $i = DB::table('installations')->where('id',$issued['installation_id'])->first(); $path = '/api/v1/inference/'.$r->id;
        $this->get($path,$this->signedHeaders($i,$secret,'GET',$path))->assertNotFound();
    }
}
