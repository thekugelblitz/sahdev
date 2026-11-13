<?php
namespace App\Services;
use App\Support\Audit;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
final class InstallationService
{
    public function verifyDomain(object $brand, string $actor): void
    {
        $host = parse_url($brand->whmcs_url,PHP_URL_HOST);
        $records = dns_get_record('_sahdev.'.$host,DNS_TXT) ?: [];
        $expected = 'sahdev-verification='.$brand->domain_challenge;
        abort_unless(collect($records)->contains(fn ($r) => hash_equals($expected,$r['txt'] ?? '')),422,'The DNS TXT record has not been found yet.');
        DB::transaction(function () use ($brand,$actor) {
            DB::table('brands')->where('id',$brand->id)->update(['domain_verified_at'=>now(),'updated_at'=>now()]);
            Audit::record('domain.verified',$actor,$brand->organization_id,$brand->id);
        });
    }
    public function issue(object $brand, string $actor): array
    {
        return DB::transaction(function () use ($brand,$actor) {
            DB::table('organizations')->where('id',$brand->organization_id)->lockForUpdate()->firstOrFail();
            abort_unless($brand->domain_verified_at,422,'Verify the WHMCS domain before connecting.');
            // Replacing a connection revokes the old credentials immediately.
            DB::table('installations')->where('brand_id',$brand->id)->whereIn('status',['pending','active'])->update(['status'=>'revoked','secret'=>null,'enrollment_hash'=>null,'updated_at'=>now()]);
            $id = (string)Str::uuid(); $token = bin2hex(random_bytes(32));
            DB::table('installations')->insert(['id'=>$id,'brand_id'=>$brand->id,'enrollment_hash'=>hash('sha256',$token),'enrollment_expires_at'=>now()->addMinutes(15),'status'=>'pending','created_at'=>now(),'updated_at'=>now()]);
            Audit::record('installation.enrollment_issued',$actor,$brand->organization_id,$brand->id,['installation_id'=>$id]);
            return ['installation_id'=>$id,'token'=>$token,'expires_in'=>900];
        });
    }
    public function enroll(string $id, string $token, string $url, string $version, string $clientSecret): array
    {
        return DB::transaction(function () use ($id,$token,$url,$version,$clientSecret) {
            $i = DB::table('installations')->where('id',$id)->lockForUpdate()->first() ?? abort(401);
            // The connector generates the secret before enrollment so a lost response can be retried safely.
            $brand = DB::table('brands')->where('id',$i->brand_id)->first();
            abort_unless($i->enrollment_hash && hash_equals($i->enrollment_hash,hash('sha256',$token)) && $i->enrollment_expires_at >= now()->toDateTimeString(),401);
            abort_unless($brand->domain_verified_at && OrganizationService::canonicalUrl($url) === $brand->whmcs_url,401);
            abort_unless(in_array($i->status,['pending','active'],true),401);
            if ($i->status === 'active') abort_unless(hash_equals(Crypt::decryptString($i->secret),$clientSecret),409);
            else {
                DB::table('installations')->where('id',$id)->update(['secret'=>Crypt::encryptString($clientSecret),'status'=>'active','version'=>$version,'updated_at'=>now()]);
                Audit::record('installation.enrolled','connector',$brand->organization_id,$brand->id,['installation_id'=>$id]);
            }
            return ['installation_id'=>$id,'brand_id'=>$brand->id,'status'=>'active'];
        });
    }
    public static function signature(string $id, string $timestamp, string $nonce, string $method, string $path, string $body, string $secret): string
    {
        return hash_hmac('sha256',implode("\n",['v1',$id,$timestamp,$nonce,strtoupper($method),$path,hash('sha256',$body)]),$secret);
    }
}
