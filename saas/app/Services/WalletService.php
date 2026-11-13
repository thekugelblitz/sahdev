<?php
namespace App\Services;
use App\Support\Amount;
use App\Support\Audit;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** All writers lock the organization first, then modify balances, holds and evidence together. */
final class WalletService
{
    public function wallet(string $org, string $scope): object
    {
        DB::table('wallets')->insertOrIgnore(['id'=>(string)Str::uuid(),'organization_id'=>$org,'scope'=>$scope,'balance'=>0,'reserved'=>0]);
        return DB::table('wallets')->where('organization_id',$org)->where('scope',$scope)->first();
    }
    private function entry(object $wallet, int $amount, string $kind, string $reference, string $actor, ?string $brand = null): void
    {
        DB::table('wallets')->where('id',$wallet->id)->increment('balance',$amount);
        DB::table('ledger_entries')->insert(['id'=>(string)Str::uuid(),'organization_id'=>$wallet->organization_id,'wallet_id'=>$wallet->id,'brand_id'=>$brand,'kind'=>$kind,'amount'=>$amount,'balance_after'=>$wallet->balance+$amount,'reference'=>$reference,'actor'=>$actor,'created_at'=>now()]);
    }
    public function grant(string $org, int $amount, string $reference, string $actor, string $reason): void
    {
        abort_if($amount <= 0 || $amount > 1000000000000000,422,'Invalid credit grant.');
        DB::transaction(function () use ($org,$amount,$reference,$actor,$reason) {
            DB::table('organizations')->where('id',$org)->lockForUpdate()->firstOrFail();
            $old = DB::table('ledger_entries')->where('reference',$reference)->first();
            if ($old) { abort_unless($old->organization_id === $org && $old->amount == $amount,409,'Idempotency key conflict.'); return; }
            $this->entry($this->wallet($org,'pool'),$amount,'credit',$reference,$actor);
            Audit::record('wallet.credited',$actor,$org,null,['amount'=>$amount,'reference'=>$reference,'reason'=>$reason]);
        });
    }
    public function transfer(string $org, string $brandId, int $amount, bool $toBrand, string $key, string $actor): void
    {
        abort_if($amount <= 0,422,'Enter an amount above zero.');
        DB::transaction(function () use ($org,$brandId,$amount,$toBrand,$key,$actor) {
            $organization = DB::table('organizations')->where('id',$org)->lockForUpdate()->firstOrFail();
            abort_unless($organization->status === 'active',423);
            $brand = DB::table('brands')->where('organization_id',$org)->where('id',$brandId)->first() ?? abort(404);
            abort_unless($brand->credit_mode === 'allocated',422,'Allocations require allocated credit mode.');
            $reference = 'transfer:'.$org.':'.$key;
            $old = DB::table('ledger_entries')->where('reference',$reference.':out')->first();
            $pool = $this->wallet($org,'pool'); $allocated = $this->wallet($org,$brandId);
            $from = $toBrand ? $pool : $allocated; $to = $toBrand ? $allocated : $pool;
            if ($old) { abort_unless($old->wallet_id === $from->id && $old->brand_id === $brandId && $old->amount == -$amount,409); return; }
            abort_if($from->balance - $from->reserved < $amount,422,'There are not enough unreserved credits to transfer.');
            $this->entry($from,-$amount,'transfer',$reference.':out',$actor,$brandId);
            $this->entry($to,$amount,'transfer',$reference.':in',$actor,$brandId);
            Audit::record('wallet.transferred',$actor,$org,$brandId,['amount'=>$amount,'to_brand'=>$toBrand]);
        });
    }
    public function reserve(string $org, string $brandId, object $price, array $payload, string $key, string $actor, ?string $installationId = null): object
    {
        return DB::transaction(function () use ($org,$brandId,$price,$payload,$key,$actor,$installationId) {
            $organization = DB::table('organizations')->where('id',$org)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256',json_encode([$brandId,$price->model_id,$payload],JSON_THROW_ON_ERROR));
            $old = DB::table('reservations')->where('organization_id',$org)->where('idempotency_key',$key)->first();
            if ($old) { abort_unless($old->brand_id === $brandId && $old->actor === $actor && $old->installation_id === $installationId && hash_equals($old->request_hash,$hash),409,'The request key was already used for different work.'); return $old; }
            $brand = DB::table('brands')->where('organization_id',$org)->where('id',$brandId)->first() ?? abort(404);
            abort_unless($organization->status === 'active' && $brand->status === 'active',423,'AI access is suspended.');
            $limit = DB::table('plans')->where('id',$organization->plan_id)->value('concurrency_limit');
            abort_if(DB::table('reservations')->where('organization_id',$org)->whereIn('state',['reserved','dispatched','uncertain'])->count() >= $limit,429,'Your concurrent request limit has been reached.');
            abort_unless(DB::table('plan_models')->where('plan_id',$organization->plan_id)->where('model_id',$price->model_id)->exists(),403,'This model is not included in your plan.');
            // UTF-8 bytes + structural overhead is deliberately conservative, not a token estimate used for billing.
            $maxInput = strlen(json_encode($payload['messages'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)) + strlen(json_encode($payload['tools'] ?? [],JSON_THROW_ON_ERROR)) + 4096;
            $amount = Amount::charge($maxInput,(int)$price->input_rate) + Amount::charge((int)$payload['max_output_tokens'],(int)$price->output_rate);
            $wallet = $this->wallet($org,$brand->credit_mode === 'allocated' ? $brandId : 'pool');
            abort_if($wallet->balance - $wallet->reserved < $amount,402,'Not enough available credits for this request.');
            DB::table('budget_periods')->insertOrIgnore(['id'=>(string)Str::uuid(),'brand_id'=>$brandId,'month'=>now()->format('Y-m'),'spent'=>0,'reserved'=>0]);
            $period = DB::table('budget_periods')->where('brand_id',$brandId)->where('month',now()->format('Y-m'))->first();
            abort_if($period->spent + $period->reserved + $amount > $brand->monthly_budget,402,'This brand would exceed its monthly budget.');
            $id = (string)Str::uuid();
            DB::table('wallets')->where('id',$wallet->id)->increment('reserved',$amount);
            DB::table('budget_periods')->where('id',$period->id)->increment('reserved',$amount);
            DB::table('reservations')->insert(['id'=>$id,'organization_id'=>$org,'brand_id'=>$brandId,'wallet_id'=>$wallet->id,'period_id'=>$period->id,'price_id'=>$price->id,'installation_id'=>$installationId,'actor'=>$actor,'idempotency_key'=>$key,'request_hash'=>$hash,'state'=>'reserved','amount'=>$amount,'charged'=>0,'provider_cost'=>0,'payload'=>Crypt::encryptString(json_encode($payload,JSON_THROW_ON_ERROR)),'expires_at'=>now()->addMinutes(10),'created_at'=>now(),'updated_at'=>now()]);
            Audit::record('usage.reserved',$actor,$org,$brandId,['request_id'=>$id,'amount'=>$amount,'price_id'=>$price->id]);
            return DB::table('reservations')->where('id',$id)->first();
        });
    }
    public function claim(string $id): ?object
    {
        return $this->locked($id,function ($r) {
            if ($r->state !== 'reserved') return null;
            $org = DB::table('organizations')->where('id',$r->organization_id)->first();
            $brand = DB::table('brands')->where('id',$r->brand_id)->first();
            $modelId = DB::table('model_prices')->where('id',$r->price_id)->value('model_id');
            $allowed = DB::table('ai_models')->where('id',$modelId)->where('active',true)->whereNull('deleted_at')->exists() && DB::table('plan_models')->where('plan_id',$org->plan_id)->where('model_id',$modelId)->exists();
            if ($r->installation_id) $allowed = $allowed && DB::table('installations')->where('id',$r->installation_id)->where('status','active')->exists();
            else {
                $membership = DB::table('memberships')->where('organization_id',$r->organization_id)->where('user_id',$r->actor)->first();
                $allowed = $allowed && $membership && (in_array($membership->role,['owner','admin'],true) || ($membership->role==='member' && DB::table('brand_memberships')->where('brand_id',$r->brand_id)->where('user_id',$r->actor)->where('role','operator')->exists()));
            }
            if (!$allowed || $r->expires_at < now()->toDateTimeString() || $org->status !== 'active' || $brand->status !== 'active') { $this->release($r->id,'not_dispatched'); return null; }
            DB::table('reservations')->where('id',$id = $r->id)->update(['state'=>'dispatched','updated_at'=>now()]);
            return DB::table('reservations')->where('id',$id)->first();
        });
    }
    private function locked(string $id, callable $callback)
    {
        $org = DB::table('reservations')->where('id',$id)->value('organization_id') ?? abort(404);
        return DB::transaction(function () use ($org,$id,$callback) {
            DB::table('organizations')->where('id',$org)->lockForUpdate()->firstOrFail();
            return $callback(DB::table('reservations')->where('id',$id)->lockForUpdate()->first());
        });
    }
    public function settle(string $id, int $input, int $output, array $result, string $actor = 'worker'): void
    {
        $this->locked($id,function ($r) use ($input,$output,$result,$actor) {
            if ($r->state === 'settled') { abort_unless($r->input_tokens === $input && $r->output_tokens === $output,409); return; }
            abort_unless(in_array($r->state,['dispatched','uncertain'],true),409);
            $price = DB::table('model_prices')->where('id',$r->price_id)->first();
            $calculated = Amount::charge($input,$price->input_rate)+Amount::charge($output,$price->output_rate);
            $charge = min($r->amount,$calculated);
            $cost = Amount::charge($input,$price->cost_input_rate)+Amount::charge($output,$price->cost_output_rate);
            $wallet = DB::table('wallets')->where('id',$r->wallet_id)->first();
            $this->entry($wallet,-$charge,'usage','usage:'.$r->id,$actor,$r->brand_id);
            DB::table('wallets')->where('id',$r->wallet_id)->decrement('reserved',$r->amount);
            DB::table('budget_periods')->where('id',$r->period_id)->decrement('reserved',$r->amount);
            DB::table('budget_periods')->where('id',$r->period_id)->increment('spent',$charge);
            DB::table('reservations')->where('id',$r->id)->update(['state'=>'settled','charged'=>$charge,'provider_cost'=>$cost,'input_tokens'=>$input,'output_tokens'=>$output,'result'=>Crypt::encryptString(json_encode($result,JSON_THROW_ON_ERROR)),'payload'=>'','error_code'=>$calculated > $charge ? 'usage_above_reserved_limit' : null,'updated_at'=>now()]);
            Audit::record('usage.settled',$actor,$r->organization_id,$r->brand_id,['request_id'=>$r->id,'charged'=>$charge,'released'=>$r->amount-$charge,'input'=>$input,'output'=>$output]);
        });
    }
    public function release(string $id, string $reason, bool $reconciled = false, string $actor = 'worker'): void
    {
        $this->locked($id,function ($r) use ($reason,$reconciled,$actor) {
            if ($r->state === 'released') return;
            abort_unless($r->state === 'reserved' || ($reconciled && in_array($r->state,['dispatched','uncertain'],true)),409,'Provider outcome must be reconciled before releasing this hold.');
            DB::table('wallets')->where('id',$r->wallet_id)->decrement('reserved',$r->amount);
            DB::table('budget_periods')->where('id',$r->period_id)->decrement('reserved',$r->amount);
            DB::table('reservations')->where('id',$r->id)->update(['state'=>'released','payload'=>'','error_code'=>$reason,'updated_at'=>now()]);
            Audit::record('usage.released',$actor,$r->organization_id,$r->brand_id,['request_id'=>$r->id,'amount'=>$r->amount,'reason'=>$reason]);
        });
    }
    public function uncertain(string $id, string $reason): void
    {
        $this->locked($id,function ($r) use ($reason) {
            if ($r->state !== 'dispatched') return;
            DB::table('reservations')->where('id',$r->id)->update(['state'=>'uncertain','error_code'=>$reason,'updated_at'=>now()]);
            Audit::record('usage.uncertain','worker',$r->organization_id,$r->brand_id,['request_id'=>$r->id,'reason'=>$reason]);
        });
    }
    public function refundOrder(object $order): void
    {
        DB::transaction(function () use ($order) {
            DB::table('organizations')->where('id',$order->organization_id)->lockForUpdate()->firstOrFail();
            if (DB::table('ledger_entries')->where('reference','refund:'.$order->id)->exists()) return;
            $wallet = $this->wallet($order->organization_id,'pool');
            $this->entry($wallet,-$order->credits,'refund','refund:'.$order->id,'billing');
            DB::table('organizations')->where('id',$order->organization_id)->update(['status'=>'suspended','updated_at'=>now()]);
            Audit::record('billing.refunded','billing',$order->organization_id,$order->brand_id,['order_id'=>$order->id,'credits'=>$order->credits,'review_required'=>true]);
        });
    }
}
