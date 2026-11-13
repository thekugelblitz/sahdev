<?php
namespace App\Http\Middleware;
use App\Support\Access;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class OrganizationAccess
{
    public function handle(Request $request, Closure $next)
    {
        $org = $request->session()->get('organization_id');
        if (!$org) { $org = DB::table('memberships')->where('user_id',$request->user()->id)->orderBy('id')->value('organization_id'); $request->session()->put('organization_id',$org); }
        abort_unless($org,403,'No organization membership.');
        Access::membership($request->user(),$org);
        $request->attributes->set('org',DB::table('organizations')->where('id',$org)->first() ?? abort(403));
        return $next($request);
    }
}
