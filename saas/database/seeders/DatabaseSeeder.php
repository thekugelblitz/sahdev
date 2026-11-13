<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('plans')->insertOrIgnore(['id'=>'00000000-0000-4000-8000-000000000001','name'=>'Launch','brand_limit'=>5,'seat_limit'=>10,'concurrency_limit'=>2,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        foreach ([['101','Starter',499],['102','Growth',1499],['103','Scale',3999]] as [$suffix,$name,$price]) {
            DB::table('credit_packs')->insertOrIgnore(['id'=>'00000000-0000-4000-8000-000000000'.$suffix,'name'=>$name,'credits'=>$price*1000000,'price_minor'=>$price*100,'currency'=>'INR','active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        }
        // No credentials, grants, subscription commitments or invented model rates.
        // insertOrIgnore preserves operator edits and archived packages on redeploy.
    }
}
