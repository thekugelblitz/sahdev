<?php
namespace App\Http\Controllers;
use App\Services\{ProviderEndpoint,WalletService};
use App\Support\{Amount,Audit};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
final class OperatorController
{
    public function index()
    {
        $providers = DB::table('providers')->whereNull('deleted_at')->get(['id','name','driver','endpoint','active']);
        $models = DB::table('ai_models')->whereNull('deleted_at')->get();
        foreach ($models as $m) $m->price = DB::table('model_prices')->where('id',$m->current_price_id)->first();
        return view('ops',[
            'providers'=>$providers,'models'=>$models,'plans'=>DB::table('plans')->get(),'packs'=>DB::table('credit_packs')->get(),'promotions'=>DB::table('promotions')->get(),
            'organizations'=>DB::table('organizations')->get(),'brands'=>DB::table('brands')->get(),
            'brand_usage'=>DB::table('reservations')->selectRaw('brand_id, COUNT(*) AS requests, SUM(charged) AS credits')->groupBy('brand_id')->get()->keyBy('brand_id'),
            'wallets'=>DB::table('wallets')->get(),'usage'=>DB::table('reservations')->selectRaw('state, COUNT(*) AS count, SUM(charged) AS charged')->groupBy('state')->get(),
            'reviews'=>DB::table('reservations')->where('state','uncertain')->orderByDesc('created_at')->limit(50)->get(['id','organization_id','brand_id','amount','error_code','created_at']),
            'orders'=>DB::table('orders')->orderByDesc('created_at')->limit(100)->get(),
            'events'=>DB::table('audit_events')->orderByDesc('created_at')->limit(100)->get(),
            'plan_models'=>DB::table('plan_models')->get(),
            'jobs'=>DB::table('jobs')->count(),'failed_jobs'=>DB::table('failed_jobs')->count(),
        ]);
    }
    public function save(Request $r,string $kind,?string $id = null)
    {
        abort_unless(in_array($kind,['providers','models','plans','packs','promotions'],true),404);
        $common = ['name'=>'required|string|max:100','active'=>'required|boolean'];
        $rules = match($kind) {
            'providers'=>$common+['driver'=>'required|in:openai_compatible,anthropic','endpoint'=>'required|string|max:240','secret'=>($id ? 'nullable' : 'required').'|string|min:12|max:4000'],
            'models'=>$common+['provider_id'=>'required|uuid|exists:providers,id','remote_model'=>'required|string|regex:~^[a-zA-Z0-9:/._-]{1,160}$~','max_input_bytes'=>'required|integer|min:1000|max:180000','max_output_tokens'=>'required|integer|min:1|max:16384','supports_tools'=>'required|boolean','token_parameter'=>'required|in:max_tokens,max_completion_tokens','input_rate'=>'required|string','output_rate'=>'required|string','cost_input_rate'=>'required|string','cost_output_rate'=>'required|string','cost_currency'=>'required|in:USD,INR,EUR'],
            'plans'=>$common+['brand_limit'=>'required|integer|min:1|max:1000','seat_limit'=>'required|integer|min:1|max:1000','concurrency_limit'=>'required|integer|min:1|max:50','model_ids'=>'sometimes|array','model_ids.*'=>'uuid|exists:ai_models,id'],
            'packs'=>$common+['credits'=>'required|string','price'=>'required|string','currency'=>'required|in:INR,USD,EUR'],
            'promotions'=>['code'=>'required|string|regex:/^[A-Z0-9_-]{3,40}$/','discount_percent'=>'required|integer|min:1|max:95','max_redemptions'=>'required|integer|min:1|max:100000','expires_at'=>'required|date','active'=>'required|boolean'],
        };
        $v = $r->validate($rules);
        $table = match($kind) {'models'=>'ai_models','packs'=>'credit_packs',default=>$kind};
        if ($kind === 'providers') {
            $v['endpoint'] = ProviderEndpoint::normalize($v['endpoint'],$v['driver']);
            if (!empty($v['secret'])) $v['secret'] = Crypt::encryptString($v['secret']); else unset($v['secret']);
        }
        if ($kind === 'packs') { $v['credits'] = Amount::parse($v['credits']); $v['price_minor'] = Amount::parse($v['price'],2); unset($v['price']); abort_if($v['credits'] <= 0 || $v['price_minor'] <= 0,422); }
        $creatingProvider = $kind === 'providers' && $id === null;
        $id = DB::transaction(function () use ($r,$kind,$table,$id,$v) {
            $exists = $id ? DB::table($table)->where('id',$id)->lockForUpdate()->first() ?? abort(404) : null;
            if ($exists && property_exists($exists,'deleted_at')) abort_if($exists->deleted_at !== null,409);
            $id = $id ?: (string)Str::uuid(); $prices = []; $modelIds = $v['model_ids'] ?? []; unset($v['model_ids']);
            if ($kind === 'models') {
                $provider = DB::table('providers')->where('id',$v['provider_id'])->whereNull('deleted_at')->first() ?? abort(422);
                foreach (['input_rate','output_rate','cost_input_rate','cost_output_rate'] as $key) { $prices[$key] = Amount::parse($v[$key]); abort_if($prices[$key] > 1000000000000,422,'Rate exceeds supported precision.'); unset($v[$key]); }
                $prices['cost_currency'] = $v['cost_currency']; unset($v['cost_currency']);
            }
            if ($kind === 'promotions' && $exists) abort_if($v['max_redemptions'] < $exists->redemptions,422,'Cannot reduce the limit below existing redemptions.');
            if ($exists) DB::table($table)->where('id',$id)->update($v+['updated_at'=>now()]);
            else DB::table($table)->insert($v+['id'=>$id,'created_at'=>now(),'updated_at'=>now()]);
            if ($kind === 'models') {
                $priceId = (string)Str::uuid(); DB::table('model_prices')->insert($prices+['id'=>$priceId,'model_id'=>$id,'created_at'=>now()]);
                DB::table('ai_models')->where('id',$id)->update(['current_price_id'=>$priceId]);
            }
            if ($kind === 'plans') {
                DB::table('plan_models')->where('plan_id',$id)->delete();
                foreach (array_unique($modelIds) as $modelId) DB::table('plan_models')->insert(['plan_id'=>$id,'model_id'=>$modelId]);
            }
            $safe = $v; unset($safe['secret']);
            $before = $exists ? (array)$exists : []; unset($before['secret']);
            Audit::record('catalog.'.$kind.'.saved',$r->user()->id,null,null,['id'=>$id,'before'=>$before,'after'=>$safe,'new_prices'=>$prices,'credential_changed'=>isset($v['secret']),'model_ids'=>$modelIds]);
            return $id;
        });
        if ($creatingProvider) return redirect('/command?create=models&provider='.$id.'#models')->with('status','Provider saved. Add a model below using its exact provider model identifier, then set prices and grant it to a plan.');
        return redirect('/command#'.$kind)->with('status','Saved. Existing usage keeps its original pricing.');
    }
    public function archive(Request $r,string $kind,string $id)
    {
        $table = match($kind) {'providers'=>'providers','models'=>'ai_models','plans'=>'plans','packs'=>'credit_packs','promotions'=>'promotions',default=>abort(404)};
        DB::transaction(function () use ($r,$kind,$table,$id) {
            DB::table($table)->where('id',$id)->lockForUpdate()->firstOrFail();
            $v = ['active'=>false,'updated_at'=>now()];
            if (in_array($kind,['providers','models'],true)) $v['deleted_at'] = now();
            if ($kind === 'providers') $v['secret'] = Crypt::encryptString('');
            DB::table($table)->where('id',$id)->update($v);
            Audit::record('catalog.'.$kind.'.archived',$r->user()->id,null,null,['id'=>$id]);
        });
        return redirect('/command#'.$kind)->with('status','Archived. Financial history is retained.');
    }
    public function organization(Request $r,string $id)
    {
        $v = $r->validate(['status'=>'required|in:active,suspended','plan_id'=>'required|uuid|exists:plans,id','billing_client_id'=>'nullable|integer|min:1','reason'=>'required|string|min:8|max:400']);
        DB::transaction(function () use ($r,$id,$v) {
            $org = DB::table('organizations')->where('id',$id)->lockForUpdate()->firstOrFail();
            if ($v['status'] === 'active') {
                abort_if(DB::table('wallets')->where('organization_id',$id)->where('balance','<',0)->exists() || DB::table('orders')->where('organization_id',$id)->where('state','refund_review')->exists(),422,'Resolve debt and payment reviews before reactivating.');
            }
            if ($org->billing_client_id && $org->billing_client_id != ($v['billing_client_id'] ?? null)) abort_if(DB::table('orders')->where('organization_id',$id)->exists(),409,'A billing identity with invoice history cannot be reassigned. Create a separate organization for another legal entity.');
            $values = array_intersect_key($v,array_flip(['status','plan_id','billing_client_id']));
            DB::table('organizations')->where('id',$id)->update($values+['updated_at'=>now()]);
            Audit::record('organization.updated',$r->user()->id,$id,null,['before'=>(array)$org,'after'=>$values,'reason'=>$v['reason']]);
        });
        return back()->with('status','Organization updated.');
    }
    public function grant(Request $r,string $id,WalletService $wallet)
    {
        $v = $r->validate(['amount'=>'required|string','reason'=>'required|string|min:8|max:400','idempotency_key'=>'required|uuid']);
        $wallet->grant($id,Amount::parse($v['amount']),'operator:'.$id.':'.$v['idempotency_key'],$r->user()->id,$v['reason']);
        return back()->with('status','Audited credit grant recorded.');
    }
    public function reconcile(Request $r,string $id,WalletService $wallet)
    {
        $v = $r->validate(['action'=>'required|in:release,settle','input_tokens'=>'required|integer|min:0|max:1000000','output_tokens'=>'required|integer|min:0|max:1000000','reason'=>'required|string|min:12|max:400']);
        DB::transaction(function () use ($r,$id,$v,$wallet) {
            $reservation = DB::table('reservations')->where('id',$id)->where('state','uncertain')->first() ?? abort(409);
            if ($v['action'] === 'release') $wallet->release($id,'operator_confirmed_no_charge',true,$r->user()->id);
            else $wallet->settle($id,$v['input_tokens'],$v['output_tokens'],['content'=>'Provider usage reconciled by an operator. Original response unavailable.','tool_calls'=>[]],$r->user()->id);
            Audit::record('usage.operator_reconciled',$r->user()->id,$reservation->organization_id,$reservation->brand_id,['request_id'=>$id,'reason'=>$v['reason'],'action'=>$v['action']]);
        });
        return back()->with('status','Usage reconciliation recorded.');
    }
    public function refund(Request $r,string $id,WalletService $wallet)
    {
        $v = $r->validate(['reason'=>'required|string|min:12|max:400']);
        DB::transaction(function () use ($r,$id,$v,$wallet) {
            $initial = DB::table('orders')->where('id',$id)->firstOrFail();
            DB::table('organizations')->where('id',$initial->organization_id)->lockForUpdate()->firstOrFail();
            $order = DB::table('orders')->where('id',$id)->lockForUpdate()->firstOrFail();
            abort_unless($order->state === 'refund_review',409);
            if (DB::table('ledger_entries')->where('reference','order:'.$id)->exists()) $wallet->refundOrder($order);
            DB::table('orders')->where('id',$id)->update(['state'=>'refunded','updated_at'=>now()]);
            Audit::record('billing.full_reversal_confirmed',$r->user()->id,$order->organization_id,$order->brand_id,['order_id'=>$id,'reason'=>$v['reason']]);
        });
        return back()->with('status','Full credit reversal recorded. Organization remains suspended until reviewed.');
    }
}
