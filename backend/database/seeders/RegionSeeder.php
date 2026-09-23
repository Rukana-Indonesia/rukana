<?php

namespace Database\Seeders;

use App\Models\Village;
use Illuminate\Database\Seeder;

/** 1 desa, 2 RW percontohan, masing-masing 2 RT (sesuai target uji coba PRD). */
class RegionSeeder extends Seeder
{
    public function run(): void
    {
        $village = Village::create([
            'name' => 'Sukamaju',
            'district' => 'Cibeunying',
            'regency' => 'Kabupaten Contoh',
            'province' => 'Jawa Barat',
            'address' => 'Jl. Raya Sukamaju No. 1',
        ]);

        foreach ([3 => 'Dusun Krajan', 4 => 'Dusun Babakan'] as $number => $name) {
            $rw = $village->rws()->create(['number' => $number, 'name' => $name]);

            foreach ([1, 2] as $rtNumber) {
                $rw->rts()->create(['number' => $rtNumber, 'village_id' => $village->id]);
            }
        }
    }
}
