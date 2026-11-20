<?php
namespace App\Http\Controllers;

use App\Services\TenantStore;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class LegacyArchiveController
{
    private const INITIAL_CHAIN = '0000000000000000000000000000000000000000000000000000000000000000';

    public function start(Request $request, string $id, TenantStore $tenants): array
    {
        $brand = $this->brand($request);
        $input = $request->validate([
            'chunks'=>'required|integer|min:1|max:1000000',
            'bytes'=>'required|integer|min:1|max:'.config('cloud.legacy_archive_max_bytes'),
            'chain'=>'required|string|regex:/^[a-f0-9]{64}$/D',
        ]);
        abort_if($input['bytes'] < $input['chunks'] * 56 || $input['bytes'] > $input['chunks'] * 180000,422);
        return Cache::lock('legacy-archive-start:'.$brand,30)->block(5,function () use ($request,$id,$tenants,$brand,$input) {
            return $tenants->run($brand,function (Connection $db) use ($request,$id,$input) {
                $record = $db->table('legacy_archives')->where('id',$id)->first();
                if (!$record) {
                    abort_if($db->table('legacy_archives')->count() >= 2,409,'Brand archive capacity reached.');
                    $db->table('legacy_archives')->insert($input+[
                        'id'=>$id,'installation_id'=>$request->attributes->get('installation')->id,
                        'received_chain'=>self::INITIAL_CHAIN,'created_at'=>now(),
                    ]);
                    $record = $db->table('legacy_archives')->where('id',$id)->firstOrFail();
                }
                abort_unless((int)$record->chunks === (int)$input['chunks'] && (int)$record->bytes === (int)$input['bytes']
                    && hash_equals($record->chain,$input['chain']),409,'Archive ID already has a different manifest.');
                return $this->status($record);
            });
        });
    }

    public function upload(Request $request, string $id, string $sequence, TenantStore $tenants): array
    {
        $brand = $this->brand($request);
        abort_if(strlen($request->getContent()) > 200000,413);
        $input = $request->validate(['ciphertext'=>'required|string|max:180000']);
        $encoded = $input['ciphertext'];
        $decoded = base64_decode($encoded,true);
        abort_unless($decoded !== false && strlen($decoded) >= 41 && base64_encode($decoded) === $encoded,422,'Invalid encoded chunk.');
        $digest = hash('sha256',$encoded);
        $sequence = (int) $sequence;
        return $tenants->run($brand,function (Connection $db) use ($id,$sequence,$encoded,$digest) {
            return $db->transaction(function () use ($db,$id,$sequence,$encoded,$digest) {
                $archive = $db->table('legacy_archives')->where('id',$id)->lockForUpdate()->first() ?? abort(404);
                if ($sequence <= $archive->received_chunks) {
                    $prior = $db->table('legacy_archive_chunks')->where('archive_id',$id)->where('sequence',$sequence)->value('digest');
                    abort_unless(is_string($prior) && hash_equals($prior,$digest),409,'Conflicting chunk replay.');
                    return $this->status($archive);
                }
                $size = $archive->received_bytes + strlen($encoded);
                abort_if($archive->completed_at || $sequence !== $archive->received_chunks + 1
                    || $sequence > $archive->chunks || $size > $archive->bytes,409,'Unexpected archive cursor.');
                $chain = hash('sha256',$archive->received_chain.$digest);
                $db->table('legacy_archive_chunks')->insert([
                    'archive_id'=>$id,'sequence'=>$sequence,'digest'=>$digest,'ciphertext'=>$encoded,
                ]);
                $db->table('legacy_archives')->where('id',$id)->update([
                    'received_chunks'=>$sequence,'received_bytes'=>$size,'received_chain'=>$chain,
                ]);
                return ['received_chunks'=>$sequence,'received_chain'=>$chain,'completed'=>false];
            });
        });
    }

    public function complete(Request $request, string $id, TenantStore $tenants): array
    {
        return $tenants->run($this->brand($request),function (Connection $db) use ($id) {
            return $db->transaction(function () use ($db,$id) {
                $archive = $db->table('legacy_archives')->where('id',$id)->lockForUpdate()->first() ?? abort(404);
                abort_unless($archive->chunks == $archive->received_chunks && $archive->bytes == $archive->received_bytes
                    && hash_equals($archive->chain,$archive->received_chain),409,'Archive is incomplete or differs from the manifest.');
                if (!$archive->completed_at) {
                    $db->table('legacy_archives')->where('id',$id)->update(['completed_at'=>now()]);
                    $archive->completed_at = now();
                }
                return $this->status($archive);
            });
        });
    }

    public function download(Request $request, string $id, string $sequence, TenantStore $tenants)
    {
        $chunk = $tenants->run($this->brand($request),function (Connection $db) use ($id,$sequence) {
            return $db->table('legacy_archive_chunks as chunk')->join('legacy_archives as archive','archive.id','=','chunk.archive_id')
                ->where('chunk.archive_id',$id)->where('chunk.sequence',(int)$sequence)->whereNotNull('archive.completed_at')
                ->first(['chunk.ciphertext','chunk.digest']) ?? abort(404);
        });
        return response()->json($chunk)->header('Cache-Control','private, no-store');
    }

    private function status(object $archive): array
    {
        return ['received_chunks'=>(int)$archive->received_chunks,'received_chain'=>$archive->received_chain,
            'completed'=>$archive->completed_at !== null];
    }

    private function brand(Request $request): string
    {
        abort_unless(config('cloud.thin_connector_preview'),503,'Legacy archive preview is disabled.');
        $brand = $request->attributes->get('brand');
        abort_unless($brand->status === 'active' && $brand->domain_verified_at
            && DB::table('organizations')->where('id',$brand->organization_id)->value('status') === 'active',423);
        abort_unless(DB::table('brand_databases')->where('brand_id',$brand->id)->where('schema_version','>=',2)->exists(),
            503,'Brand archive storage requires provisioning or upgrade.');
        return $brand->id;
    }
}
