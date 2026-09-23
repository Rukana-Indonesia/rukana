<?php

namespace Database\Seeders;

use App\Enums\ResidencyStatus;
use App\Enums\Role;
use App\Models\Rt;
use App\Models\User;
use App\Models\Village;
use Illuminate\Database\Seeder;

/**
 * Satu akun untuk tiap peran + warga di tiap RT. Kata sandi semua akun: "password".
 *
 * Pola no. HP (format 08…):
 *   operator 081100000001, kepala desa 081100000002
 *   RW n           0811000 0n 00  (mis. RW 03 → 081100000300)
 *   RT m / RW n    0811000 0n 0m  (mis. RT 02/RW 03 → 081100000302)
 *   warga          0812000 n 0m 0k (mis. 081200030201; k = 01..03 aktif, 09 = menunggu verifikasi)
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        fake()->seed(2610);
        $village = Village::with('rws.rts')->firstOrFail();

        User::factory()->forVillage($village)->role(Role::Operator)
            ->create(['name' => 'Operator Desa', 'phone' => '6281100000001']);
        User::factory()->forVillage($village)->role(Role::KepalaDesa)
            ->create(['name' => 'Kepala Desa Sukamaju', 'phone' => '6281100000002']);

        foreach ($village->rws as $rw) {
            $n = $rw->number;
            User::factory()->forRw($rw)->role(Role::Rw)
                ->create(['name' => "Ketua {$rw->label()}", 'phone' => sprintf('628110000%02d00', $n)]);

            /** @var Rt $rt */
            foreach ($rw->rts as $rt) {
                $m = $rt->number;
                User::factory()->forRt($rt)->role(Role::Rt)
                    ->create(['name' => "Ketua {$rt->label()}/{$rw->label()}", 'phone' => sprintf('628110000%02d%02d', $n, $m)]);

                foreach ([1, 2, 3] as $k) {
                    User::factory()->forRt($rt)->create([
                        'phone' => sprintf('62812000%d%02d%02d', $n, $m, $k),
                        'residency_status' => fake()->randomElement(ResidencyStatus::cases()),
                    ]);
                }

                User::factory()->forRt($rt)->pending()->create(['phone' => sprintf('62812000%d%02d09', $n, $m)]);
            }
        }
    }
}
