<?php

namespace App\Console\Commands;

use App\Models\Announcement;
use App\Support\Notifications\Notifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Kirim notifikasi pengumuman terjadwal yang sudah waktunya terbit. Dijalankan
 * scheduler tiap menit (routes/console.php); aman dijalankan berulang karena
 * Notifier mengklaim tiap pengumuman secara atomik.
 */
#[Signature('announcements:notify')]
#[Description('Kirim notifikasi lonceng untuk pengumuman terjadwal yang sudah terbit')]
class NotifyAnnouncements extends Command
{
    public function handle(Notifier $notifier): int
    {
        $due = Announcement::whereNull('notified_at')->live()->orderBy('published_at')->get();

        foreach ($due as $announcement) {
            $sent = $notifier->announcementPublished($announcement);
            $this->line("Pengumuman #{$announcement->id}: {$sent} penerima.");
        }

        return self::SUCCESS;
    }
}
