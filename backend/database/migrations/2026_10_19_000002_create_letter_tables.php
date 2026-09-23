<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STATUSES = ['submitted', 'rt_verified', 'rw_approved', 'village_received', 'ready_for_pickup', 'completed', 'rejected'];

    private const LEVELS = ['rt', 'rw', 'village'];

    public function up(): void
    {
        Schema::create('letter_requests', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique()->comment('SRT-YYMM-NNNN');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_type_id')->constrained()->restrictOnDelete();
            $table->enum('flow', ['rt', 'rt_rw', 'rt_rw_village'])->comment('Snapshot alur dari katalog');
            $table->foreignId('village_id')->constrained()->restrictOnDelete();
            $table->foreignId('rw_id')->constrained()->restrictOnDelete();
            $table->foreignId('rt_id')->constrained()->restrictOnDelete();
            $table->text('purpose');
            $table->json('form_data')->nullable();
            $table->text('nik_encrypted')->nullable();
            $table->string('nik_masked', 16)->nullable();
            $table->enum('status', self::STATUSES)->default('submitted');
            $table->enum('current_level', self::LEVELS)->nullable()->comment('NULL saat status final');
            $table->enum('rejected_level', self::LEVELS)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['village_id', 'status', 'created_at']);
            $table->index(['rt_id', 'status']);
            $table->index(['rw_id', 'status']);
        });

        Schema::create('letter_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('letter_request_id')->constrained()->cascadeOnDelete();
            $table->enum('level', self::LEVELS)->nullable()->comment('NULL untuk aksi warga (submitted)');
            $table->enum('action', ['submitted', 'verified', 'approved', 'received', 'ready', 'picked_up', 'rejected']);
            $table->enum('from_status', self::STATUSES)->nullable();
            $table->enum('to_status', self::STATUSES);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['level', 'action', 'created_at']);
        });

        Schema::create('letter_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('letter_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requirement_label')->nullable()->comment('Syarat yang dipenuhi');
            $table->string('disk', 20)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedInteger('size_bytes');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_attachments');
        Schema::dropIfExists('letter_steps');
        Schema::dropIfExists('letter_requests');
    }
};
