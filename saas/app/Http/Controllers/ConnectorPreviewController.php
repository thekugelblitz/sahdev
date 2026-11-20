<?php
namespace App\Http\Controllers;

use App\Domain\Workflows\CopilotRecords;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ConnectorPreviewController
{
    public function status(Request $request): array
    {
        $brand = $request->attributes->get('brand');
        $organization = $this->organization($brand);
        $modelCount = DB::table('plan_models')
            ->join('plans','plans.id','=','plan_models.plan_id')
            ->join('ai_models','ai_models.id','=','plan_models.model_id')
            ->join('providers','providers.id','=','ai_models.provider_id')
            ->join('model_prices','model_prices.id','=','ai_models.current_price_id')
            ->where('plans.id',$organization->plan_id)->where('plans.active',true)
            ->where('ai_models.active',true)->whereNull('ai_models.deleted_at')
            ->where('providers.active',true)->whereNull('providers.deleted_at')
            ->count();
        return [
            'preview_enabled'=>(bool) config('cloud.thin_connector_preview'),
            'brand_database_ready'=>DB::table('brand_databases')->where('brand_id',$brand->id)->exists(),
            'models'=>$modelCount,
        ];
    }

    public function records(Request $request, CopilotRecords $records): array
    {
        abort_unless(config('cloud.thin_connector_preview'),503,'Connector preview is disabled.');
        abort_if(strlen($request->getContent()) > 32768,413);
        $brand = $request->attributes->get('brand');
        $this->organization($brand);
        $data = $records->validate($request->all());
        $card = $records->card($data);
        $installation = $request->attributes->get('installation');
        Audit::record('copilot.records_read','installation:'.$installation->id.':'.$data['actor'],
            $brand->organization_id,$brand->id,[
                'resource'=>$data['resource'], 'client_id'=>(int) $data['client']['id'],
                'page'=>$card['page'], 'row_count'=>count($card['rows']),
            ]);
        return $card;
    }

    private function organization(object $brand): object
    {
        $organization = DB::table('organizations')->where('id',$brand->organization_id)->firstOrFail();
        abort_unless($organization->status === 'active' && $brand->status === 'active' && $brand->domain_verified_at,423,'Brand access is suspended.');
        return $organization;
    }
}
