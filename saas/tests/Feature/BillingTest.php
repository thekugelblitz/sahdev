<?php
namespace Tests\Feature;
use App\Services\{BillingService,WalletService};
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
use Tests\TestCase;
class BillingTest extends TestCase
{
    private function order(): object
    {
        DB::table('organizations')->where('id',$this->org)->update(['billing_client_id'=>7]); $pack = (string)Str::uuid();
        DB::table('credit_packs')->insert(['id'=>$pack,'name'=>'Fixture Pack','credits'=>100000000,'price_minor'=>10000,'currency'=>'INR','active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        config(['cloud.billing_url'=>'https://billing.hostingspell.com','cloud.billing_identifier'=>'fixture-id','cloud.billing_secret'=>'fixture-secret','cloud.billing_webhook_secret'=>str_repeat('a',64)]);
        return app(BillingService::class)->order($this->org,$pack,null,'',(string)Str::uuid(),$this->owner->id);
    }
    private function billingResponses(object $order,string $status='Paid',int $userid=7): void
    {
        Http::fake(['billing.hostingspell.com/*'=>function($r) use ($order,$status,$userid) {
            return Http::response(match($r['action']) {
                'GetClientsDetails'=>['result'=>'success','client'=>['currency'=>1]],
                'CreateInvoice'=>['result'=>'success','invoiceid'=>42],
                'GetInvoice'=>['result'=>'success','userid'=>$userid,'status'=>$status,'balance'=>$status==='Paid' ? '0.00' : '100.00','subtotal'=>'100.00','notes'=>'Sahdev order: '.$order->id],
                default=>['result'=>'error'],
            });
        }]);
    }
    private function event(string $id='event-paid-0001',string $kind='paid'): array { return ['event_id'=>$id,'invoice_id'=>42,'kind'=>$kind]; }
    public function test_paid_invoice_is_verified_and_credited_once_across_duplicate_events(): void
    {
        $order = $this->order(); $this->billingResponses($order); $billing = app(BillingService::class);
        $billing->createInvoice($order->id); $billing->event($this->event(),'hash1'); $billing->event($this->event(),'hash1'); $billing->event($this->event('event-paid-0002'),'hash2');
        $this->assertSame(100000000,app(WalletService::class)->wallet($this->org,'pool')->balance);
        $this->assertDatabaseCount('ledger_entries',1); $this->assertDatabaseCount('billing_events',2);
        $this->assertDatabaseHas('orders',['id'=>$order->id,'state'=>'paid','billing_invoice_id'=>42]);
        $this->reject(fn()=>$billing->event($this->event(),'altered'),409);
    }
    public function test_unpaid_invoice_or_wrong_customer_never_issues_credits(): void
    {
        $order = $this->order(); $this->billingResponses($order,'Unpaid'); $billing=app(BillingService::class); $billing->createInvoice($order->id);
        $this->reject(fn()=>$billing->event($this->event(),'hash'),409); $this->assertDatabaseCount('ledger_entries',0);
    }
    public function test_wrong_invoice_identity_cannot_attach(): void
    {
        $order = $this->order(); $this->billingResponses($order,'Paid',999); $billing=app(BillingService::class); $billing->createInvoice($order->id);
        $this->assertDatabaseHas('orders',['id'=>$order->id,'state'=>'invoice_review','billing_invoice_id'=>null]);
        $this->assertDatabaseCount('ledger_entries',0);
    }
    public function test_invoice_timeout_does_not_retry_create_and_requires_review(): void
    {
        $order=$this->order(); Http::fake(['billing.hostingspell.com/*'=>Http::response(['result'=>'error'],503)]);
        $billing=app(BillingService::class); $billing->createInvoice($order->id); $billing->createInvoice($order->id);
        Http::assertSentCount(1); $this->assertDatabaseHas('orders',['id'=>$order->id,'state'=>'invoice_review']);
    }
    public function test_reversal_freezes_spending_and_old_paid_event_cannot_recredit(): void
    {
        $order=$this->order(); $this->billingResponses($order); $billing=app(BillingService::class); $billing->createInvoice($order->id); $billing->event($this->event(),'paid');
        $billing->event($this->event('event-reversal-1','reversal'),'reversal');
        $this->assertDatabaseHas('organizations',['id'=>$this->org,'status'=>'suspended']);
        $this->reject(fn()=>$billing->event($this->event('event-paid-late'),'late'),409);
        $this->reject(fn()=>$this->reserve(),423); $this->assertDatabaseCount('ledger_entries',1);
    }
    public function test_full_refund_keeps_credit_history_and_blocks_reuse(): void
    {
        $order=$this->order(); $this->billingResponses($order); $billing=app(BillingService::class); $billing->createInvoice($order->id); $billing->event($this->event(),'paid');
        $wallet=app(WalletService::class); $wallet->refundOrder($order); $wallet->refundOrder($order);
        $this->assertSame(0,$wallet->wallet($this->org,'pool')->balance); $this->assertDatabaseCount('ledger_entries',2); $this->artisan('credits:reconcile')->assertExitCode(0);
    }
    public function test_webhook_signature_is_bound_to_raw_body_and_fresh_timestamp(): void
    {
        $order=$this->order(); $this->billingResponses($order); app(BillingService::class)->createInvoice($order->id);
        $this->postJson('/api/v1/billing/events',$this->event())->assertUnauthorized();
        $body=json_encode($this->event()); $time=(string)time(); $sig=hash_hmac('sha256',$time."\n".$body,config('cloud.billing_webhook_secret'));
        $this->call('POST','/api/v1/billing/events',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json','HTTP_X_SAHDEV_TIME'=>$time,'HTTP_X_SAHDEV_SIGNATURE'=>$sig],$body)->assertOk();
        $this->postJson('/api/v1/billing/events',$this->event('tampered-event'),['X-Sahdev-Time'=>$time,'X-Sahdev-Signature'=>$sig])->assertUnauthorized();
    }
}
