<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 100)->comment('Mis. letter.status_changed, auth.access_denied');
            $table->nullableMorphs('subject');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['action', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('ticket_sequences', function (Blueprint $table) {
            $table->string('prefix', 5);
            $table->char('period', 4)->comment('YYMM');
            $table->unsignedInteger('last_number')->default(0);

            $table->primary(['prefix', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_sequences');
        Schema::dropIfExists('audit_logs');
    }
};
