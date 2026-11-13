<?php
namespace App\Http\Controllers;
use App\Jobs\CreateInvoice;
use App\Models\User;
use App\Services\{BillingService,InferenceService,InstallationService,OrganizationService,WalletService};
use App\Support\{Access,Amount,Audit};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
final class DashboardController
{
    private function org(Request $r): object { return $r->attributes->get('org'); }
    public function index(Request $r)
    {
        $org = $this->org($r); $member = Access::membership($r->user(),$org->id);
        $brands = Access::brands($r->user(),$org->id)->orderBy('created_at')->get();
        $ids = $brands->pluck('id');
        return view('dashboard',[
            'org'=>$org,'member'=>$member,'brands'=>$brands,
            'plan'=>DB::table('plans')->where('id',$org->plan_id)->first(),
            'wallets'=>DB::table('wallets')->where('organization_id',$org->id)->when($member->role==='member',fn($q)=>$q->whereIn('scope',$ids))->get()->keyBy('scope'),
            'periods'=>DB::table('budget_periods')->whereIn('brand_id',$ids)->where('month',now()->format('Y-m'))->get()->keyBy('brand_id'),
            'usage'=>DB::table('reservations')->whereIn('brand_id',$ids)->orderByDesc('created_at')->limit(30)->get(['id','brand_id','actor','state','charged','amount','created_at','error_code']),
            'events'=>DB::table('audit_events')->where('organization_id',$org->id)->when($member->role==='member',fn($q)=>$q->whereIn('brand_id',$ids))->orderByDesc('created_at')->limit(20)->get(),
            'organizations'=>DB::table('organizations')->whereIn('id',DB::table('memberships')->where('user_id',$r->user()->id)->select('organization_id'))->get(),
        ]);
    }
    public function switch(Request $r)
    {
        $v = $r->validate(['organization_id'=>'required|uuid']); Access::membership($r->user(),$v['organization_id']);
        $r->session()->put('organization_id',$v['organization_id']); return redirect('/dashboard');
    }
    public function addBrand(Request $r, OrganizationService $service)
    {
        $org = $this->org($r); Access::requireRole($r->user(),$org->id,['owner','admin']);
        $v = $r->validate(['name'=>'required|string|max:100','whmcs_url'=>'required|string|max:240','credit_mode'=>'required|in:pooled,allocated','monthly_budget'=>'required|string']);
        $v['monthly_budget'] = Amount::parse($v['monthly_budget']);
        $id = $service->addBrand($org->id,$v,$r->user()->id);
        return redirect('/brands/'.$id)->with('status','Brand created. Verify its domain and provision its database to connect WHMCS.');
    }
    public function brand(Request $r, string $id)
    {
        $org = $this->org($r); $brand = Access::brand($r->user(),$org->id,$id);
        $models = DB::table('ai_models')->where('active',true)->whereNull('deleted_at')->whereIn('provider_id',DB::table('providers')->where('active',true)->whereNull('deleted_at')->select('id'))->whereIn('id',DB::table('plan_models')->where('plan_id',$org->plan_id)->select('model_id'))->get();
        foreach ($models as $model) $model->price = DB::table('model_prices')->where('id',$model->current_price_id)->first(['input_rate','output_rate']);
        return view('brand',['org'=>$org,'brand'=>$brand,'member'=>Access::membership($r->user(),$org->id),
            'wallet'=>DB::table('wallets')->where('organization_id',$org->id)->where('scope',$id)->first(),
            'database_ready'=>DB::table('brand_databases')->where('brand_id',$id)->where('schema_version',1)->exists(),
            'installations'=>DB::table('installations')->where('brand_id',$id)->orderByDesc('created_at')->get(['id','status','version','last_seen_at','created_at']),
            'models'=>$models,
            'period'=>DB::table('budget_periods')->where('brand_id',$id)->where('month',now()->format('Y-m'))->first(),
        ]);
    }
    public function updateBrand(Request $r,string $id,OrganizationService $service)
    {
        $org = $this->org($r); Access::requireRole($r->user(),$org->id,['owner','admin']);
        $v = $r->validate(['name'=>'required|string|max:100','credit_mode'=>'required|in:pooled,allocated','monthly_budget'=>'required|string','status'=>'required|in:active,suspended','retain_content'=>'required|boolean']);
        $v['monthly_budget'] = Amount::parse($v['monthly_budget']);
        $service->updateBrand($org->id,$id,$v,$r->user()->id); return back()->with('status','Brand settings saved.');
    }
    public function transfer(Request $r,string $id,WalletService $wallet)
    {
        $org = $this->org($r); Access::requireRole($r->user(),$org->id,['owner','admin','billing']);
        $v = $r->validate(['amount'=>'required|string','direction'=>'required|in:allocate,reclaim','idempotency_key'=>'required|uuid']);
        $wallet->transfer($org->id,$id,Amount::parse($v['amount']),$v['direction']==='allocate',$v['idempotency_key'],$r->user()->id);
        return back()->with('status','Credit transfer recorded.');
    }
    public function connection(Request $r,string $id,InstallationService $service)
    {
        $org = $this->org($r); Access::requireRole($r->user(),$org->id,['owner','admin']);
        $brand = Access::brand($r->user(),$org->id,$id); $action = $r->validate(['action'=>'required|in:verify,issue,revoke'])['action'];
        if ($action === 'verify') $service->verifyDomain($brand,$r->user()->id);
        if ($action === 'issue') return back()->with('enrollment',$service->issue($brand,$r->user()->id));
        if ($action === 'revoke') DB::transaction(function () use ($brand,$r) {
            DB::table('installations')->where('brand_id',$brand->id)->update(['status'=>'revoked','secret'=>null,'enrollment_hash'=>null,'updated_at'=>now()]);
            Audit::record('installation.revoked',$r->user()->id,$brand->organization_id,$brand->id);
        });
        return back()->with('status','Connection updated.');
    }
    public function billing(Request $r)
    {
        $org = $this->org($r); Access::requireRole($r->user(),$org->id,['owner','admin','billing']);
        return view('billing',['org'=>$org,'purchasingOpen'=>app(\App\Services\LaunchReadiness::class)->ready(),'packs'=>DB::table('credit_packs')->where('active',true)->get(),'brands'=>Access::brands($r->user(),$org->id)->get(),
            'orders'=>DB::table('orders')->where('organization_id',$org->id)->orderByDesc('created_at')->limit(100)->get(),
            'ledger'=>DB::table('ledger_entries')->where('organization_id',$org->id)->orderByDesc('created_at')->limit(100)->get()]);
    }
    public function checkout(Request $r,BillingService $billing)
    {
        $org = $this->org($r); Access::requireRole($r->user(),$org->id,['owner','admin','billing']);
        if (!app(\App\Services\LaunchReadiness::class)->ready()) return back()->withErrors(['checkout'=>'Credit purchases open after release validation. Preview testing uses operator-granted credits.']);
        $v = $r->validate(['pack_id'=>'required|uuid','brand_id'=>'nullable|uuid','promotion'=>'nullable|string|max:40','idempotency_key'=>'required|uuid']);
        DB::transaction(function () use ($r,$org,$billing,$v) {
            $order = $billing->order($org->id,$v['pack_id'],$v['brand_id'] ?? null,$v['promotion'] ?? '',$v['idempotency_key'],$r->user()->id);
            if ($order->state === 'pending') CreateInvoice::dispatch($order->id);
        });
        return back()->with('status','Credit order saved. Your HostingSpell invoice will appear below after the billing worker processes it. Credits are added only after verified payment.');
    }
    public function team(Request $r)
    {
        $org = $this->org($r); Access::requireRole($r->user(),$org->id,['owner','admin']);
        return view('team',['org'=>$org,'brands'=>Access::brands($r->user(),$org->id)->get(),'members'=>DB::table('memberships')->join('users','users.id','=','memberships.user_id')->where('organization_id',$org->id)->get(['users.id','users.name','users.email','memberships.role']),'grants'=>DB::table('brand_memberships')->whereIn('brand_id',DB::table('brands')->where('organization_id',$org->id)->select('id'))->get()]);
    }
    public function membership(Request $r)
    {
        $org = $this->org($r); Access::requireRole($r->user(),$org->id,['owner','admin']);
        $v = $r->validate(['email'=>'required|email','role'=>'required|in:admin,billing,member,remove','brand_ids'=>'sometimes|array','brand_ids.*'=>'uuid','brand_role'=>'required|in:viewer,operator']);
        DB::transaction(function () use ($r,$org,$v) {
            DB::table('organizations')->where('id',$org->id)->lockForUpdate()->firstOrFail();
            $target = User::where('email',strtolower($v['email']))->whereNotNull('email_verified_at')->first() ?? abort(422,'Ask this person to register and verify their email before adding them.');
            $old = DB::table('memberships')->where('organization_id',$org->id)->where('user_id',$target->id)->first();
            abort_if($old?->role === 'owner' || $target->id === $r->user()->id,422,'Owner membership and your own access cannot be changed here.');
            if (!$old) abort_if(DB::table('memberships')->where('organization_id',$org->id)->count() >= DB::table('plans')->where('id',$org->plan_id)->value('seat_limit'),422,'Staff seat limit reached.');
            foreach ($v['brand_ids'] ?? [] as $id) abort_unless(DB::table('brands')->where('organization_id',$org->id)->where('id',$id)->exists(),404);
            DB::table('brand_memberships')->where('user_id',$target->id)->whereIn('brand_id',DB::table('brands')->where('organization_id',$org->id)->select('id'))->delete();
            if ($v['role'] === 'remove') DB::table('memberships')->where('organization_id',$org->id)->where('user_id',$target->id)->delete();
            else {
                DB::table('memberships')->updateOrInsert(['organization_id'=>$org->id,'user_id'=>$target->id],['role'=>$v['role'],'created_at'=>now(),'updated_at'=>now()]);
                if ($v['role'] === 'member') foreach (array_unique($v['brand_ids'] ?? []) as $id) DB::table('brand_memberships')->insert(['brand_id'=>$id,'user_id'=>$target->id,'role'=>$v['brand_role']]);
            }
            Audit::record('membership.changed',$r->user()->id,$org->id,null,['user_id'=>$target->id,'role'=>$v['role'],'brand_ids'=>$v['brand_ids'] ?? [],'brand_role'=>$v['brand_role']]);
        });
        return back()->with('status','Team permissions saved. Changes apply to every new request.');
    }
    public function infer(Request $r,string $id,InferenceService $inference)
    {
        $brand = Access::brand($r->user(),$this->org($r)->id,$id,true);
        $v = $r->validate(['message'=>'required|string|max:16000','model_id'=>'required|uuid','idempotency_key'=>'required|uuid','max_output_tokens'=>'required|integer|min:1|max:16384']);
        $result = $inference->submit($brand,['messages'=>[['role'=>'user','content'=>$v['message']]],'model_id'=>$v['model_id'],'idempotency_key'=>$v['idempotency_key'],'max_output_tokens'=>$v['max_output_tokens']],$r->user()->id);
        return response()->json($inference->result($result),202);
    }
    public function result(Request $r,string $id,InferenceService $inference)
    {
        $reservation = DB::table('reservations')->where('id',$id)->where('organization_id',$this->org($r)->id)->where('actor',$r->user()->id)->whereNull('installation_id')->first() ?? abort(404);
        Access::brand($r->user(),$this->org($r)->id,$reservation->brand_id,true);
        return $inference->result($reservation);
    }
}
