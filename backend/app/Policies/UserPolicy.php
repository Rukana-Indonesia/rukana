<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * Wilayah target selalu dicek lewat scope akun yang login, jadi ketua RT 01
 * tidak bisa menyentuh warga RT 02 walau tahu ID-nya.
 */
class UserPolicy
{
    /** Lihat, verifikasi, dan tolak akun warga: ketua RT untuk RT-nya, operator untuk desanya. */
    public function manageResident(User $actor, User $target): bool
    {
        return $target->role === Role::Warga
            && in_array($actor->role, [Role::Rt, Role::Operator], true)
            && $actor->scope()->covers($target->village_id, $target->rw_id, $target->rt_id);
    }

    /** Akun pengurus dan perangkat desa hanya dikelola operator desa yang sama, bukan akunnya sendiri. */
    public function manageOfficial(User $actor, User $target): bool
    {
        return $actor->role === Role::Operator
            && $target->role->isStaff()
            && $target->village_id === $actor->village_id
            && ! $actor->is($target);
    }

    /** Reset kata sandi (tanpa OTP): warga oleh RT/operator, pengurus oleh operator. */
    public function resetPassword(User $actor, User $target): bool
    {
        return $target->role === Role::Warga
            ? $this->manageResident($actor, $target)
            : $this->manageOfficial($actor, $target);
    }
}
