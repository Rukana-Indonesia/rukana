<?php

namespace App\Policies;

use App\Enums\Level;
use App\Enums\Role;
use App\Models\Announcement;
use App\Models\User;

/**
 * Pengumuman (PRD 6.6 dan tabel hak akses): RT mengumumkan untuk RT-nya, RW untuk
 * RW-nya, operator untuk seluruh desa atau RW/RT mana pun di desanya. Kepala desa
 * dan warga hanya membaca.
 */
class AnnouncementPolicy
{
    /**
     * Pengumuman yang ditujukan ke wilayah akun ini dan sudah terbit. Yang terjadwal
     * atau kedaluwarsa hanya terlihat oleh pengurus yang mengelolanya.
     */
    public function view(User $user, Announcement $announcement): bool
    {
        if ($this->manage($user, $announcement)) {
            return true;
        }

        return Announcement::visibleTo($user)->live()->whereKey($announcement->id)->exists();
    }

    public function create(User $user): bool
    {
        return in_array($user->role, [Role::Rt, Role::Rw, Role::Operator], true);
    }

    /** Ubah dan hapus: pengurus di tingkat target yang sama, atau operator desa. */
    public function manage(User $user, Announcement $announcement): bool
    {
        if ($announcement->village_id !== $user->village_id) {
            return false;
        }

        return match ($user->role) {
            Role::Operator => true,
            Role::Rw => $announcement->target_level === Level::Rw && $announcement->target_rw_id === $user->rw_id,
            Role::Rt => $announcement->target_level === Level::Rt && $announcement->target_rt_id === $user->rt_id,
            default => false,
        };
    }
}
