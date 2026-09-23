<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STATUSES = ['submitted', 'verified', 'forwarded', 'in_progress', 'resolved', 'rejected'];

    private const LEVELS = ['rt', 'rw', 'village'];

    private const URGENCIES = ['low', 'medium', 'high'];

    public function up(): void
    {
        Schema::create('complaint_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('complaint_categories')->restrictOnDelete();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->enum('default_level', self::LEVELS)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('complaints', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique()->comment('LPR-YYMM-NNNN');
            $table->foreignId('reporter_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('village_id')->constrained()->restrictOnDelete();
            $table->foreignId('rw_id')->constrained()->restrictOnDelete();
            $table->foreignId('rt_id')->constrained()->restrictOnDelete();
            $table->string('title', 150);
            $table->text('description');
            $table->foreignId('reported_category_id')->constrained('complaint_categories')->restrictOnDelete()->comment('Pilihan pelapor');
            $table->foreignId('category_id')->constrained('complaint_categories')->restrictOnDelete()->comment('Kategori final');
            $table->enum('urgency', self::URGENCIES)->nullable();
            $table->enum('handling_level', self::LEVELS)->default('rt');
            $table->enum('status', self::STATUSES)->default('submitted');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('location_text')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->timestamps();

            $table->unique(['reporter_id', 'idempotency_key']);
            $table->index(['village_id', 'status', 'created_at']);
            $table->index(['rt_id', 'status']);
            $table->index(['rw_id', 'status']);
            $table->index(['handling_level', 'status']);
            $table->index(['category_id', 'created_at']);
            $table->index(['latitude', 'longitude']);
        });

        Schema::create('complaint_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 20)->default('local');
            $table->string('path');
            $table->string('mime', 100);
            $table->unsignedInteger('size_bytes');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('complaint_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained()->cascadeOnDelete();
            $table->enum('action', ['submitted', 'verified', 'forwarded', 'accepted', 'started', 'resolved', 'rejected', 'category_corrected']);
            $table->enum('from_level', self::LEVELS)->nullable();
            $table->enum('to_level', self::LEVELS)->nullable();
            $table->enum('from_status', self::STATUSES)->nullable();
            $table->enum('to_status', self::STATUSES)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['action', 'created_at']);
        });

        Schema::create('complaint_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('body');
            $table->boolean('is_internal')->default(false)->comment('true = catatan antar-pengurus');
            $table->timestamps();
        });

        Schema::create('complaint_ai_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->unique()->constrained()->cascadeOnDelete();
            $table->enum('status', ['pending', 'done', 'failed'])->default('pending');
            $table->string('model', 100)->nullable();
            $table->string('prompt_version', 20)->nullable();
            $table->foreignId('suggested_category_id')->nullable()->constrained('complaint_categories')->nullOnDelete();
            $table->enum('suggested_urgency', self::URGENCIES)->nullable();
            $table->enum('suggested_level', self::LEVELS)->nullable();
            $table->text('summary')->nullable();
            $table->json('raw_output')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('final_category_id')->nullable()->constrained('complaint_categories')->nullOnDelete();
            $table->enum('final_urgency', self::URGENCIES)->nullable();
            $table->enum('final_level', self::LEVELS)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaint_ai_labels');
        Schema::dropIfExists('complaint_comments');
        Schema::dropIfExists('complaint_steps');
        Schema::dropIfExists('complaint_photos');
        Schema::dropIfExists('complaints');
        Schema::dropIfExists('complaint_categories');
    }
};
