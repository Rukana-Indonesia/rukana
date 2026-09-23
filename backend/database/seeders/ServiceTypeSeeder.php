<?php

namespace Database\Seeders;

use App\Enums\LetterFlow;
use App\Models\Village;
use Illuminate\Database\Seeder;

/** Contoh katalog layanan. Isi sebenarnya disesuaikan setelah observasi di desa. */
class ServiceTypeSeeder extends Seeder
{
    public function run(): void
    {
        $village = Village::firstOrFail();

        $services = [
            [
                'slug' => 'pengantar-domisili',
                'name' => 'Surat Pengantar Keterangan Domisili',
                'purpose' => 'Bukti tempat tinggal untuk keperluan administrasi, sekolah, bank, atau pekerjaan.',
                'requirements' => ['KTP', 'Kartu Keluarga'],
                'flow' => LetterFlow::RtRwVillage,
                'estimated_days' => 2,
                'notes' => 'Bawa KTP asli saat mengambil surat di kantor desa.',
                'requires_nik' => true,
                'form_schema' => [
                    ['key' => 'lama_tinggal', 'label' => 'Lama tinggal di alamat ini', 'type' => 'text', 'required' => true],
                ],
            ],
            [
                'slug' => 'pengantar-skck',
                'name' => 'Surat Pengantar SKCK',
                'purpose' => 'Pengantar untuk mengurus Surat Keterangan Catatan Kepolisian di Polsek/Polres.',
                'requirements' => ['KTP', 'Kartu Keluarga', 'Pas foto 4x6 latar merah (2 lembar)'],
                'flow' => LetterFlow::RtRwVillage,
                'estimated_days' => 2,
                'notes' => 'Bawa KTP asli dan pas foto saat mengambil surat.',
                'requires_nik' => true,
                'form_schema' => [
                    ['key' => 'keperluan_skck', 'label' => 'Keperluan SKCK', 'type' => 'select', 'required' => true, 'options' => ['Melamar kerja', 'Pendidikan', 'Lainnya']],
                ],
            ],
            [
                'slug' => 'keterangan-usaha',
                'name' => 'Surat Keterangan Usaha',
                'purpose' => 'Keterangan bahwa warga memiliki usaha, mis. untuk pengajuan KUR atau izin usaha mikro.',
                'requirements' => ['KTP', 'Kartu Keluarga', 'Foto tempat usaha'],
                'flow' => LetterFlow::RtRwVillage,
                'estimated_days' => 3,
                'notes' => null,
                'requires_nik' => true,
                'form_schema' => [
                    ['key' => 'nama_usaha', 'label' => 'Nama usaha', 'type' => 'text', 'required' => true],
                    ['key' => 'jenis_usaha', 'label' => 'Jenis usaha', 'type' => 'select', 'required' => true, 'options' => ['Warung/toko', 'Makanan & minuman', 'Jasa', 'Pertanian/peternakan', 'Lainnya']],
                    ['key' => 'alamat_usaha', 'label' => 'Alamat usaha', 'type' => 'text', 'required' => true],
                    ['key' => 'lama_usaha', 'label' => 'Lama usaha berjalan (tahun)', 'type' => 'number', 'required' => false],
                ],
            ],
            [
                'slug' => 'keterangan-tidak-mampu',
                'name' => 'Surat Keterangan Tidak Mampu (SKTM)',
                'purpose' => 'Keterangan kondisi ekonomi untuk keringanan biaya sekolah atau layanan kesehatan.',
                'requirements' => ['KTP', 'Kartu Keluarga', 'Foto rumah tampak depan'],
                'flow' => LetterFlow::RtRwVillage,
                'estimated_days' => 3,
                'notes' => 'Pengurus RT dapat melakukan pengecekan lapangan sebelum verifikasi.',
                'requires_nik' => true,
                'form_schema' => [
                    ['key' => 'keperluan_sktm', 'label' => 'Digunakan untuk', 'type' => 'select', 'required' => true, 'options' => ['Pendidikan', 'Kesehatan', 'Lainnya']],
                ],
            ],
            [
                'slug' => 'pengantar-pindah',
                'name' => 'Surat Pengantar Pindah',
                'purpose' => 'Pengantar untuk mengurus surat pindah domisili ke luar desa.',
                'requirements' => ['KTP', 'Kartu Keluarga'],
                'flow' => LetterFlow::RtRwVillage,
                'estimated_days' => 3,
                'notes' => 'Proses selanjutnya dilakukan di Disdukcapil.',
                'requires_nik' => true,
                'form_schema' => [
                    ['key' => 'alamat_tujuan', 'label' => 'Alamat tujuan pindah', 'type' => 'text', 'required' => true],
                    ['key' => 'jumlah_anggota', 'label' => 'Jumlah anggota keluarga yang ikut pindah', 'type' => 'number', 'required' => false],
                ],
            ],
            [
                'slug' => 'izin-keramaian',
                'name' => 'Surat Pengantar Izin Keramaian',
                'purpose' => 'Pemberitahuan acara (hajatan, kegiatan warga) yang melibatkan banyak orang.',
                'requirements' => ['KTP'],
                'flow' => LetterFlow::RtRw,
                'estimated_days' => 1,
                'notes' => null,
                'requires_nik' => false,
                'form_schema' => [
                    ['key' => 'nama_acara', 'label' => 'Nama acara', 'type' => 'text', 'required' => true],
                    ['key' => 'tanggal_acara', 'label' => 'Tanggal acara', 'type' => 'date', 'required' => true],
                    ['key' => 'tempat_acara', 'label' => 'Tempat acara', 'type' => 'text', 'required' => true],
                ],
            ],
            [
                'slug' => 'pengantar-rt',
                'name' => 'Surat Pengantar RT (Umum)',
                'purpose' => 'Pengantar dari RT untuk keperluan umum di luar layanan lain.',
                'requirements' => ['KTP'],
                'flow' => LetterFlow::Rt,
                'estimated_days' => 1,
                'notes' => null,
                'requires_nik' => false,
                'form_schema' => [],
            ],
        ];

        foreach ($services as $i => $service) {
            $village->serviceTypes()->create([...$service, 'sort_order' => $i + 1]);
        }
    }
}
