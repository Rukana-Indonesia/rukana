<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pengumuman terjadwal: kirim notifikasi lonceng saat terbit.
Schedule::command('announcements:notify')->everyMinute()->withoutOverlapping();

// Monthly Insight bulan lalu untuk desa dan tiap RW (butuh queue worker yang jalan).
Schedule::command('insights:generate')->monthlyOn(1, '06:00')->timezone('Asia/Jakarta');
