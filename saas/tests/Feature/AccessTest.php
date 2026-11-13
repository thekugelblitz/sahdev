<?php
namespace Tests\Feature;
use App\Services\{Egress,OrganizationService,TenantStore};
use App\Support\Totp;
use Illuminate\Support\Facades\{Crypt,DB,Queue};
use Illuminate\Support\Str;
use Tests\TestCase;
class AccessTest extends TestCase
{
    public function test_email_verification_and_org_membership_are_required(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->actingAs($this->user(false))->get('/dashboard')->assertRedirect('/verify-email');
        $this->actingAs($this->user())->withSession(['organization_id'=>$this->org])->get('/dashboard')->assertForbidden();
    }
    public function test_member_brand_scope_is_enforced_on_reads_writes_and_org_switch(): void
    {
        $other = $this->brand($this->org,'Private brand'); $staff = $this->user();
        DB::table('memberships')->insert(['organization_id'=>$this->org,'user_id'=>$staff->id,'role'=>'member']);
        DB::table('brand_memberships')->insert(['brand_id'=>$this->brand->id,'user_id'=>$staff->id,'role'=>'viewer']);
        $this->actingAs($staff)->withSession(['organization_id'=>$this->org])->get('/dashboard')->assertOk()->assertSee('Fixture Brand')->assertDontSee('Private brand');
        $this->get('/brands/'.$other->id)->assertNotFound(); $this->get('/billing')->assertForbidden(); $this->get('/team')->assertForbidden();
        $this->post('/brands/'.$this->brand->id.'/inference',[])->assertForbidden();
        $this->post('/brands/'.$this->brand->id.'/connection',['action'=>'issue'])->assertForbidden();
        $this->post('/organizations/switch',['organization_id'=>(string)Str::uuid()])->assertForbidden();
        DB::table('brand_memberships')->where('user_id',$staff->id)->delete(); $this->get('/brands/'.$this->brand->id)->assertNotFound();
    }
    public function test_customer_cannot_become_operator_through_registration_or_settings(): void
    {
        $this->actingAs($this->owner)->get('/command')->assertForbidden();
        $this->post('/command/catalog/providers',[])->assertForbidden();
        $this->get('/command/security')->assertForbidden();
        $this->assertFalse($this->owner->fresh()->is_operator);
    }
    public function test_operator_requires_recent_mfa_and_never_gets_provider_secret_back(): void
    {
        $this->owner->forceFill(['is_operator'=>true,'totp_secret'=>Totp::secret()])->save();
        $this->actingAs($this->owner)->get('/command')->assertRedirect('/command/security');
        $this->withSession(['operator_verified_at'=>time()])->get('/command')->assertOk()->assertDontSee('fixture-api-secret-do-not-display');
        $this->withSession(['operator_verified_at'=>time()-901])->post('/command/catalog/providers',[])->assertRedirect('/command/security');
    }
    public function test_totp_matches_rfc_vector_and_prevents_replay(): void
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $this->assertSame('94287082',Totp::code($secret,1,8));
        $step = intdiv(time(),30); $code = Totp::code($secret,$step);
        $this->assertSame($step,Totp::verify($secret,$code,0)); $this->assertNull(Totp::verify($secret,$code,$step));
    }
    public function test_tenant_stores_are_distinct_and_connection_state_is_cleared(): void
    {
        $other = $this->brand($this->org,'Other'); $store = app(TenantStore::class); $id = (string)Str::uuid();
        $store->run($this->brand->id,fn($db)=>$db->table('inference_records')->insert(['id'=>$id,'actor'=>'brand-a','created_at'=>now()]));
        $this->assertSame(0,$store->run($other->id,fn($db)=>$db->table('inference_records')->count()));
        $this->assertSame('brand-a',$store->run($this->brand->id,fn($db)=>$db->table('inference_records')->value('actor')));
        $this->assertSame('sqlite',DB::getDefaultConnection());
        $this->assertArrayNotHasKey('brand_'.str_replace('-','',$this->brand->id),DB::getConnections());
    }
    public function test_customer_views_escape_untrusted_names_and_have_security_headers(): void
    {
        DB::table('brands')->where('id',$this->brand->id)->update(['name'=>'<script>alert(1)</script>']);
        $response = $this->actingAs($this->owner)->get('/dashboard');
        $response->assertOk()->assertDontSee('<script>alert(1)</script>',false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;',false)->assertHeader('X-Content-Type-Options','nosniff');
        $this->assertStringContainsString("frame-ancestors 'none'",$response->headers->get('Content-Security-Policy'));
    }
    public function test_cross_org_brand_mutation_and_staff_assignment_are_denied(): void
    {
        $otherOrg = app(OrganizationService::class)->create($this->user(),'Other organization'); $otherBrand = $this->brand($otherOrg,'Other brand');
        $this->actingAs($this->owner)->withSession(['organization_id'=>$this->org])->post('/brands/'.$otherBrand->id,['name'=>'Changed','credit_mode'=>'pooled','monthly_budget'=>'100','status'=>'active','retain_content'=>0])->assertNotFound();
        $staff = $this->user(); $this->post('/team',['email'=>$staff->email,'role'=>'member','brand_role'=>'operator','brand_ids'=>[$otherBrand->id]])->assertNotFound();
        $this->assertSame('Other brand',DB::table('brands')->where('id',$otherBrand->id)->value('name'));
    }
    public function test_staff_cannot_view_another_users_ai_result(): void
    {
        $this->fund(); $r = $this->reserve(); $staff = $this->user();
        DB::table('memberships')->insert(['organization_id'=>$this->org,'user_id'=>$staff->id,'role'=>'admin']);
        $this->actingAs($staff)->withSession(['organization_id'=>$this->org])->getJson('/inference/'.$r->id)->assertNotFound();
    }
    public function test_endpoints_block_private_networks_and_unapproved_hosts(): void
    {
        foreach (['127.0.0.1','10.0.0.1','169.254.169.254','100.64.0.1','::1','::ffff:127.0.0.1','fc00::1'] as $ip) $this->assertFalse(Egress::publicIp($ip));
        $this->reject(fn()=>Egress::validate('https://evil.example/chat/completions',['openrouter.ai']),422);
        $this->reject(fn()=>Egress::validate('https://x:secret@openrouter.ai/chat/completions',['openrouter.ai']),422);
        $this->assertSame('openrouter.ai',Egress::validate('https://openrouter.ai/api/v1/chat/completions',['openrouter.ai']));
    }
}
