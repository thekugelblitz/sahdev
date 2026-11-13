<?php
namespace App\Support;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
final class Audit
{
    public static function record(string $event, string $actor, ?string $org = null, ?string $brand = null, array $details = []): void
    {
        // Callers supply identifiers and non-secret deltas, never request bodies or credentials.
        DB::table('audit_events')->insert(['id'=>(string)Str::uuid(),'organization_id'=>$org,'brand_id'=>$brand,'actor'=>$actor,'event'=>$event,'details'=>json_encode($details, JSON_THROW_ON_ERROR),'created_at'=>now()]);
    }
}
