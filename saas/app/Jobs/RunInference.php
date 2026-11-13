<?php
namespace App\Jobs;
use App\Services\ProviderGateway;
use App\Services\TenantStore;
use App\Services\WalletService;
use App\Support\Audit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
class RunInference implements ShouldQueue
{
    use Queueable;
    public int $tries = 1;
    public int $timeout = 110;
    public bool $failOnTimeout = true;
    public function __construct(public string $reservationId) {}
    public function handle(WalletService $wallet, ProviderGateway $gateway, TenantStore $tenants): void
    {
        $r = $wallet->claim($this->reservationId); if (!$r) return;
        try {
            $payload = json_decode(Crypt::decryptString($r->payload),true,512,JSON_THROW_ON_ERROR);
            $provider = DB::table('providers')->where('id',$payload['provider_id'])->where('active',true)->whereNull('deleted_at')->first();
            if (!$provider) { $wallet->release($r->id,'provider_disabled',true); return; }
            $result = $gateway->generate($provider,$payload,function (string $reference) use ($r) {
                DB::table('reservations')->where('id',$r->id)->update(['provider_reference'=>$reference]);
                Audit::record('usage.provider_reference','worker',$r->organization_id,$r->brand_id,['request_id'=>$r->id,'reference'=>$reference]);
            });
            if (isset($result['rejected'])) { $wallet->release($r->id,'provider_rejected_'.$result['status'],true); return; }
            $wallet->settle($r->id,$result['input'],$result['output'],$result['result']);
            try { $tenants->record($r,$payload,$result['result']); }
            catch (\Throwable $e) { Audit::record('tenant.history_write_failed','worker',$r->organization_id,$r->brand_id,['request_id'=>$r->id]); }
        } catch (\Throwable $e) {
            // A timeout may have incurred provider usage. Keep the hold and require reconciliation.
            $wallet->uncertain($r->id,'provider_outcome_unknown');
        }
    }
    public function failed(?\Throwable $exception): void { app(WalletService::class)->uncertain($this->reservationId,'worker_interrupted'); }
}
