<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda notifikasi pengumuman sudah dikirim. Pengumuman terjadwal baru dikirim
 * saat terbit (perintah `announcements:notify`), dan tidak pernah dua kali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->timestamp('notified_at')->nullable()->after('expires_at');
            $table->index(['notified_at', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropIndex(['notified_at', 'published_at']);
            $table->dropColumn('notified_at');
        });
    }
};
