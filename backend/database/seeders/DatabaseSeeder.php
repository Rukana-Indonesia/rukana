<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Data dummy untuk pengembangan dan demo. Semua nama dan nomor HP fiktif;
 * jangan dipakai di server produksi desa.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RegionSeeder::class,
            UserSeeder::class,
            ServiceTypeSeeder::class,
            ComplaintCategorySeeder::class,
        ]);
    }
}
