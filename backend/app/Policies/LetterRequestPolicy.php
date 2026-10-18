<?php

namespace App\Policies;

use App\Enums\LetterStatus;
use App\Enums\Role;
use App\Models\LetterRequest;
use App\Models\User;
use App\Support\Letters\LetterWorkflow;

/**
 * Wilayah surat dibaca dari snapshot di surat itu sendiri (village/rw/rt saat
 * diajukan), bukan dari akun pemohon sekarang.
 */
class LetterRequestPolicy
{
    /** Pemohon melihat suratnya sendiri; pengurus dan perangkat desa melihat surat di wilayahnya. */
    public function view(User $user, LetterRequest $letter): bool
    {
        return $user->scope()->covers($letter->village_id, $letter->rw_id, $letter->rt_id, $letter->user_id);
    }

    /**
     * Pelaku harus berada di tingkat yang sedang memegang surat. Aksi yang tidak
     * berlaku untuk status sekarang lolos di sini dan ditolak workflow dengan 409,
     * supaya pengurus yang benar mendapat pesan "sudah berstatus …", bukan 403.
     */
    public function act(User $user, LetterRequest $letter, string $action): bool
    {
        $level = $user->role->actingLevel();
        $required = app(LetterWorkflow::class)->levelFor($action, $letter);

        return $level !== null
            && $this->view($user, $letter)
            && ($required === null || $required === $level);
    }

    /** Pemohon boleh membatalkan pengajuannya sendiri selama surat belum berstatus akhir. */
    public function cancel(User $user, LetterRequest $letter): bool
    {
        return $letter->user_id === $user->id && ! $letter->status->isFinal();
    }

    /** Pengantar bisa dicetak setelah diverifikasi RT, selama tidak ditolak. */
    public function print(User $user, LetterRequest $letter): bool
    {
        return $this->view($user, $letter)
            && ! in_array($letter->status, [LetterStatus::Submitted, LetterStatus::Rejected, LetterStatus::Cancelled], true);
    }

    /** Rating 1–5 oleh pemohon setelah surat selesai, sekali per surat (PRD 6.7). */
    public function rate(User $user, LetterRequest $letter): bool
    {
        return $letter->user_id === $user->id
            && $letter->status === LetterStatus::Completed
            && ! $letter->hasRating();
    }

    /** NIK utuh hanya dibuka ke operator desa yang memproses surat di kantor desa. */
    public function viewNik(User $user, LetterRequest $letter): bool
    {
        return $user->role === Role::Operator
            && $letter->nik_masked !== null
            && $this->view($user, $letter);
    }
}
