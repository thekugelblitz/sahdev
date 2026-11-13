<?php
namespace App\Console\Commands;
use App\Services\TenantStore;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
final class ProvisionBrand extends Command
{
    protected $signature = 'brand:provision {brand : Brand UUID}';
    protected $description = 'Provision an isolated PostgreSQL database and login for a brand using temporary operator credentials';
    public function handle(TenantStore $tenants): int
    {
        $brand = DB::table('brands')->where('id',$this->argument('brand'))->first();
        if (!$brand) { $this->error('Unknown brand.'); return self::FAILURE; }
        return Cache::lock('provision:'.$brand->id,120)->block(5,function () use ($brand,$tenants) {
            $db = DB::table('brand_databases')->where('brand_id',$brand->id)->first();
            if ($db && $db->schema_version === 1) { $this->info('Already provisioned.'); return self::SUCCESS; }
            // Read only process environment: privileged credentials must never live in the web app .env/config cache.
            $dsn = getenv('SAHDEV_PROVISION_DSN'); $user = getenv('SAHDEV_PROVISION_USER'); $password = getenv('SAHDEV_PROVISION_PASSWORD');
            if (!$dsn || !$user || !str_starts_with($dsn,'pgsql:')) { $this->error('Set SAHDEV_PROVISION_DSN, SAHDEV_PROVISION_USER and SAHDEV_PROVISION_PASSWORD for this command only.'); return self::FAILURE; }
            try {
                $admin = new \PDO($dsn,$user,$password ?: '',[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
                if (!$db) {
                    $suffix = str_replace('-','',$brand->id); $name = 'sd_t_'.$suffix; $role = 'sd_b_'.$suffix;
                    if (!preg_match('/^[a-f0-9]{32}$/D',$suffix)) throw new \RuntimeException('Invalid generated identifier.');
                    DB::table('brand_databases')->insert(['brand_id'=>$brand->id,'database_name'=>$name,'username'=>$role,'password'=>Crypt::encryptString(bin2hex(random_bytes(32))),'schema_version'=>0,'created_at'=>now()]);
                    $db = DB::table('brand_databases')->where('brand_id',$brand->id)->first();
                }
                if (!preg_match('/^sd_[tb]_[a-f0-9]{32}$/D',$db->database_name) || !preg_match('/^sd_b_[a-f0-9]{32}$/D',$db->username)) throw new \RuntimeException('Invalid generated database identifier.');
                $exists = $admin->prepare('SELECT 1 FROM pg_roles WHERE rolname = ?'); $exists->execute([$db->username]);
                if (!$exists->fetchColumn()) $admin->exec('CREATE ROLE "'.$db->username.'" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT PASSWORD '.$admin->quote(Crypt::decryptString($db->password)));
                $exists = $admin->prepare('SELECT 1 FROM pg_database WHERE datname = ?'); $exists->execute([$db->database_name]);
                if (!$exists->fetchColumn()) $admin->exec('CREATE DATABASE "'.$db->database_name.'" OWNER "'.$db->username.'"');
                $admin->exec('REVOKE ALL ON DATABASE "'.$db->database_name.'" FROM PUBLIC');
                $admin->exec('GRANT CONNECT ON DATABASE "'.$db->database_name.'" TO "'.$db->username.'"');
                $tenants->migrate($db);
                $tenants->connect($db,function ($connection) { $connection->statement('REVOKE ALL ON SCHEMA public FROM PUBLIC'); });
                DB::table('brand_databases')->where('brand_id',$brand->id)->update(['schema_version'=>1]);
                Audit::record('tenant.provisioned','console',$brand->organization_id,$brand->id,['database_name'=>$db->database_name]);
                $this->info('Brand database provisioned.'); return self::SUCCESS;
            } catch (\Throwable $e) {
                // SQL exceptions can contain password literals. Never echo them or put them in application logs.
                $this->error('Provisioning failed. Check operator database permissions and connectivity. The pending record preserves credentials for a safe retry.'); return self::FAILURE;
            }
        });
    }
}
