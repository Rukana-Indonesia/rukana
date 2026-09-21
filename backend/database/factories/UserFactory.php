<?php

namespace Database\Factories;

use App\Enums\ResidencyStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Rt;
use App\Models\Rw;
use App\Models\User;
use App\Models\Village;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Default: warga aktif. Wilayah wajib diisi lewat state forRt()/forRw()/forVillage().
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '628'.fake()->unique()->numerify('##########'),
            'password' => static::$password ??= Hash::make('password'),
            'role' => Role::Warga,
            'residency_status' => ResidencyStatus::Tetap,
            'status' => UserStatus::Active,
            'verified_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    public function forRt(Rt $rt): static
    {
        return $this->state(['village_id' => $rt->village_id, 'rw_id' => $rt->rw_id, 'rt_id' => $rt->id]);
    }

    public function forRw(Rw $rw): static
    {
        return $this->state(['village_id' => $rw->village_id, 'rw_id' => $rw->id, 'rt_id' => null]);
    }

    public function forVillage(Village $village): static
    {
        return $this->state(['village_id' => $village->id, 'rw_id' => null, 'rt_id' => null]);
    }

    public function pending(): static
    {
        return $this->state(['status' => UserStatus::Pending, 'verified_at' => null]);
    }

    public function role(Role $role): static
    {
        return $this->state(fn () => [
            'role' => $role,
            'residency_status' => $role === Role::Warga ? ResidencyStatus::Tetap : null,
        ]);
    }
}
