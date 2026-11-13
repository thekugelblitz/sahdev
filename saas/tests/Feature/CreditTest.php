<?php
namespace Tests\Feature;
use App\Services\{OrganizationService,WalletService};
use App\Support\Amount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
class CreditTest extends TestCase
{
    public function test_decimal_accounting_never_uses_binary_floats(): void
    {
        $this->assertSame(1234567,Amount::parse('1.234567'));
        $this->assertSame('1.234567',Amount::format(1234567));
        $this->assertSame(1,Amount::charge(1,1));
        $this->assertSame(12345,Amount::parse('123.45',2));
    }
    public function test_duplicate_credit_delivery_is_idempotent_and_conflicting_amount_rejected(): void
    {
        $wallet = app(WalletService::class);
        $wallet->grant($this->org,100,'paid:1','billing','Fixture'); $wallet->grant($this->org,100,'paid:1','billing','Fixture');
        $this->assertSame(100,(int)$wallet->wallet($this->org,'pool')->balance);
        $this->reject(fn()=>$wallet->grant($this->org,101,'paid:1','billing','Fixture'),409);
        $this->assertDatabaseCount('ledger_entries',1);
    }
    public function test_budget_failure_rolls_back_all_holds_and_queue_state(): void
    {
        $this->fund(); DB::table('brands')->where('id',$this->brand->id)->update(['monthly_budget'=>1]);
        $this->reject(fn()=>$this->reserve(),402);
        $this->assertDatabaseCount('reservations',0); $this->assertDatabaseCount('budget_periods',0);
        $this->assertSame(0,(int)app(WalletService::class)->wallet($this->org,'pool')->reserved);
    }
    public function test_balance_failure_cannot_be_bypassed_by_large_budget(): void
    {
        $this->reject(fn()=>$this->reserve(),402); $this->assertDatabaseCount('reservations',0);
    }
    public function test_held_credits_prevent_a_second_over_budget_request(): void
    {
        $this->fund(8000000); $this->reserve(); $this->reject(fn()=>$this->reserve(),402);
        $this->assertDatabaseCount('reservations',1);
    }
    public function test_duplicate_request_does_not_reserve_twice(): void
    {
        $this->fund(); $r = $this->reserve('retry-key-1234'); $again = $this->reserve('retry-key-1234');
        $this->assertSame($r->id,$again->id); $this->assertSame($r->amount,app(WalletService::class)->wallet($this->org,'pool')->reserved);
        $other = $this->brand($this->org,'Other'); $this->reject(fn()=>$this->reserve('retry-key-1234',$other->id),409);
    }
    public function test_settlement_release_and_month_rollover_preserve_invariants(): void
    {
        $this->fund(); $wallet = app(WalletService::class); $r = $this->reserve(); $wallet->claim($r->id);
        $this->travel(40)->days(); $wallet->settle($r->id,100,20,['content'=>'Done']); $wallet->settle($r->id,100,20,['content'=>'Done']);
        $this->assertSame(99860000,(int)$wallet->wallet($this->org,'pool')->balance);
        $this->assertSame(0,(int)$wallet->wallet($this->org,'pool')->reserved);
        $this->assertSame(140000,(int)DB::table('budget_periods')->where('id',$r->period_id)->value('spent'));
        $this->artisan('credits:reconcile')->assertExitCode(0);
    }
    public function test_uncertain_work_keeps_hold_until_operator_reconciliation(): void
    {
        $this->fund(); $wallet = app(WalletService::class); $r = $this->reserve(); $wallet->claim($r->id); $wallet->uncertain($r->id,'timeout');
        $this->reject(fn()=>$wallet->release($r->id,'expired'),409);
        $this->assertSame($r->amount,$wallet->wallet($this->org,'pool')->reserved);
        $wallet->release($r->id,'verified_no_charge',true,'operator:fixture'); $this->artisan('credits:reconcile')->assertExitCode(0);
    }
    public function test_brand_allocation_cannot_spend_another_brand_or_pool_balance(): void
    {
        $this->fund(); $wallet = app(WalletService::class); $service = app(OrganizationService::class);
        $service->updateBrand($this->org,$this->brand->id,['monthly_budget'=>100000000,'credit_mode'=>'allocated'],$this->owner->id);
        $this->reject(fn()=>$this->reserve(),402);
        $key = (string)Str::uuid(); $wallet->transfer($this->org,$this->brand->id,10000000,true,$key,$this->owner->id);
        $wallet->transfer($this->org,$this->brand->id,10000000,true,$key,$this->owner->id);
        $this->assertSame(90000000,(int)$wallet->wallet($this->org,'pool')->balance);
        $r = $this->reserve();
        $this->reject(fn()=>$wallet->transfer($this->org,$this->brand->id,10000000,false,(string)Str::uuid(),$this->owner->id),422);
        $this->reject(fn()=>$service->updateBrand($this->org,$this->brand->id,['monthly_budget'=>100000000,'credit_mode'=>'pooled'],$this->owner->id),409);
        $wallet->release($r->id,'cancelled'); $wallet->transfer($this->org,$this->brand->id,10000000,false,(string)Str::uuid(),$this->owner->id);
        $service->updateBrand($this->org,$this->brand->id,['monthly_budget'=>100000000,'credit_mode'=>'pooled'],$this->owner->id);
        $this->artisan('credits:reconcile')->assertExitCode(0);
    }
    public function test_model_access_suspension_and_budget_reduction_fail_closed(): void
    {
        $this->fund(); $r = $this->reserve();
        $this->reject(fn()=>app(OrganizationService::class)->updateBrand($this->org,$this->brand->id,['monthly_budget'=>0,'credit_mode'=>'pooled'],$this->owner->id),422);
        DB::table('organizations')->where('id',$this->org)->update(['status'=>'suspended']); $this->reject(fn()=>$this->reserve(),423);
        $this->assertNull(app(WalletService::class)->claim($r->id));
        DB::table('organizations')->where('id',$this->org)->update(['status'=>'active']); DB::table('plan_models')->delete();
        $this->reject(fn()=>$this->reserve(),403);
    }
    public function test_provider_overage_never_exceeds_the_approved_reservation(): void
    {
        $this->fund(); $r = $this->reserve(); $w = app(WalletService::class); $w->claim($r->id); $w->settle($r->id,100000,20000,['content'=>'Done']);
        $saved = DB::table('reservations')->where('id',$r->id)->first();
        $this->assertSame($r->amount,$saved->charged); $this->assertSame('usage_above_reserved_limit',$saved->error_code);
        $this->artisan('credits:reconcile')->assertExitCode(0);
    }
}
