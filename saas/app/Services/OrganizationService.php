<?php
namespace App\Services;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
final class OrganizationService
{
    public function create(User $user, string $name): string
    {
        return DB::transaction(function () use ($user,$name) {
            $plan = DB::table('plans')->where('active',true)->orderBy('created_at')->first() ?? abort(503,'Registration is not configured.');
            $id = (string)Str::uuid();
            DB::table('organizations')->insert(['id'=>$id,'name'=>$name,'plan_id'=>$plan->id,'status'=>'active','created_at'=>now(),'updated_at'=>now()]);
            DB::table('memberships')->insert(['organization_id'=>$id,'user_id'=>$user->id,'role'=>'owner','created_at'=>now(),'updated_at'=>now()]);
            app(WalletService::class)->wallet($id,'pool');
            Audit::record('organization.created',$user->id,$id);
            return $id;
        });
    }
    public static function canonicalUrl(string $url): string
    {
        $p = parse_url($url);
        if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user'],$p['pass']) || isset($p['user']) || isset($p['pass']) || isset($p['port']) || isset($p['query']) || isset($p['fragment']) || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/iD',$p['host']) || !preg_match('~^/[a-zA-Z0-9_./-]*$~D',$p['path'] ?? '/') || str_contains($p['path'] ?? '', '..')) {
            throw ValidationException::withMessages(['whmcs_url'=>'Use the HTTPS WHMCS installation URL, without a query, port, username or password.']);
        }
        return 'https://'.strtolower($p['host']).rtrim($p['path'] ?? '', '/');
    }
    public function addBrand(string $org, array $values, string $actor): string
    {
        return DB::transaction(function () use ($org,$values,$actor) {
            $organization = DB::table('organizations')->where('id',$org)->lockForUpdate()->first() ?? abort(404);
            abort_unless($organization->status === 'active',423,'This organization is suspended.');
            $plan = DB::table('plans')->where('id',$organization->plan_id)->first();
            abort_if(DB::table('brands')->where('organization_id',$org)->count() >= $plan->brand_limit,422,'Your plan brand limit has been reached.');
            $id = (string)Str::uuid();
            DB::table('brands')->insert(['id'=>$id,'organization_id'=>$org,'name'=>$values['name'],'whmcs_url'=>self::canonicalUrl($values['whmcs_url']),'credit_mode'=>$values['credit_mode'],'monthly_budget'=>$values['monthly_budget'],'domain_challenge'=>Str::random(48),'created_at'=>now(),'updated_at'=>now()]);
            app(WalletService::class)->wallet($org,$id);
            Audit::record('brand.created',$actor,$org,$id,['name'=>$values['name'],'budget'=>$values['monthly_budget'],'credit_mode'=>$values['credit_mode']]);
            return $id;
        });
    }
    public function updateBrand(string $org, string $id, array $values, string $actor): void
    {
        DB::transaction(function () use ($org,$id,$values,$actor) {
            DB::table('organizations')->where('id',$org)->lockForUpdate()->firstOrFail();
            $brand = DB::table('brands')->where('organization_id',$org)->where('id',$id)->first() ?? abort(404);
            $period = DB::table('budget_periods')->where('brand_id',$id)->where('month',now()->format('Y-m'))->first();
            abort_if($period && $values['monthly_budget'] < $period->spent + $period->reserved,422,'The budget cannot be below credits already used or reserved this month. Suspend the brand to stop new work.');
            if ($values['credit_mode'] !== $brand->credit_mode) {
                abort_if(DB::table('reservations')->where('brand_id',$id)->whereIn('state',['reserved','dispatched','uncertain'])->exists(),409,'Resolve outstanding usage before changing the credit mode.');
                $wallet = app(WalletService::class)->wallet($org,$id);
                abort_if($wallet->balance != 0,409,'Transfer the allocated balance back to the pool before changing the credit mode.');
            }
            $allowed = array_intersect_key($values,array_flip(['name','monthly_budget','credit_mode','status','retain_content']));
            DB::table('brands')->where('id',$id)->update($allowed+['updated_at'=>now()]);
            Audit::record('brand.updated',$actor,$org,$id,['before'=>array_intersect_key((array)$brand,$allowed),'after'=>$allowed]);
        });
    }
}
