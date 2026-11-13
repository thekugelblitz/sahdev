<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('workflow_requests',function (Blueprint $t) {
            $t->foreignUuid('reservation_id')->primary()->constrained('reservations');
            $t->foreignUuid('installation_id')->constrained();
            $t->foreignUuid('brand_id')->constrained();
            $t->string('actor',100); $t->string('workflow',50); $t->string('revision',40);
            $t->unsignedBigInteger('ticket_id'); $t->json('record_links'); $t->timestamp('created_at');
        });
    }
    public function down(): void { Schema::dropIfExists('workflow_requests'); }
};
