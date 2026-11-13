<?php
namespace App\Services;
use App\Jobs\RunInference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
final class InferenceService
{
    public function submit(object $brand, array $input, string $actor, ?string $installation = null): object
    {
        $v = Validator::make($input,[
            'model_id'=>'required|uuid','idempotency_key'=>'required|string|regex:/^[A-Za-z0-9:_-]{8,100}$/',
            'messages'=>'required|array|min:1|max:60','messages.*'=>'array:role,content,tool_calls,tool_call_id',
            'messages.*.role'=>'required|in:system,user,assistant,tool','messages.*.content'=>'nullable|string|max:100000',
            'messages.*.tool_call_id'=>'sometimes|string|max:200', 'messages.*.tool_calls'=>'sometimes|array|max:12',
            'messages.*.tool_calls.*'=>'array:id,type,function', 'messages.*.tool_calls.*.id'=>'required|string|max:200',
            'messages.*.tool_calls.*.type'=>'required|in:function','messages.*.tool_calls.*.function'=>'required|array:name,arguments',
            'messages.*.tool_calls.*.function.name'=>'required|string|regex:/^[a-zA-Z0-9_-]{1,64}$/',
            'messages.*.tool_calls.*.function.arguments'=>'required|json|max:100000',
            'tools'=>'sometimes|array|max:40','tools.*'=>'array:type,function','tools.*.type'=>'required|in:function',
            'tools.*.function'=>'required|array:name,description,parameters','tools.*.function.name'=>'required|string|regex:/^[a-zA-Z0-9_-]{1,64}$/',
            'tools.*.function.description'=>'sometimes|string|max:2000','tools.*.function.parameters'=>'required|array',
            'max_output_tokens'=>'required|integer|min:1|max:16384',
        ])->validate();
        $model = DB::table('ai_models')->where('id',$v['model_id'])->where('active',true)->whereNull('deleted_at')->first() ?? abort(422,'Model is unavailable.');
        $provider = DB::table('providers')->where('id',$model->provider_id)->where('active',true)->whereNull('deleted_at')->first() ?? abort(422,'Provider is unavailable.');
        if ($provider->driver === 'replicate') {
            abort_if(!empty($v['tools']) || !preg_match('~^[a-zA-Z0-9_-]+/[a-zA-Z0-9_.-]+$~D',$model->remote_model) || !in_array($model->token_parameter,['max_tokens','max_output_tokens','max_new_tokens'],true),422,'Replicate requires a supported text model and output-limit parameter; tools are unavailable.');
        }
        abort_if(!empty($v['tools']) && !$model->supports_tools,422,'This model does not support tools.');
        abort_if(strlen(json_encode([$v['messages'],$v['tools'] ?? []],JSON_THROW_ON_ERROR)) > $model->max_input_bytes || $v['max_output_tokens'] > $model->max_output_tokens,422,'This request exceeds the model input or output limit.');
        app(TenantStore::class)->run($brand->id,fn ($db) => $db->select('select 1'));
        $price = DB::table('model_prices')->where('model_id',$model->id)->where('id',$model->current_price_id)->first() ?? abort(422,'Model pricing is not configured.');
        $payload = ['messages'=>$v['messages'],'tools'=>$v['tools'] ?? [],'max_output_tokens'=>$v['max_output_tokens'],'remote_model'=>$model->remote_model,'provider_id'=>$provider->id,'token_parameter'=>$model->token_parameter];
        return DB::transaction(function () use ($brand,$price,$payload,$v,$actor,$installation) {
            $r = app(WalletService::class)->reserve($brand->organization_id,$brand->id,$price,$payload,$v['idempotency_key'],$actor,$installation);
            // The database queue insert and reservation commit together. Duplicate jobs cannot claim twice.
            if ($r->state === 'reserved') RunInference::dispatch($r->id);
            return $r;
        });
    }
    public function result(object $r): array
    {
        $result = $r->state === 'settled' && $r->result ? json_decode(\Illuminate\Support\Facades\Crypt::decryptString($r->result),true,512,JSON_THROW_ON_ERROR) : null;
        return ['id'=>$r->id,'state'=>$r->state,'reserved_credits'=>$r->amount,'charged_credits'=>$r->charged,'result'=>$result,'error'=>$r->error_code,'credit_scale'=>1000000];
    }
}
