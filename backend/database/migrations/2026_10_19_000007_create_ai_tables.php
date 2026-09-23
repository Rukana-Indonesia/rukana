<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('scope_level', ['rt', 'rw', 'village'])->comment('Snapshot scope saat percakapan dibuat');
            $table->unsignedBigInteger('scope_id');
            $table->string('title', 150)->nullable();
            $table->timestamps();
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_conversation_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['user', 'assistant']);
            $table->text('content');
            $table->string('model', 100)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('ai_tool_calls', function (Blueprint $table) {
            $table->id();
            $table->enum('feature', ['ask', 'smart_complaint', 'monthly_insight']);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('subject');
            $table->string('tool_name', 100);
            $table->json('arguments')->comment('Termasuk scope yang disisipkan backend');
            $table->json('result')->nullable()->comment('Agregat saja, tanpa data pribadi');
            $table->enum('status', ['ok', 'error', 'denied']);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['feature', 'created_at']);
        });

        Schema::create('monthly_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('village_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rw_id')->nullable()->constrained()->cascadeOnDelete()->comment('NULL = tingkat desa');
            $table->date('period')->comment('Tanggal 1 bulan tersebut');
            $table->json('metrics')->comment('Angka dihitung backend');
            $table->text('narrative')->nullable();
            $table->enum('status', ['pending', 'done', 'failed'])->default('pending');
            $table->string('model', 100)->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            // MySQL memperlakukan NULL sebagai nilai berbeda di UNIQUE, jadi keunikan
            // insight tingkat desa (rw_id NULL) dijaga juga di aplikasi.
            $table->unique(['village_id', 'rw_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_insights');
        Schema::dropIfExists('ai_tool_calls');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
    }
};
