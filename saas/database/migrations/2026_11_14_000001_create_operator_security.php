<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('operator_security', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->boolean('mfa_required');
            $table->uuid('revision')->nullable();
        });
        DB::table('operator_security')->insert(['id'=>1,'mfa_required'=>false]);
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_security');
    }
};
