<?php
use App\Models\User;
use App\Services\{BillingService,TenantStore,WalletService};
use App\Support\Audit;
use Illuminate\Support\Facades\{Artisan,DB,Schedule};
use Illuminate\Support\Str;

Artisan::command('platform:operator {email} {--create}',function () {
    $email = strtolower($this->argument('email'));
    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) { $this->error('Invalid email.'); return 1; }
    $user = User::where('email',$email)->first();
    if (!$user && $this->option('create')) {
        $name = $this->ask('Operator name'); $password = $this->secret('Password (12+ characters, letters and numbers)');
        if (!$name || strlen($password ?? '') < 12 || !preg_match('/[a-z]/i',$password) || !preg_match('/[0-9]/',$password)) { $this->error('Password does not meet requirements.'); return 1; }
        $user = User::create(['name'=>$name,'email'=>$email,'password'=>$password]);
    }
    if (!$user) { $this->error('Create the account first, or use --create.'); return 1; }
    $user->forceFill(['is_operator'=>true,'email_verified_at'=>now()])->save();
    Audit::record('operator.granted','console',null,null,['user_id'=>$user->id]);
    $this->info('Operator enabled. Sign in and enroll an authenticator before accessing Command Station.');
})->purpose('Bootstrap a trusted HostingSpell operator; console access is required');

Artisan::command('platform:revoke-operator {email}',function () {
    $user = User::where('email',strtolower($this->argument('email')))->firstOrFail();
    $user->forceFill(['is_operator'=>false,'totp_secret'=>null,'totp_last_step'=>0])->save();
    DB::table('sessions')->where('user_id',$user->id)->delete();
    Audit::record('operator.revoked','console',null,null,['user_id'=>$user->id]); $this->info('Operator access revoked.');
});

Artisan::command('billing:attach {order} {invoice}',function (BillingService $billing) {
    $billing->attach($this->argument('order'),(int)$this->argument('invoice'));
    $this->info('Verified and linked. The existing invoice will be reconciled; no second invoice was created.');
});

Artisan::command('billing:reconcile',function (BillingService $billing) {
    if (!config('cloud.billing_url') || !config('cloud.billing_secret')) { $this->info('Billing not configured.'); return 0; }
    $failures = 0;
    foreach (DB::table('orders')->whereNotNull('billing_invoice_id')->where('state','awaiting_payment')->orderBy('created_at')->limit(100)->get() as $order) {
        try {
            $event = ['event_id'=>'reconcile:'.$order->id,'invoice_id'=>$order->billing_invoice_id,'kind'=>'paid'];
            $billing->event($event,hash('sha256',json_encode($event)));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { if ($e->getStatusCode() !== 409) $failures++; }
        catch (\Throwable $e) { $failures++; }
    }
    $this->info('Billing reconciliation completed; failures: '.$failures); return $failures ? 1 : 0;
});

Artisan::command('credits:reconcile',function () {
    $errors = [];
    foreach (DB::table('organizations')->get() as $org) DB::transaction(function () use ($org,&$errors) {
        DB::table('organizations')->where('id',$org->id)->lockForUpdate()->first();
        foreach (DB::table('wallets')->where('organization_id',$org->id)->get() as $wallet) {
            $ledger = (int)DB::table('ledger_entries')->where('wallet_id',$wallet->id)->sum('amount');
            $holds = (int)DB::table('reservations')->where('wallet_id',$wallet->id)->whereIn('state',['reserved','dispatched','uncertain'])->sum('amount');
            if ($wallet->balance != $ledger || $wallet->reserved != $holds) $errors[] = $wallet->id;
        }
        foreach (DB::table('budget_periods')->whereIn('brand_id',DB::table('brands')->where('organization_id',$org->id)->select('id'))->get() as $period) {
            $spent = (int)DB::table('reservations')->where('period_id',$period->id)->where('state','settled')->sum('charged');
            $held = (int)DB::table('reservations')->where('period_id',$period->id)->whereIn('state',['reserved','dispatched','uncertain'])->sum('amount');
            if ($period->spent != $spent || $period->reserved != $held) $errors[] = $period->id;
        }
    });
    if ($errors) { $this->error('Ledger/balance mismatch: '.implode(', ',$errors)); return 1; }
    $this->info('All wallet balances, reservations and monthly budgets reconcile.'); return 0;
});

Artisan::command('platform:maintain',function (WalletService $wallet,TenantStore $tenants) {
    foreach (DB::table('reservations')->where('state','reserved')->where('expires_at','<',now())->limit(500)->get() as $r) {
        try { $wallet->release($r->id,'queue_expired'); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { if ($e->getStatusCode() !== 409) throw $e; }
    }
    foreach (DB::table('reservations')->where('state','dispatched')->where('updated_at','<',now()->subMinutes(5))->limit(500)->get() as $r) $wallet->uncertain($r->id,'worker_lease_expired');
    DB::table('orders')->where('state','creating_invoice')->where('updated_at','<',now()->subMinutes(5))->update(['state'=>'invoice_review','updated_at'=>now()]);
    DB::table('request_nonces')->where('expires_at','<',now())->delete();
    DB::table('installations')->where('enrollment_expires_at','<',now())->update(['enrollment_hash'=>null]);
    DB::table('reservations')->whereIn('state',['settled','released'])->where('updated_at','<',now()->subDays(config('cloud.retention_days')))->update(['payload'=>'','result'=>null]);
    foreach (DB::table('brand_databases')->where('schema_version',1)->get() as $db) {
        try { $tenants->run($db->brand_id,fn ($c) => $c->table('inference_records')->where('created_at','<',now()->subDays(config('cloud.retention_days')))->delete()); }
        catch (\Throwable $e) { $this->warn('Tenant retention pending: '.$db->brand_id); }
    }
    $this->info('Expired work and retention processed. Dispatched requests retain credit holds until reconciled.');
});
Schedule::command('platform:maintain')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('billing:reconcile')->everyFifteenMinutes()->withoutOverlapping(15);
Schedule::command('credits:reconcile')->hourly()->withoutOverlapping(15);
