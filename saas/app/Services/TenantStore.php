<?php
namespace App\Services;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/** Never changes the default/control-plane connection; each use purges its tenant connection. */
final class TenantStore
{
    public function run(string $brand, callable $callback)
    {
        $db = DB::table('brand_databases')->where('brand_id',$brand)->where('schema_version','>=',1)->first() ?? abort(503,'This brand database is awaiting provisioning.');
        return $this->connect($db,$callback);
    }
    public function connect(object $db, callable $callback)
    {
        $name = 'brand_'.str_replace('-','',$db->brand_id);
        $config = ['driver'=>'pgsql','host'=>config('cloud.tenant_host'),'port'=>config('cloud.tenant_port'),'database'=>$db->database_name,'username'=>$db->username,'password'=>Crypt::decryptString($db->password),'charset'=>'utf8','prefix'=>'','search_path'=>'public','sslmode'=>config('cloud.tenant_sslmode')];
        if (app()->environment('testing') && str_ends_with($db->database_name,'.sqlite')) $config = ['driver'=>'sqlite','database'=>$db->database_name,'prefix'=>'','foreign_key_constraints'=>true];
        config(['database.connections.'.$name=>$config]);
        try { return $callback(DB::connection($name)); }
        finally { DB::purge($name); config(['database.connections.'.$name=>null]); }
    }
    public function migrate(object $db): void
    {
        $this->connect($db,function (Connection $connection) {
            $schema = $connection->getSchemaBuilder();
            if (!$schema->hasTable('inference_records')) $schema->create('inference_records',function (Blueprint $t) { $t->uuid('id')->primary(); $t->string('actor'); $t->text('content')->nullable(); $t->timestamp('created_at')->index(); });
            if (!$schema->hasTable('legacy_archives')) $schema->create('legacy_archives', function (Blueprint $table) {
                $table->char('id',32)->primary();
                $table->uuid('installation_id');
                $table->unsignedInteger('chunks');
                $table->unsignedBigInteger('bytes');
                $table->char('chain',64);
                $table->unsignedInteger('received_chunks')->default(0);
                $table->unsignedBigInteger('received_bytes')->default(0);
                $table->char('received_chain',64);
                $table->timestamp('created_at');
                $table->timestamp('completed_at')->nullable();
            });
            if (!$schema->hasTable('legacy_archive_chunks')) $schema->create('legacy_archive_chunks', function (Blueprint $table) {
                $table->char('archive_id',32);
                $table->unsignedInteger('sequence');
                $table->char('digest',64);
                $table->text('ciphertext');
                $table->primary(['archive_id','sequence']);
                $table->foreign('archive_id')->references('id')->on('legacy_archives')->cascadeOnDelete();
            });
        });
    }
    public function record(object $reservation, array $payload, array $result): void
    {
        $retain = DB::table('brands')->where('id',$reservation->brand_id)->value('retain_content');
        $this->run($reservation->brand_id,function (Connection $db) use ($reservation,$payload,$result,$retain) {
            $db->table('inference_records')->insertOrIgnore(['id'=>$reservation->id,'actor'=>$reservation->actor,'content'=>$retain ? Crypt::encryptString(json_encode(['messages'=>$payload['messages'],'result'=>$result],JSON_THROW_ON_ERROR)) : null,'created_at'=>now()]);
        });
    }
}
