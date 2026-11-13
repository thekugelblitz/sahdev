<?php
namespace App\Services;
use App\Support\Amount;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
final class BillingService
{
    public function api(string $action, array $params = []): array
    {
        $base = config('cloud.billing_url');
        abort_unless($base && config('cloud.billing_identifier') && config('cloud.billing_secret'),503,'HostingSpell billing is not configured.');
        $url = $base.'/includes/api.php';
        $response = app(Egress::class)->request($url,[parse_url($base,PHP_URL_HOST)])->timeout(15)->asForm()->post($url,$params+['action'=>$action,'identifier'=>config('cloud.billing_identifier'),'secret'=>config('cloud.billing_secret'),'responsetype'=>'json']);
        $body = $response->json();
        if (!$response->successful() || !is_array($body) || ($body['result'] ?? '') !== 'success') throw new \RuntimeException('Billing API request failed; reconcile before retrying a write.');
        return $body;
    }
    public function order(string $org, string $packId, ?string $brand, string $promo, string $key, string $actor): object
    {
        return DB::transaction(function () use ($org,$packId,$brand,$promo,$key,$actor) {
            $organization = DB::table('organizations')->where('id',$org)->lockForUpdate()->firstOrFail();
            abort_unless($organization->billing_client_id,422,'Your billing account must be linked by HostingSpell before buying credits.');
            $hash = hash('sha256',json_encode([$packId,$brand,strtoupper($promo)]));
            $old = DB::table('orders')->where('organization_id',$org)->where('idempotency_key',$key)->first();
            if ($old) { abort_unless(hash_equals($old->request_hash,$hash),409); return $old; }
            abort_unless($organization->status === 'active',423);
            if ($brand) abort_unless(DB::table('brands')->where('organization_id',$org)->where('id',$brand)->exists(),404);
            $pack = DB::table('credit_packs')->where('id',$packId)->where('active',true)->first() ?? abort(422,'Credit pack is unavailable.');
            abort_unless($pack->currency === config('cloud.billing_currency'),422,'Pack currency does not match billing configuration.');
            $promotion = null; $price = $pack->price_minor;
            if ($promo !== '') {
                $promotion = DB::table('promotions')->where('code',strtoupper($promo))->lockForUpdate()->first();
                abort_unless($promotion && $promotion->active && $promotion->expires_at > now()->toDateTimeString() && $promotion->redemptions < $promotion->max_redemptions,422,'Promotion is unavailable or exhausted.');
                abort_if(DB::table('orders')->where('organization_id',$org)->where('promotion_id',$promotion->id)->exists(),422,'This promotion has already been redeemed by this organization.');
                $price = intdiv($price * (100-$promotion->discount_percent)+99,100);
                DB::table('promotions')->where('id',$promotion->id)->increment('redemptions');
            }
            $id = (string)Str::uuid();
            DB::table('orders')->insert(['id'=>$id,'organization_id'=>$org,'brand_id'=>$brand,'pack_id'=>$packId,'promotion_id'=>$promotion?->id,'idempotency_key'=>$key,'request_hash'=>$hash,'state'=>'pending','description'=>$pack->name,'credits'=>$pack->credits,'price_minor'=>$price,'currency'=>$pack->currency,'created_at'=>now(),'updated_at'=>now()]);
            Audit::record('order.created',$actor,$org,$brand,['order_id'=>$id,'credits'=>$pack->credits,'price_minor'=>$price,'currency'=>$pack->currency]);
            return DB::table('orders')->where('id',$id)->first();
        });
    }
    public function createInvoice(string $id): void
    {
        $order = DB::table('orders')->where('id',$id)->firstOrFail();
        $org = DB::table('organizations')->where('id',$order->organization_id)->first();
        // Claim once; a timed-out CreateInvoice is ambiguous and MUST NOT be automatically repeated.
        if (!DB::table('orders')->where('id',$id)->where('state','pending')->update(['state'=>'creating_invoice','updated_at'=>now()])) return;
        try {
            $client = $this->api('GetClientsDetails',['clientid'=>$org->billing_client_id,'stats'=>false]);
            if ((int)($client['client']['currency'] ?? $client['currency'] ?? 0) !== (int)config('cloud.billing_currency_id')) throw new \RuntimeException('Billing currency mismatch.');
            $invoice = $this->api('CreateInvoice',['userid'=>$org->billing_client_id,'status'=>'Unpaid','sendinvoice'=>true,'date'=>now()->toDateString(),'duedate'=>now()->addDays(7)->toDateString(),'autoapplycredit'=>false,'itemdescription1'=>$order->description.' (Sahdev credits)','itemamount1'=>str_replace(',','',Amount::format($order->price_minor,2)),'itemtaxed1'=>1,'notes'=>'Sahdev order: '.$id]);
            $this->attach($id,(int)($invoice['invoiceid'] ?? 0));
        } catch (\Throwable $e) {
            DB::table('orders')->where('id',$id)->update(['state'=>'invoice_review','updated_at'=>now()]);
            Audit::record('billing.invoice_review','billing',$org->id,$order->brand_id,['order_id'=>$id]);
        }
    }
    private function verifyInvoice(object $order, int $id): array
    {
        $org = DB::table('organizations')->where('id',$order->organization_id)->first();
        $invoice = $this->api('GetInvoice',['invoiceid'=>$id]);
        abort_unless((int)($invoice['userid'] ?? 0) === (int)$org->billing_client_id && str_contains($invoice['notes'] ?? '','Sahdev order: '.$order->id) && Amount::parse((string)($invoice['subtotal'] ?? ''),2) === $order->price_minor,409,'Invoice identity or subtotal does not match this order.');
        $client = $this->api('GetClientsDetails',['clientid'=>$org->billing_client_id,'stats'=>false]);
        abort_unless((int)($client['client']['currency'] ?? $client['currency'] ?? 0) === (int)config('cloud.billing_currency_id') && $order->currency === config('cloud.billing_currency'),409,'Invoice currency does not match this order.');
        return $invoice;
    }
    public function attach(string $orderId, int $invoiceId): void
    {
        abort_if($invoiceId < 1,422);
        $order = DB::table('orders')->where('id',$orderId)->firstOrFail();
        $this->verifyInvoice($order,$invoiceId);
        DB::transaction(function () use ($order,$invoiceId) {
            DB::table('organizations')->where('id',$order->organization_id)->lockForUpdate()->firstOrFail();
            $fresh = DB::table('orders')->where('id',$order->id)->lockForUpdate()->first();
            abort_if($fresh->billing_invoice_id && $fresh->billing_invoice_id != $invoiceId,409);
            if (!$fresh->billing_invoice_id) DB::table('orders')->where('id',$order->id)->update(['billing_invoice_id'=>$invoiceId,'state'=>'awaiting_payment','updated_at'=>now()]);
            Audit::record('billing.invoice_linked','billing',$order->organization_id,$order->brand_id,['order_id'=>$order->id,'invoice_id'=>$invoiceId]);
        });
    }
    public function event(array $event, string $hash): void
    {
        $order = DB::table('orders')->where('billing_invoice_id',$event['invoice_id'])->first() ?? abort(409,'Invoice is not linked yet; retry later.');
        $invoice = $this->verifyInvoice($order,(int)$event['invoice_id']);
        DB::transaction(function () use ($order,$event,$hash,$invoice) {
            DB::table('organizations')->where('id',$order->organization_id)->lockForUpdate()->firstOrFail();
            $old = DB::table('billing_events')->where('id',$event['event_id'])->first();
            if ($old) { abort_unless(hash_equals($old->body_hash,$hash) && $old->order_id === $order->id,409); return; }
            $fresh = DB::table('orders')->where('id',$order->id)->lockForUpdate()->first();
            if ($event['kind'] === 'reversal' || in_array($invoice['status'] ?? '',['Refunded','Cancelled'],true)) {
                // Partial refunds/chargebacks need human reconciliation; immediately block further spending.
                if ($fresh->state !== 'refunded') DB::table('orders')->where('id',$order->id)->update(['state'=>'refund_review','updated_at'=>now()]);
                // Cancelling an unfunded order must not freeze unrelated, already funded brand wallets.
                if (DB::table('ledger_entries')->where('reference','order:'.$order->id)->exists()) DB::table('organizations')->where('id',$order->organization_id)->update(['status'=>'suspended','updated_at'=>now()]);
                Audit::record('billing.reversal_review','billing',$order->organization_id,$order->brand_id,['order_id'=>$order->id,'invoice_id'=>$event['invoice_id']]);
            } else {
                abort_unless(($invoice['status'] ?? '') === 'Paid' && Amount::parse((string)($invoice['balance'] ?? ''),2) === 0,409,'The invoice has not been fully paid.');
                abort_if(in_array($fresh->state,['refund_review','refunded'],true),409,'A reversed payment cannot issue new credits.');
                app(WalletService::class)->grant($order->organization_id,$order->credits,'order:'.$order->id,'billing','Paid HostingSpell invoice '.$event['invoice_id']);
                DB::table('orders')->where('id',$order->id)->update(['state'=>'paid','updated_at'=>now()]);
            }
            DB::table('billing_events')->insert(['id'=>$event['event_id'],'body_hash'=>$hash,'order_id'=>$order->id,'kind'=>$event['kind'],'created_at'=>now()]);
        });
    }
}
