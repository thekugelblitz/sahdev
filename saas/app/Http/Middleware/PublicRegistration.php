<?php
namespace App\Http\Middleware;

use App\Services\LaunchReadiness;
use Closure;
use Illuminate\Http\Request;

final class PublicRegistration
{
    public function handle(Request $request, Closure $next)
    {
        // The operator's environment switch cannot turn an incomplete migration into a public release.
        if (!config('cloud.registration') || !app(LaunchReadiness::class)->ready()) {
            if ($request->isMethod('get')) return response()->view('public.registration-closed', [], 200);
            abort(503, 'Public signup is not open yet. Existing accounts can still sign in.');
        }
        return $next($request);
    }
}
