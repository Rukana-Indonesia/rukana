<?php

namespace App\Policies;

use App\Models\Complaint;
use App\Models\User;
use App\Support\Complaints\ComplaintWorkflow;

/**
 * Wilayah laporan dibaca dari snapshot di laporan itu sendiri (RT pelapor saat
 * melapor). Identitas pelapor dan catatan internal hanya untuk pengurus yang
 * menangani: tingkat yang pernah memegang laporan (PRD 6.4).
 */
class ComplaintPolicy
{
    /** Pelapor melihat laporannya sendiri; pengurus dan perangkat desa melihat laporan di wilayahnya. */
    public function view(User $user, Complaint $complaint): bool
    {
        return $user->scope()->covers($complaint->village_id, $complaint->rw_id, $complaint->rt_id, $complaint->reporter_id);
    }

    /**
     * Pelaku harus berada di tingkat yang sedang memegang laporan. Aksi yang tidak
     * berlaku untuk status sekarang lolos di sini dan ditolak workflow dengan 409.
     */
    public function act(User $user, Complaint $complaint, string $action): bool
    {
        $level = $user->role->actingLevel();
        $required = app(ComplaintWorkflow::class)->levelFor($action, $complaint);

        return $level !== null
            && $this->view($user, $complaint)
            && ($required === null || $required === $level);
    }

    /** Koreksi kategori dan urgensi oleh tingkat yang sedang memegang laporan. */
    public function classify(User $user, Complaint $complaint): bool
    {
        return ! $complaint->status->isFinal()
            && $user->role->actingLevel() === $complaint->handling_level
            && $this->view($user, $complaint);
    }

    /** Pengurus di tingkat yang pernah memegang laporan ini. */
    public function handle(User $user, Complaint $complaint): bool
    {
        $level = $user->role->actingLevel();

        return $level !== null
            && $this->view($user, $complaint)
            && in_array($level, $complaint->handledLevels(), true);
    }

    /** Nama dan no. HP pelapor: pelapor sendiri dan pengurus yang menangani. */
    public function viewReporter(User $user, Complaint $complaint): bool
    {
        return $complaint->reporter_id === $user->id || $this->handle($user, $complaint);
    }

    /** Tanggapan yang terlihat pelapor. Pelapor bisa membalas selama laporan belum selesai. */
    public function comment(User $user, Complaint $complaint): bool
    {
        if ($complaint->reporter_id === $user->id) {
            return ! $complaint->status->isFinal();
        }

        return $this->handle($user, $complaint);
    }

    /** Catatan antar-pengurus, tidak terlihat pelapor. */
    public function commentInternal(User $user, Complaint $complaint): bool
    {
        return $this->handle($user, $complaint);
    }
}
