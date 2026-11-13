<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;

final class LaunchReadiness
{
    public function migrationComplete(): bool
    {
        $features = config('migration.features', []);
        return $features !== [] && collect($features)->every(fn ($feature) => $feature['status'] === 'verified');
    }

    /** Configuration checks are deliberately separate from end-to-end evidence. */
    public function checks(): array
    {
        $mail = config('mail.default');
        $models = DB::table('ai_models')->join('providers','providers.id','=','ai_models.provider_id')
            ->join('plan_models','plan_models.model_id','=','ai_models.id')->join('plans','plans.id','=','plan_models.plan_id')
            ->where('ai_models.active',true)->whereNull('ai_models.deleted_at')->where('providers.active',true)
            ->whereNull('providers.deleted_at')->where('plans.active',true)->whereNotNull('ai_models.current_price_id')->exists();
        $release = false;
        try { $catalog = app(ReleaseService::class)->catalog(); if ($catalog) { app(ReleaseService::class)->artifact('module'); $release = ($catalog['distribution'] ?? '') === 'thin-connector' && ($catalog['channel'] ?? '') === 'stable'; } }
        catch (\Throwable $e) { /* Show status without exposing filesystem or cryptographic errors. */ }
        return [
            'migration'=>['label'=>'Full thin-connector feature parity','ready'=>$this->migrationComplete(),'detail'=>'Every feature domain must have verified migration evidence.'],
            'release'=>['label'=>'Signed stable connector download','ready'=>$release,'detail'=>'Signature, distribution type, size and SHA-256 must verify.'],
            'models'=>['label'=>'Priced AI model assigned to an active plan','ready'=>$models,'detail'=>'Configuration check only; run a real request after saving a provider.'],
            'mail'=>['label'=>'Transactional email configured','ready'=>$mail === 'smtp' && (bool)config('mail.mailers.smtp.host') && (bool)config('mail.from.address'),'detail'=>'Verify actual delivery of signup and password-reset messages.'],
            'billing'=>['label'=>'HostingSpell billing configured','ready'=>(bool)(config('cloud.billing_url') && config('cloud.billing_identifier') && config('cloud.billing_secret') && strlen((string)config('cloud.billing_webhook_secret')) >= 32),'detail'=>'Verify an invoice and payment delivery before selling credits.'],
            'catalog'=>['label'=>'Credit packages published','ready'=>DB::table('credit_packs')->where('active',true)->where('currency',config('cloud.billing_currency'))->exists(),'detail'=>'Prices and model rates are separate; verify their commercial margin.'],
        ];
    }

    public function ready(): bool { return collect($this->checks())->every(fn ($check) => $check['ready']); }
}
