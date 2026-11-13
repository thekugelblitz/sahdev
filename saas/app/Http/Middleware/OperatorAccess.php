<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class OperatorAccess
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->is_operator,403);
        if (!$request->user()->totp_secret || $request->session()->get('operator_verified_at',0) < time()-900) return redirect('/security');
        return $next($request);
    }
}
