<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('name'); $t->string('email')->unique();
            $t->timestamp('email_verified_at')->nullable(); $t->string('password'); $t->rememberToken();
            $t->boolean('is_operator')->default(false); $t->text('totp_secret')->nullable();
            $t->bigInteger('totp_last_step')->default(0); $t->timestamps();
        });
        Schema::create('password_reset_tokens', function (Blueprint $t) { $t->string('email')->primary(); $t->string('token'); $t->timestamp('created_at')->nullable(); });
        Schema::create('sessions', function (Blueprint $t) { $t->string('id')->primary(); $t->uuid('user_id')->nullable()->index(); $t->string('ip_address',45)->nullable(); $t->text('user_agent')->nullable(); $t->longText('payload'); $t->integer('last_activity')->index(); });
        Schema::create('cache', function (Blueprint $t) { $t->string('key')->primary(); $t->mediumText('value'); $t->integer('expiration')->index(); });
        Schema::create('cache_locks', function (Blueprint $t) { $t->string('key')->primary(); $t->string('owner'); $t->integer('expiration'); });
        Schema::create('jobs', function (Blueprint $t) { $t->bigIncrements('id'); $t->string('queue')->index(); $t->longText('payload'); $t->unsignedTinyInteger('attempts'); $t->unsignedInteger('reserved_at')->nullable(); $t->unsignedInteger('available_at'); $t->unsignedInteger('created_at'); });
        Schema::create('failed_jobs', function (Blueprint $t) { $t->id(); $t->string('uuid')->unique(); $t->text('connection'); $t->text('queue'); $t->longText('payload'); $t->longText('exception'); $t->timestamp('failed_at')->useCurrent(); });
        Schema::create('plans', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('name'); $t->integer('brand_limit')->default(5); $t->integer('seat_limit')->default(10);
            $t->integer('concurrency_limit')->default(2); $t->boolean('active')->default(true); $t->timestamps();
        });
        Schema::create('organizations', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('name'); $t->foreignUuid('plan_id')->constrained();
            $t->string('status')->default('active'); $t->unsignedBigInteger('billing_client_id')->nullable()->unique();
            $t->timestamps();
        });
        Schema::create('memberships', function (Blueprint $t) {
            $t->id(); $t->foreignUuid('organization_id')->constrained(); $t->foreignUuid('user_id')->constrained();
            $t->string('role'); $t->unique(['organization_id','user_id']); $t->timestamps();
        });
        Schema::create('brands', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('organization_id')->constrained(); $t->string('name');
            $t->string('whmcs_url')->unique(); $t->string('status')->default('active'); $t->string('credit_mode')->default('pooled');
            $t->bigInteger('monthly_budget')->default(0); $t->string('domain_challenge'); $t->timestamp('domain_verified_at')->nullable();
            $t->boolean('retain_content')->default(false); $t->timestamps();
        });
        Schema::create('brand_memberships', function (Blueprint $t) {
            $t->id(); $t->foreignUuid('brand_id')->constrained(); $t->foreignUuid('user_id')->constrained(); $t->string('role'); $t->unique(['brand_id','user_id']);
        });
        Schema::create('brand_databases', function (Blueprint $t) {
            $t->foreignUuid('brand_id')->primary()->constrained(); $t->string('database_name')->unique(); $t->string('username'); $t->text('password'); $t->integer('schema_version')->default(1); $t->timestamp('created_at');
        });
        Schema::create('wallets', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('organization_id')->constrained(); $t->string('scope');
            $t->bigInteger('balance')->default(0); $t->bigInteger('reserved')->default(0); $t->unique(['organization_id','scope']);
        });
        Schema::create('budget_periods', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('brand_id')->constrained(); $t->string('month',7);
            $t->bigInteger('spent')->default(0); $t->bigInteger('reserved')->default(0); $t->unique(['brand_id','month']);
        });
        Schema::create('providers', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('name'); $t->string('driver'); $t->string('endpoint');
            $t->text('secret'); $t->boolean('active')->default(false); $t->timestamp('deleted_at')->nullable(); $t->timestamps();
        });
        Schema::create('ai_models', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('provider_id')->constrained(); $t->string('name'); $t->string('remote_model');
            $t->integer('max_input_bytes')->default(16000); $t->integer('max_output_tokens')->default(2048); $t->string('token_parameter')->default('max_tokens');
            $t->uuid('current_price_id')->nullable(); $t->boolean('supports_tools')->default(false); $t->boolean('active')->default(false); $t->timestamp('deleted_at')->nullable(); $t->timestamps();
        });
        Schema::create('model_prices', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('model_id')->constrained('ai_models');
            $t->bigInteger('input_rate'); $t->bigInteger('output_rate'); $t->bigInteger('cost_input_rate'); $t->bigInteger('cost_output_rate');
            $t->string('cost_currency',3)->default('USD'); $t->timestamp('created_at');
        });
        Schema::create('plan_models', function (Blueprint $t) { $t->foreignUuid('plan_id')->constrained(); $t->foreignUuid('model_id')->constrained('ai_models'); $t->primary(['plan_id','model_id']); });
        Schema::create('installations', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('brand_id')->constrained(); $t->string('enrollment_hash')->nullable();
            $t->timestamp('enrollment_expires_at')->nullable(); $t->text('secret')->nullable();
            $t->string('status')->default('pending'); $t->string('version')->nullable(); $t->timestamp('last_seen_at')->nullable(); $t->timestamps();
        });
        Schema::create('request_nonces', function (Blueprint $t) { $t->string('id')->primary(); $t->timestamp('expires_at')->index(); });
        Schema::create('reservations', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('organization_id')->constrained(); $t->foreignUuid('brand_id')->constrained();
            $t->foreignUuid('wallet_id')->constrained(); $t->foreignUuid('period_id')->constrained('budget_periods'); $t->foreignUuid('price_id')->constrained('model_prices');
            $t->foreignUuid('installation_id')->nullable()->constrained(); $t->string('actor'); $t->string('idempotency_key',100); $t->string('request_hash',64);
            $t->string('state')->default('reserved')->index(); $t->bigInteger('amount'); $t->bigInteger('charged')->default(0);
            $t->bigInteger('provider_cost')->default(0); $t->integer('input_tokens')->nullable(); $t->integer('output_tokens')->nullable();
            $t->text('payload'); $t->text('result')->nullable(); $t->string('error_code')->nullable(); $t->timestamp('expires_at');
            $t->timestamps(); $t->unique(['organization_id','idempotency_key']);
        });
        Schema::create('ledger_entries', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('organization_id')->constrained(); $t->foreignUuid('wallet_id')->constrained();
            $t->foreignUuid('brand_id')->nullable()->constrained(); $t->string('kind'); $t->bigInteger('amount');
            $t->bigInteger('balance_after'); $t->string('reference')->unique(); $t->string('actor'); $t->timestamp('created_at');
        });
        Schema::create('credit_packs', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('name'); $t->bigInteger('credits'); $t->bigInteger('price_minor');
            $t->string('currency',3); $t->boolean('active')->default(false); $t->timestamps();
        });
        Schema::create('promotions', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('code')->unique(); $t->integer('discount_percent'); $t->integer('max_redemptions');
            $t->integer('redemptions')->default(0); $t->timestamp('expires_at'); $t->boolean('active')->default(true); $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('organization_id')->constrained(); $t->foreignUuid('brand_id')->nullable()->constrained();
            $t->foreignUuid('pack_id')->constrained('credit_packs'); $t->foreignUuid('promotion_id')->nullable()->constrained('promotions');
            $t->string('idempotency_key',100); $t->string('request_hash',64); $t->string('state')->default('pending'); $t->string('description');
            $t->bigInteger('credits'); $t->bigInteger('price_minor'); $t->string('currency',3);
            $t->unsignedBigInteger('billing_invoice_id')->nullable()->unique(); $t->timestamps(); $t->unique(['organization_id','idempotency_key']);
        });
        Schema::create('billing_events', function (Blueprint $t) { $t->string('id')->primary(); $t->string('body_hash',64); $t->foreignUuid('order_id')->constrained('orders'); $t->string('kind'); $t->timestamp('created_at'); });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('organization_id')->nullable()->constrained(); $t->foreignUuid('brand_id')->nullable()->constrained();
            $t->string('actor'); $t->string('event'); $t->json('details'); $t->timestamp('created_at')->index();
        });
    }
    public function down(): void
    {
        foreach (['audit_events','billing_events','orders','promotions','credit_packs','ledger_entries','reservations','request_nonces','installations','plan_models','model_prices','ai_models','providers','budget_periods','wallets','brand_databases','brand_memberships','brands','memberships','organizations','plans','failed_jobs','jobs','cache_locks','cache','sessions','password_reset_tokens','users'] as $table) Schema::dropIfExists($table);
    }
};
