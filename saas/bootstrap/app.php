<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(App\Http\Middleware\SecurityHeaders::class);
        $middleware->alias(['org' => App\Http\Middleware\OrganizationAccess::class, 'ops' => App\Http\Middleware\OperatorAccess::class, 'installation' => App\Http\Middleware\InstallationAuth::class]);
        $middleware->prependToPriorityList(\Illuminate\Routing\Middleware\ThrottleRequests::class, App\Http\Middleware\InstallationAuth::class);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/dashboard');
        $middleware->preventRequestForgery(except: []);
        $middleware->trustHosts(at: fn () => ['^'.preg_quote(parse_url(config('app.url'), PHP_URL_HOST) ?: 'localhost').'$'], subdomains: false);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'secret', 'api_key', 'code']);
    })->create();
