<?php
namespace App\Http\Middleware;
use App\Services\InstallationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
class InstallationAuth
{
    public function handle(Request $request, Closure $next)
    {
        $id = (string)$request->header('X-Sahdev-Installation'); $time = (string)$request->header('X-Sahdev-Timestamp'); $nonce = (string)$request->header('X-Sahdev-Nonce');
        abort_unless(preg_match('/^[a-f0-9-]{36}$/D',$id) && ctype_digit($time) && abs(time()-(int)$time) <= 300 && preg_match('/^[a-f0-9]{32,64}$/D',$nonce) && !$request->getQueryString(),401);
        $i = DB::table('installations')->where('id',$id)->where('status','active')->first() ?? abort(401);
        $signature = InstallationService::signature($id,$time,$nonce,$request->method(),$request->getPathInfo(),$request->getContent(),Crypt::decryptString($i->secret));
        abort_unless(hash_equals($signature,(string)$request->header('X-Sahdev-Signature')),401);
        abort_unless(DB::table('request_nonces')->insertOrIgnore(['id'=>$id.':'.$nonce,'expires_at'=>now()->addMinutes(10)]),409,'Request nonce already used. Retry with a fresh nonce and the same operation key.');
        $brand = DB::table('brands')->where('id',$i->brand_id)->first() ?? abort(401);
        // Signed result reads remain possible after budget/org suspension; no new inference can reserve credits.
        $request->attributes->set('installation',$i); $request->attributes->set('brand',$brand);
        DB::table('installations')->where('id',$id)->update(['last_seen_at'=>now()]);
        return $next($request);
    }
}
