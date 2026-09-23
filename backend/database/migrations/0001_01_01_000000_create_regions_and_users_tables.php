<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('villages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 20)->nullable()->unique()->comment('Kode desa Kemendagri');
            $table->string('district', 100)->nullable()->comment('Kecamatan');
            $table->string('regency', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('address')->nullable();
            $table->timestamps();
        });

        Schema::create('rws', function (Blueprint $table) {
            $table->id();
            $table->foreignId('village_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('name', 100)->nullable()->comment('Mis. nama dusun');
            $table->timestamps();

            $table->unique(['village_id', 'number']);
        });

        Schema::create('rts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rw_id')->constrained()->cascadeOnDelete();
            $table->foreignId('village_id')->constrained()->cascadeOnDelete()->comment('Denormalisasi untuk filter scope desa');
            $table->unsignedSmallInteger('number');
            $table->timestamps();

            $table->unique(['rw_id', 'number']);
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('phone', 20)->unique()->comment('Dinormalisasi ke format 62...');
            $table->string('password');
            $table->enum('role', ['warga', 'rt', 'rw', 'operator', 'kepala_desa']);
            $table->foreignId('village_id')->constrained()->restrictOnDelete();
            $table->foreignId('rw_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('rt_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('residency_status', ['tetap', 'kontrak', 'kos'])->nullable()->comment('Hanya untuk warga');
            $table->enum('status', ['pending', 'active', 'rejected', 'suspended'])->default('pending');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['rt_id', 'role', 'status']);
            $table->index(['village_id', 'role', 'status']);
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
        Schema::dropIfExists('rts');
        Schema::dropIfExists('rws');
        Schema::dropIfExists('villages');
    }
};
