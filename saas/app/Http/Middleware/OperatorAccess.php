<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class OperatorAccess
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->is_operator,403);
        $policy = DB::table('operator_security')->find(1);
        abort_unless($policy,503);
        if ($policy->mfa_required && (
            !$request->user()->totp_secret ||
            $request->session()->get('operator_mfa_revision') !== $policy->revision ||
            $request->session()->get('operator_verified_at',0) < time()-900
        )) return redirect('/command/security');
        return $next($request);
    }
}
