<?php

use App\Enums\LetterFlow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FLOWS_WITH_VILLAGE = ['rt', 'rt_rw', 'rt_rw_village', 'village'];
    private const FLOWS_ORIGINAL = ['rt', 'rt_rw', 'rt_rw_village'];

    public function up(): void
    {
        Schema::table('service_types', function (Blueprint $table) {
            $table->enum('flow', self::FLOWS_WITH_VILLAGE)->change();
        });

        Schema::table('letter_requests', function (Blueprint $table) {
            $table->enum('flow', self::FLOWS_WITH_VILLAGE)->comment('Snapshot alur dari katalog')->change();
        });

        // Sesuaikan katalog Surat Keterangan Usaha (SKU) yang sudah ada di database.
        DB::table('service_types')
            ->where('slug', 'keterangan-usaha')
            ->update([
                'flow' => LetterFlow::Village->value,
                'requirements' => json_encode(['Surat Pengantar RT']),
                'requires_nik' => false,
                'form_schema' => json_encode([]),
                'notes' => 'Warga meminta surat pengantar fisik ke Ketua RT terlebih dahulu, lalu foto/scan dan unggah di sini.',
            ]);
    }

    public function down(): void
    {
        DB::table('service_types')
            ->where('slug', 'keterangan-usaha')
            ->update([
                'flow' => LetterFlow::RtRwVillage->value,
                'requirements' => json_encode(['KTP', 'Kartu Keluarga', 'Foto tempat usaha']),
                'notes' => null,
            ]);

        Schema::table('letter_requests', function (Blueprint $table) {
            $table->enum('flow', self::FLOWS_ORIGINAL)->comment('Snapshot alur dari katalog')->change();
        });

        Schema::table('service_types', function (Blueprint $table) {
            $table->enum('flow', self::FLOWS_ORIGINAL)->change();
        });
    }
};
