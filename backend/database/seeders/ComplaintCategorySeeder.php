<?php

namespace Database\Seeders;

use App\Enums\Level;
use App\Models\ComplaintCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** Kategori laporan dua tingkat. Warga memilih subkategori; AI memakai ID yang sama. */
class ComplaintCategorySeeder extends Seeder
{
    public function run(): void
    {
        $tree = [
            'Infrastruktur' => [
                'Jalan Rusak' => Level::Village,
                'Lampu Jalan Mati' => Level::Rw,
                'Saluran Air Mampet' => Level::Rt,
            ],
            'Kebersihan' => ['Sampah Menumpuk' => Level::Rt],
            'Fasilitas Umum' => ['Fasilitas Umum Rusak' => Level::Rw],
            'Keamanan' => ['Gangguan Keamanan' => Level::Rt],
            'Lainnya' => ['Lainnya' => Level::Rt],
        ];

        $order = 0;
        foreach ($tree as $parentName => $children) {
            $parent = ComplaintCategory::create([
                'name' => $parentName,
                'slug' => Str::slug($parentName),
                'sort_order' => ++$order,
            ]);

            foreach ($children as $name => $level) {
                ComplaintCategory::create([
                    'parent_id' => $parent->id,
                    'name' => $name,
                    'slug' => $name === $parentName ? Str::slug($name).'-umum' : Str::slug($name),
                    'default_level' => $level,
                    'sort_order' => ++$order,
                ]);
            }
        }
    }
}
