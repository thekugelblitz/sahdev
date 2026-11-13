<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        abort_if((int)$request->server('CONTENT_LENGTH',0) > 262144,413);
        $response = $next($request);
        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
        $response->headers->set('X-Content-Type-Options','nosniff');
        $response->headers->set('Referrer-Policy','same-origin');
        $response->headers->set('Cache-Control','no-store, private');
        if (app()->isProduction()) $response->headers->set('Strict-Transport-Security','max-age=31536000');
        return $response;
    }
}
