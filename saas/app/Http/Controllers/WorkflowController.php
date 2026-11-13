<?php
namespace App\Http\Controllers;

use App\Domain\Workflows\TicketWorkflow;
use App\Services\InferenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class WorkflowController
{
    public function submit(Request $request, TicketWorkflow $workflow, InferenceService $inference)
    {
        abort_unless(config('cloud.thin_connector_preview'),503,'Thin connector acceptance testing is not enabled.');
        $data = $workflow->validate($request->all());
        $brand = $request->attributes->get('brand'); $installation = $request->attributes->get('installation');
        $reservation = DB::transaction(function () use ($data,$brand,$installation,$workflow,$inference) {
            // A stable workflow revision is part of the priced request; a replay never silently uses a changed prompt.
            $reservation = $inference->submit($brand,[
                'model_id'=>$data['model_id'],'idempotency_key'=>$data['idempotency_key'],
                'max_output_tokens'=>$data['max_output_tokens'],'messages'=>$workflow->messages($data),'tools'=>[],
            ],'installation:'.$installation->id.':'.$data['actor'],$installation->id);
            DB::table('workflow_requests')->insertOrIgnore([
                'reservation_id'=>$reservation->id,'installation_id'=>$installation->id,'brand_id'=>$brand->id,
                'actor'=>$data['actor'],'workflow'=>$data['workflow'],'revision'=>TicketWorkflow::REVISION,
                'ticket_id'=>$data['context']['ticket']['id'],'record_links'=>json_encode($workflow->links($data),JSON_THROW_ON_ERROR),'created_at'=>now(),
            ]);
            return $reservation;
        });
        return response()->json(['id'=>$reservation->id,'state'=>$reservation->state,'revision'=>TicketWorkflow::REVISION],202);
    }

    public function result(Request $request, string $id, TicketWorkflow $workflow, InferenceService $inference)
    {
        $input = $request->validate(['actor'=>'required|string|regex:/^admin:[1-9][0-9]{0,9}$/D','ticket_id'=>'required|integer|min:1']);
        $installation = $request->attributes->get('installation');
        $job = DB::table('workflow_requests')->where('reservation_id',$id)->where('installation_id',$installation->id)
            ->where('brand_id',$installation->brand_id)->where('actor',$input['actor'])->where('ticket_id',$input['ticket_id'])->first() ?? abort(404);
        $reservation = DB::table('reservations')->where('id',$id)->firstOrFail();
        $result = $inference->result($reservation);
        return [
            'id'=>$id,'state'=>$result['state'],'charged_credits'=>$result['charged_credits'],'credit_scale'=>1000000,
            'output'=>$result['result'] ? $workflow->present($job->workflow,(string)($result['result']['content'] ?? '')) : null,
            'record_links'=>$result['state'] === 'settled' ? json_decode($job->record_links,true) : [],
            'error'=>$result['error'],'revision'=>$job->revision,
        ];
    }
}
