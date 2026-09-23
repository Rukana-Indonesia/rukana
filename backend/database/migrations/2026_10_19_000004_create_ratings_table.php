<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->string('rateable_type', 30)->comment('letter_request | complaint');
            $table->unsignedBigInteger('rateable_id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('score');
            $table->string('comment', 500)->nullable();
            $table->foreignId('village_id')->constrained()->restrictOnDelete();
            $table->foreignId('rw_id')->constrained()->restrictOnDelete();
            $table->foreignId('rt_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('complaint_categories')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['rateable_type', 'rateable_id']);
            $table->index(['village_id', 'created_at']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE ratings ADD CONSTRAINT ratings_score_check CHECK (score BETWEEN 1 AND 5)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
