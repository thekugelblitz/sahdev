<?php
namespace Tests\Feature;

use App\Services\LaunchReadiness;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicSiteTest extends TestCase
{
    public function test_public_pages_show_real_prices_and_do_not_open_an_incomplete_release(): void
    {
        $this->get('/')->assertOk()->assertSee('RELEASE PREVIEW')->assertSee('499');
        $this->get('/pricing')->assertOk()->assertSee('Starter')->assertSee('1,499')->assertSee('3,999');
        $this->get('/installation')->assertOk()->assertSee('Acceptance preview.')->assertSee('still release blockers');
        $this->get('/register')->assertOk()->assertSee('Public signup and production downloads are not open yet.');
        $count = DB::table('users')->count(); $this->post('/register',['name'=>'No account'])->assertStatus(503);
        $this->assertDatabaseCount('users',$count); $this->assertFalse(app(LaunchReadiness::class)->ready());
        $this->actingAs($this->owner)->from('/billing')->post('/billing/checkout',[])->assertRedirect('/billing')->assertSessionHasErrors('checkout');
        $this->assertDatabaseCount('orders',0);
        $this->actingAs($this->owner)->get('/command/release')->assertForbidden();
        $this->owner->forceFill(['is_operator'=>true])->save();
        $this->get('/command/release')->assertOk()->assertSee('Public release readiness.')->assertDontSee('fixture-api-secret');
    }
    public function test_catalog_seed_preserves_operator_prices_and_disabled_packs(): void
    {
        $id = DB::table('credit_packs')->where('name','Starter')->value('id');
        DB::table('credit_packs')->where('id',$id)->update(['price_minor'=>12345,'active'=>false]); $this->seed();
        $this->assertDatabaseHas('credit_packs',['id'=>$id,'price_minor'=>12345,'active'=>false]);
        $this->get('/pricing')->assertOk()->assertDontSee('Starter');
    }
    public function test_operator_can_create_own_acceptance_workspace_without_public_signup(): void
    {
        $this->actingAs($this->owner)->post('/command/workspace',['name'=>'Not an operator'])->assertForbidden();
        $operator=$this->user(); $operator->forceFill(['is_operator'=>true])->save();
        $this->actingAs($operator)->get('/command')->assertOk()->assertSee('Create your testing workspace');
        $before=DB::table('organizations')->count();
        $this->post('/command/workspace',['name'=>'Staging'])->assertRedirect('/dashboard');
        $this->post('/command/workspace',['name'=>'Duplicate click'])->assertRedirect('/dashboard');
        $this->assertDatabaseCount('organizations',$before+1);
        $this->get('/dashboard')->assertOk()->assertSee('Staging');
        $org=DB::table('memberships')->where('user_id',$operator->id)->value('organization_id');
        $this->assertSame(0,(int)DB::table('wallets')->where('organization_id',$org)->sum('balance'));
    }
}
