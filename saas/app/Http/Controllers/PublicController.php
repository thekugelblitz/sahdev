<?php
namespace App\Http\Controllers;

use App\Services\LaunchReadiness;
use Illuminate\Support\Facades\DB;

final class PublicController
{
    public function home()
    {
        $catalog = $this->catalog();
        $status = $catalog['open'] ? 'Registration open.' : 'RELEASE PREVIEW · Access is by invitation.';
        $pack = $catalog['packs']->first();
        if ($pack) {
            $status .= ' Credit packs from '.number_format($pack->price_minor / 100, 2).' '.$pack->currency.'.';
        }
        $html = file_get_contents(public_path('business-home.html'));
        return response(str_replace('Pilot availability and pricing', e($status), $html));
    }
    public function pricing() { return view('public.pricing', $this->catalog()); }
    public function guide() { return view('public.guide'); }
    private function catalog(): array
    {
        return [
            'packs'=>DB::table('credit_packs')->where('active',true)->where('currency',config('cloud.billing_currency'))->orderBy('price_minor')->get(),
            'plan'=>DB::table('plans')->where('id','00000000-0000-4000-8000-000000000001')->where('active',true)->first(),
            'open'=>config('cloud.registration') && app(LaunchReadiness::class)->ready(),
        ];
    }
}
