<?php
namespace App\Http\Controllers;

use App\Services\LaunchReadiness;
use Illuminate\Support\Facades\DB;

final class PublicController
{
    public function home()
    {
        return view('public.home', $this->catalog());
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
