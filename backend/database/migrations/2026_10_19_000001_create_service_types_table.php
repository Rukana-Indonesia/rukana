<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('village_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 100);
            $table->string('name', 150);
            $table->text('purpose')->nullable()->comment('Kegunaan surat');
            $table->json('requirements')->comment('Daftar syarat dokumen');
            $table->enum('flow', ['rt', 'rt_rw', 'rt_rw_village']);
            $table->unsignedSmallInteger('estimated_days')->nullable();
            $table->text('notes')->nullable();
            $table->json('form_schema')->nullable()->comment('Field dinamis form pengajuan');
            $table->boolean('requires_nik')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['village_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_types');
    }
};
