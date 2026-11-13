<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('plans')->insertOrIgnore(['id'=>'00000000-0000-4000-8000-000000000001','name'=>'Launch','brand_limit'=>5,'seat_limit'=>10,'concurrency_limit'=>2,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        // No default credentials, provider keys, free credit grants or invented model prices.
    }
}
