<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Warga bisa membatalkan pengajuan surat (status & aksi "cancelled"), dan titik
 * tengah peta diatur per desa oleh operator (sebelumnya dari .env).
 */
return new class extends Migration
{
    private const STATUSES = ['submitted', 'rt_verified', 'rw_approved', 'village_received', 'ready_for_pickup', 'completed', 'rejected'];

    private const ACTIONS = ['submitted', 'verified', 'approved', 'received', 'ready', 'picked_up', 'rejected'];

    public function up(): void
    {
        $this->letterEnums([...self::STATUSES, 'cancelled'], [...self::ACTIONS, 'cancelled']);

        Schema::table('villages', function (Blueprint $table) {
            $table->decimal('map_latitude', 10, 7)->nullable()->after('address')->comment('Titik tengah peta (balai desa)');
            $table->decimal('map_longitude', 10, 7)->nullable()->after('map_latitude');
            $table->unsignedTinyInteger('map_zoom')->nullable()->after('map_longitude');
        });
    }

    public function down(): void
    {
        Schema::table('villages', function (Blueprint $table) {
            $table->dropColumn(['map_latitude', 'map_longitude', 'map_zoom']);
        });

        $this->letterEnums(self::STATUSES, self::ACTIONS);
    }

    private function letterEnums(array $statuses, array $actions): void
    {
        Schema::table('letter_requests', function (Blueprint $table) use ($statuses) {
            $table->enum('status', $statuses)->default('submitted')->change();
        });

        Schema::table('letter_steps', function (Blueprint $table) use ($statuses, $actions) {
            $table->enum('action', $actions)->change();
            $table->enum('from_status', $statuses)->nullable()->change();
            $table->enum('to_status', $statuses)->change();
        });
    }
};
