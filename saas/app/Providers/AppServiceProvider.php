<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}
    public function boot(): void
    {
        if ($this->app->isProduction()) URL::forceScheme('https');
        RateLimiter::for('login',fn (Request $r) => [Limit::perMinute(5)->by('email:'.hash('sha256',strtolower((string)$r->input('email')))),Limit::perMinute(20)->by('ip:'.$r->ip())]);
        RateLimiter::for('installation',fn (Request $r) => Limit::perMinute(120)->by($r->attributes->get('installation')?->id ?? $r->ip()));
    }
}
