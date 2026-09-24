<?php

namespace App\Support\Scope;

use App\Enums\Level;
use App\Enums\Role;
use App\Models\User;
use LogicException;

/**
 * Satu-satunya tempat yang menurunkan scope wilayah dari akun. Dipakai Policy,
 * query dasbor, ekspor, dan tool AI.
 */
class ScopeResolver
{
    public function for(User $user): RegionScope
    {
        return match ($user->role) {
            Role::Warga => new RegionScope($user->village_id, null, $user->rw_id, $user->rt_id, $user->id),
            Role::Rt => new RegionScope($user->village_id, Level::Rt, $user->rw_id, $this->require($user, 'rt_id')),
            Role::Rw => new RegionScope($user->village_id, Level::Rw, $this->require($user, 'rw_id')),
            Role::Operator, Role::KepalaDesa => new RegionScope($user->village_id, Level::Village),
        };
    }

    private function require(User $user, string $column): int
    {
        return $user->{$column} ?? throw new LogicException("Akun {$user->id} ({$user->role->value}) tidak punya {$column}.");
    }
}
