<?php

namespace App\Support\Notifications;

use App\Enums\ComplaintStatus;
use App\Enums\LetterStatus;
use App\Enums\Level;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Announcement;
use App\Models\Complaint;
use App\Models\LetterRequest;
use App\Models\User;
use App\Notifications\RukanaNotice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Satu-satunya tempat yang memutuskan siapa menerima notifikasi lonceng (PRD 6.11):
 *
 * - pemohon/pelapor: setiap perubahan status surat atau laporannya;
 * - warga: akun diverifikasi, pengumuman baru untuk wilayahnya;
 * - pengurus: pekerjaan baru yang masuk ke antrean tingkatnya.
 *
 * Isi notifikasi tidak memuat NIK, dan nama pelapor tidak pernah dikirim ke pengurus
 * (tautannya membuka halaman yang tetap dicek Policy).
 */
class Notifier
{
    public function residentRegistered(User $resident): void
    {
        $resident->loadMissing('rt');

        $this->staff(Level::Rt, $resident->village_id, $resident->rw_id, $resident->rt_id)->each(
            fn (User $official) => $this->send($official, new RukanaNotice(
                'resident.registered',
                'Pendaftar baru menunggu verifikasi',
                "{$resident->name} mendaftar sebagai warga {$resident->rt->label()}. Pastikan benar tinggal di RT Anda.",
                '/app/warga',
                'user-search',
            )),
        );
    }

    public function accountVerified(User $resident): void
    {
        $this->send($resident, new RukanaNotice(
            'account.verified',
            'Akun Anda sudah aktif',
            'Ketua RT sudah memverifikasi akun Anda. Sekarang Anda bisa mengajukan surat dan membuat laporan.',
            '/app',
            'tick-circle',
        ));
    }

    public function letterSubmitted(LetterRequest $letter): void
    {
        $this->letterQueue($letter);
    }

    /** Dipanggil LetterWorkflow setelah status berpindah. */
    public function letterChanged(LetterRequest $letter): void
    {
        $letter->loadMissing(['serviceType', 'user']);
        $name = "{$letter->serviceType->name} ({$letter->code})";

        [$title, $body] = match ($letter->status) {
            LetterStatus::RtVerified => ['Pengantar diverifikasi RT', "{$name} sudah diverifikasi ketua RT dan diteruskan ke RW."],
            LetterStatus::RwApproved => ['Pengantar disetujui RW', "{$name} sudah disetujui RW dan diteruskan ke kantor desa."],
            LetterStatus::VillageReceived => ['Surat diterima desa', "{$name} sudah diterima dan sedang diproses kantor desa."],
            LetterStatus::ReadyForPickup => ['Surat siap diambil', "{$name} siap diambil di kantor desa.".($letter->serviceType->notes ? " {$letter->serviceType->notes}" : '')],
            LetterStatus::Completed => ['Surat selesai', "{$name} selesai. Bagaimana pelayanannya? Beri rating 1–5."],
            LetterStatus::Rejected => ['Pengajuan ditolak', "{$name} ditolak {$letter->rejected_level?->label()}: {$letter->rejection_reason}"],
            LetterStatus::Submitted => [null, null],
        };

        if ($title !== null) {
            $this->send($letter->user, new RukanaNotice("letter.{$letter->status->value}", $title, Str::limit($body, 240), "/app/surat/{$letter->id}", 'document-text'));
        }

        $this->letterQueue($letter);
    }

    public function complaintSubmitted(Complaint $complaint): void
    {
        $this->complaintQueue($complaint);
    }

    /** Dipanggil ComplaintWorkflow setelah aksi berhasil. */
    public function complaintChanged(Complaint $complaint, string $action, ?string $note = null): void
    {
        $complaint->loadMissing('reporter');
        $name = "\"{$complaint->title}\" ({$complaint->code})";
        $level = $complaint->handling_level->label();

        [$title, $body] = match ($action) {
            'verify' => ['Laporan diverifikasi', "Laporan {$name} sudah diverifikasi ketua RT."],
            'start', 'accept' => ['Laporan sedang ditangani', "Laporan {$name} sedang ditangani {$level}."],
            'forward' => ["Laporan diteruskan ke {$level}", "Laporan {$name} diteruskan ke {$level} supaya ditangani pihak yang tepat."],
            'resolve' => ['Laporan selesai', "Laporan {$name} selesai ditangani: {$note} Beri rating 1–5 untuk penanganannya."],
            'reject' => ['Laporan ditolak', "Laporan {$name} ditolak: {$note}"],
            default => [null, null],
        };

        if ($title !== null) {
            $this->send($complaint->reporter, new RukanaNotice("complaint.{$action}", $title, Str::limit($body, 240), "/app/laporan/{$complaint->id}", 'note'));
        }

        if ($action === 'forward') {
            $this->complaintQueue($complaint);
        }
    }

    /**
     * Kirim notifikasi pengumuman ke semua akun aktif di wilayah target, sekali saja.
     * Pengumuman terjadwal dikirim oleh perintah `announcements:notify` saat terbit.
     * Mengembalikan jumlah penerima.
     */
    public function announcementPublished(Announcement $announcement): int
    {
        // Klaim atomik: dua proses (simpan + scheduler) tidak bisa mengirim dua kali.
        $claimed = Announcement::whereKey($announcement->id)
            ->whereNull('notified_at')
            ->live()
            ->update(['notified_at' => now()]);

        if ($claimed === 0) {
            return 0;
        }

        $notice = new RukanaNotice(
            'announcement.published',
            "Pengumuman {$announcement->targetLabel()}",
            Str::limit($announcement->title, 150),
            "/app/pengumuman/{$announcement->id}",
            'sms',
            ['announcement_id' => $announcement->id],
        );
        $data = json_encode($notice->payload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now = now();
        $total = 0;

        // Insert massal per 500 akun; satu desa bisa berisi ribuan warga.
        $this->audience($announcement)->select('id')->chunkById(500, function ($users) use ($data, $now, &$total) {
            DB::table('notifications')->insert($users->map(fn (User $user) => [
                'id' => (string) Str::orderedUuid(),
                'type' => 'announcement.published',
                'notifiable_type' => $user->getMorphClass(),
                'notifiable_id' => $user->id,
                'data' => $data,
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
            $total += $users->count();
        });

        return $total;
    }

    /** Akun aktif yang menjadi sasaran pengumuman, selain pembuatnya. */
    private function audience(Announcement $announcement): Builder
    {
        return User::where('village_id', $announcement->village_id)
            ->where('status', UserStatus::Active)
            ->whereKeyNot($announcement->author_id)
            ->when($announcement->target_level === Level::Rw, fn (Builder $q) => $q->where('rw_id', $announcement->target_rw_id))
            ->when($announcement->target_level === Level::Rt, fn (Builder $q) => $q->where('rt_id', $announcement->target_rt_id));
    }

    /** Beri tahu pengurus tingkat yang sekarang harus memproses surat ini. */
    private function letterQueue(LetterRequest $letter): void
    {
        // Hanya saat surat berpindah ke tingkat baru. "Diterima desa" dan "siap diambil"
        // tetap di desa, jadi tidak perlu notifikasi ulang ke operator.
        if (! in_array($letter->status, [LetterStatus::Submitted, LetterStatus::RtVerified, LetterStatus::RwApproved], true)) {
            return;
        }

        $letter->loadMissing(['serviceType', 'rt', 'rw']);
        $title = match ($letter->current_level) {
            Level::Rt => 'Pengantar baru perlu diverifikasi',
            Level::Rw => 'Pengantar perlu persetujuan RW',
            Level::Village => 'Surat baru masuk ke desa',
        };

        $this->staff($letter->current_level, $letter->village_id, $letter->rw_id, $letter->rt_id)->each(
            fn (User $official) => $this->send($official, new RukanaNotice(
                'letter.queue',
                $title,
                "{$letter->serviceType->name} ({$letter->code}) dari warga {$letter->rt->label()}/{$letter->rw->label()}.",
                "/app/surat/{$letter->id}",
                'document-text',
            )),
        );
    }

    /** Beri tahu pengurus tingkat yang sekarang memegang laporan ini. */
    private function complaintQueue(Complaint $complaint): void
    {
        if ($complaint->status->isFinal()) {
            return;
        }

        $complaint->loadMissing(['rt', 'rw']);
        $title = $complaint->status === ComplaintStatus::Forwarded ? 'Laporan diteruskan ke Anda' : 'Laporan baru dari warga';

        $this->staff($complaint->handling_level, $complaint->village_id, $complaint->rw_id, $complaint->rt_id)->each(
            fn (User $official) => $this->send($official, new RukanaNotice(
                'complaint.queue',
                $title,
                Str::limit("\"{$complaint->title}\" ({$complaint->code}) di {$complaint->rt->label()}/{$complaint->rw->label()}.", 240),
                "/app/laporan/{$complaint->id}",
                'note',
            )),
        );
    }

    /**
     * ID berurutan waktu: kolom created_at hanya sampai detik, jadi ID dipakai sebagai
     * pengurut kedua supaya lonceng selalu menampilkan yang terbaru di atas.
     */
    private function send(User $user, RukanaNotice $notice): void
    {
        $notice->id = (string) Str::orderedUuid();
        $user->notify($notice);
    }

    /** Akun pengurus aktif di satu tingkat untuk wilayah sebuah tiket. */
    private function staff(Level $level, int $villageId, ?int $rwId, ?int $rtId): Builder
    {
        return User::where('village_id', $villageId)
            ->where('status', UserStatus::Active)
            ->where(fn (Builder $q) => match ($level) {
                Level::Rt => $q->where('role', Role::Rt)->where('rt_id', $rtId),
                Level::Rw => $q->where('role', Role::Rw)->where('rw_id', $rwId),
                Level::Village => $q->where('role', Role::Operator),
            });
    }
}
