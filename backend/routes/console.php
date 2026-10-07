<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pengumuman terjadwal: kirim notifikasi lonceng saat terbit.
Schedule::command('announcements:notify')->everyMinute()->withoutOverlapping();
