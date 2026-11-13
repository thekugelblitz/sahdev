<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\User;
use App\Services\{OrganizationService,WalletService};
use App\Support\Totp;
use Illuminate\Support\Facades\{Artisan,DB};
if (config('database.default')!=='sqlite' || !preg_match('/^saas_ui_test_[a-z0-9_]+\.sqlite$/D',basename(config('database.connections.sqlite.database'))) || !app()->environment('testing')) throw new RuntimeException('Only a dedicated UI test database is allowed.');
Artisan::call('migrate:fresh',['--force'=>true]); Artisan::call('db:seed',['--force'=>true]);
$user=User::create(['name'=>'Launch Operator','email'=>'operator@example.test','password'=>'Fixture-password-12345']);
$user->forceFill(['email_verified_at'=>now(),'is_operator'=>true,'totp_secret'=>'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'])->save();
$service=app(OrganizationService::class); $org=$service->create($user,'HostingSpell workspace');
foreach (['CloudNest'=>'pooled','Rackwise'=>'allocated'] as $name=>$mode) {
    $brand=$service->addBrand($org,['name'=>$name,'whmcs_url'=>'https://billing.'.strtolower($name).'.example.com','credit_mode'=>$mode,'monthly_budget'=>500000000],$user->id);
    DB::table('brands')->where('id',$brand)->update(['domain_verified_at'=>now()]);
}
app(WalletService::class)->grant($org,1000000000,'ui-fixture','fixture','Browser fixture credits');
$allocated=DB::table('brands')->where('credit_mode','allocated')->first();
app(WalletService::class)->transfer($org,$allocated->id,300000000,true,'ui-fixture',$user->id);
echo json_encode(['org'=>$org,'allocated'=>$allocated->id]);
