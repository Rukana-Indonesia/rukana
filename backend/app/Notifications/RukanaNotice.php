<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Notifikasi in-app untuk ikon lonceng (PRD 6.11). Satu bentuk untuk semua jenis:
 * judul, isi singkat, dan tautan halaman di aplikasi Vue. Tidak ada email/SMS.
 *
 * `kind` disimpan di kolom `type`, jadi data lama tidak bergantung pada nama kelas PHP.
 */
class RukanaNotice extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
        public readonly string $icon = 'notification',
        public readonly array $subject = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return $this->kind;
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload();
    }

    /** Isi kolom `data`. Dipakai juga untuk insert massal (pengumuman ke banyak warga). */
    public function payload(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'icon' => $this->icon,
            ...$this->subject,
        ];
    }
}
