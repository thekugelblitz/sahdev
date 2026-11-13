<?php
namespace Tests\Feature;
use App\Support\Totp;
use Illuminate\Support\Facades\{Crypt,DB};
use Illuminate\Support\Str;
use Tests\TestCase;
class CatalogTest extends TestCase
{
    private function operator(): void
    {
        $this->owner->forceFill(['is_operator'=>true,'totp_secret'=>Totp::secret()])->save();
        $this->actingAs($this->owner)->withSession(['operator_verified_at'=>time()]);
    }
    public function test_provider_crud_encrypts_and_redacts_credentials_and_archives_safely(): void
    {
        $this->operator(); $secret='fixture-new-provider-key';
        $v=['name'=>'New Provider','driver'=>'openai_compatible','endpoint'=>'https://openrouter.ai/api/v1/chat/completions','secret'=>$secret,'active'=>1];
        $this->post('/command/catalog/providers',$v)->assertRedirect('/command#providers');
        $p=DB::table('providers')->where('name','New Provider')->first(); $this->assertNotSame($secret,$p->secret); $this->assertSame($secret,Crypt::decryptString($p->secret));
        $v['secret']=''; $v['name']='Renamed'; $this->post('/command/catalog/providers/'.$p->id,$v)->assertRedirect();
        $this->assertSame($secret,Crypt::decryptString(DB::table('providers')->where('id',$p->id)->value('secret')));
        $this->get('/command')->assertOk()->assertDontSee($secret);
        $this->assertStringNotContainsString($secret,DB::table('audit_events')->orderByDesc('created_at')->get()->toJson());
        $this->delete('/command/catalog/providers/'.$p->id)->assertRedirect();
        $this->assertDatabaseHas('providers',['id'=>$p->id,'active'=>false]);
        $this->assertSame('',Crypt::decryptString(DB::table('providers')->where('id',$p->id)->value('secret')));
    }
    public function test_model_price_changes_do_not_reprice_existing_reservations(): void
    {
        $this->fund(); $reserved=$this->reserve(); $this->operator();
        $v=['name'=>'Updated model','provider_id'=>$this->model->provider_id,'remote_model'=>'test/text','max_input_bytes'=>16000,'max_output_tokens'=>1000,'supports_tools'=>1,'token_parameter'=>'max_tokens','input_rate'=>'9000','output_rate'=>'9000','cost_input_rate'=>'1','cost_output_rate'=>'2','cost_currency'=>'USD','active'=>1];
        $this->post('/command/catalog/models/'.$this->model->id,$v)->assertRedirect('/command#models');
        $current=DB::table('ai_models')->where('id',$this->model->id)->value('current_price_id'); $this->assertNotSame($this->price->id,$current);
        $this->assertSame($this->price->input_rate,DB::table('model_prices')->where('id',$this->price->id)->value('input_rate'));
        $w=app(\App\Services\WalletService::class); $w->claim($reserved->id); $w->settle($reserved->id,100,20,['content'=>'Done']);
        $this->assertSame(140000,DB::table('reservations')->where('id',$reserved->id)->value('charged'));
    }
    public function test_all_catalog_forms_save_and_operator_limits_apply(): void
    {
        $this->operator();
        $this->post('/command/catalog/plans',['name'=>'Team','brand_limit'=>3,'seat_limit'=>4,'concurrency_limit'=>1,'model_ids'=>[$this->model->id],'active'=>1])->assertRedirect();
        $plan=DB::table('plans')->where('name','Team')->first(); $this->assertDatabaseHas('plan_models',['plan_id'=>$plan->id,'model_id'=>$this->model->id]);
        $this->post('/command/catalog/packs',['name'=>'Pack','credits'=>'10.25','price'=>'99.50','currency'=>'INR','active'=>1])->assertRedirect();
        $this->assertDatabaseHas('credit_packs',['name'=>'Pack','credits'=>10250000,'price_minor'=>9950]);
        $this->post('/command/catalog/promotions',['code'=>'LAUNCH10','discount_percent'=>10,'max_redemptions'=>5,'expires_at'=>now()->addDay()->toDateTimeString(),'active'=>1])->assertRedirect();
        $this->post('/command/organizations/'.$this->org,['status'=>'active','plan_id'=>$plan->id,'billing_client_id'=>77,'reason'=>'Verified fixture billing identity'])->assertRedirect();
        $this->assertDatabaseHas('organizations',['id'=>$this->org,'plan_id'=>$plan->id,'billing_client_id'=>77]);
        $this->post('/command/catalog/providers',['name'=>'Blocked','driver'=>'openai_compatible','endpoint'=>'http://127.0.0.1/chat/completions','secret'=>'secret-fixture-key','active'=>1])->assertUnprocessable();
        $this->get('/command')->assertOk()->assertSee('LAUNCH10');
    }
    public function test_customer_signup_ignores_operator_flags_and_starts_with_zero_credit(): void
    {
        $email='new-owner@example.com';
        $this->post('/register',['name'=>'New owner','organization'=>'New hosting','email'=>$email,'password'=>'Test-password-12345','password_confirmation'=>'Test-password-12345','is_operator'=>1,'credits'=>9999999])->assertRedirect('/verify-email');
        $u=\App\Models\User::where('email',$email)->first(); $this->assertFalse($u->is_operator); $this->assertNull($u->email_verified_at);
        $org=DB::table('memberships')->where('user_id',$u->id)->value('organization_id'); $this->assertSame(0,DB::table('wallets')->where('organization_id',$org)->sum('balance'));
    }
}
