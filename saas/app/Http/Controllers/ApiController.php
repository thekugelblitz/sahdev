<?php
namespace App\Http\Controllers;
use App\Services\BillingService;
use App\Services\InferenceService;
use App\Services\InstallationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
final class ApiController
{
    public function enroll(Request $r, InstallationService $installations)
    {
        $v = $r->validate(['installation_id'=>'required|uuid','token'=>'required|string|size:64','whmcs_url'=>'required|string|max:240','version'=>'required|string|max:50','secret'=>'required|string|regex:/^[a-f0-9]{64}$/']);
        return $installations->enroll($v['installation_id'],$v['token'],$v['whmcs_url'],$v['version'],$v['secret']);
    }
    public function models(Request $r)
    {
        $org = DB::table('organizations')->where('id',$r->attributes->get('brand')->organization_id)->first();
        $models = DB::table('ai_models')->join('providers','providers.id','=','ai_models.provider_id')->where('ai_models.active',true)->where('providers.active',true)->whereNull('ai_models.deleted_at')->whereNull('providers.deleted_at')->whereIn('ai_models.id',DB::table('plan_models')->where('plan_id',$org->plan_id)->select('model_id'))->get(['ai_models.id','ai_models.name','ai_models.max_input_bytes','ai_models.max_output_tokens','ai_models.supports_tools']);
        foreach ($models as $model) $model->price = DB::table('model_prices')->where('id',DB::table('ai_models')->where('id',$model->id)->value('current_price_id'))->first(['id','input_rate','output_rate']);
        return ['models'=>$models,'credit_scale'=>1000000,'rate_unit'=>'credits per million tokens; all input including cached input uses the listed input rate'];
    }
    public function submit(Request $r, InferenceService $inference)
    {
        $i = $r->attributes->get('installation');
        $actor = $r->validate(['actor'=>'required|string|regex:/^(admin|client|visitor|system):[A-Za-z0-9_-]{1,80}$/'])['actor'];
        $reservation = $inference->submit($r->attributes->get('brand'),$r->all(),'installation:'.$i->id.':'.$actor,$i->id);
        return response()->json($inference->result($reservation),202);
    }
    public function result(Request $r, string $id, InferenceService $inference)
    {
        $i = $r->attributes->get('installation');
        // Structured workflow responses require the actor/ticket-scoped result endpoint.
        abort_if(DB::table('workflow_requests')->where('reservation_id',$id)->exists(),404);
        return $inference->result(DB::table('reservations')->where('id',$id)->where('brand_id',$i->brand_id)->where('installation_id',$i->id)->first() ?? abort(404));
    }
    public function billing(Request $r, BillingService $billing)
    {
        $secret = config('cloud.billing_webhook_secret'); $time = (string)$r->header('X-Sahdev-Time');
        abort_unless($secret && strlen($secret) >= 32 && ctype_digit($time) && abs(time()-(int)$time) <= 300 && hash_equals(hash_hmac('sha256',$time."\n".$r->getContent(),$secret),(string)$r->header('X-Sahdev-Signature')),401);
        $v = $r->validate(['event_id'=>'required|string|regex:/^[A-Za-z0-9:_-]{8,120}$/','invoice_id'=>'required|integer|min:1','kind'=>'required|in:paid,reversal']);
        $billing->event($v,hash('sha256',$r->getContent()));
        return ['received'=>true];
    }
}
