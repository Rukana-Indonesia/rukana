<?php

namespace App\Support\Ai;

use App\Enums\ComplaintStatus;
use App\Enums\LetterStatus;
use App\Enums\Level;
use Illuminate\Support\Arr;

/**
 * Hasil ServiceRecap::summary() dalam bentuk yang dibaca LLM: kunci dan label status
 * berbahasa Indonesia, tanpa ID internal. Angkanya tidak diubah sama sekali, jadi
 * narasi dan jawaban AI memakai angka yang sama dengan dasbor (UAT 12).
 */
class RecapFacts
{
    /** Hasil summary() (berisi Collection) sebagai array biasa, sama seperti yang tersimpan di JSON. */
    public static function plain(array $summary): array
    {
        return json_decode(json_encode($summary, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function full(array $summary): array
    {
        return [
            'wilayah' => $summary['scope']['label'],
            'periode' => self::period($summary['period']),
            'surat' => self::letters($summary['letters']),
            'laporan' => self::complaints($summary['complaints']),
            'rating' => self::ratings($summary['ratings']),
            ...self::regions($summary),
        ];
    }

    public static function period(array $period): array
    {
        return ['label' => $period['label'], 'dari' => $period['from'], 'sampai' => $period['to']];
    }

    public static function letters(array $letters): array
    {
        return array_filter([
            'jumlah_masuk' => $letters['total'],
            'selesai' => $letters['completed'],
            'ditolak' => $letters['rejected'],
            'masih_berjalan' => $letters['active'],
            'per_status' => isset($letters['by_status']) ? self::statuses($letters['by_status'], LetterStatus::class) : null,
            'rata_rata_hari_proses_surat_selesai' => $letters['avg_process_days'] ?? null,
            'layanan_terbanyak' => isset($letters['top_services']) ? self::top($letters['top_services']) : null,
        ], fn ($v) => $v !== null);
    }

    public static function complaints(array $complaints): array
    {
        return array_filter([
            'jumlah_masuk' => $complaints['total'],
            'selesai' => $complaints['resolved'],
            'ditolak' => $complaints['rejected'],
            'masih_berjalan' => $complaints['active'],
            'per_status' => isset($complaints['by_status']) ? self::statuses($complaints['by_status'], ComplaintStatus::class) : null,
            'diteruskan_ke_rw_atau_desa' => $complaints['forwarded'] ?? null,
            'eskalasi_ke_desa' => isset($complaints['escalated_village']) ? [
                'jumlah' => $complaints['escalated_village']['total'],
                'per_status' => self::statuses($complaints['escalated_village']['by_status'], ComplaintStatus::class),
            ] : null,
            'rata_rata_hari_penyelesaian' => $complaints['avg_resolve_days'] ?? null,
            'kategori_terbanyak' => isset($complaints['top_categories']) ? self::top($complaints['top_categories']) : null,
        ], fn ($v) => $v !== null);
    }

    public static function ratings(array $ratings): array
    {
        return ['jumlah_rating' => $ratings['total'], 'rata_rata_dari_5' => $ratings['average']];
    }

    /** Antrean saat ini, tidak terikat periode. */
    public static function pending(array $pending): array
    {
        $byLevel = fn (array $counts) => collect(Level::cases())
            ->mapWithKeys(fn (Level $l) => ["di_{$l->label()}" => $counts[$l->value] ?? 0])
            ->all();

        return [
            'warga_menunggu_verifikasi_akun' => $pending['residents'],
            'surat_menunggu_tindakan' => $byLevel($pending['letters']),
            'surat_siap_diambil' => $pending['letters_ready'],
            'laporan_belum_selesai' => $byLevel($pending['complaints']),
        ];
    }

    /** Rincian per RW (tingkat desa) dan per RT di bawah scope. */
    public static function regions(array $summary): array
    {
        $row = fn (array $r, string $label) => [
            'wilayah' => $label,
            'surat' => self::letters($r['letters']),
            'laporan' => self::complaints($r['complaints']),
            'kategori_laporan_terbanyak' => $r['top_category'] ? "{$r['top_category']['name']} ({$r['top_category']['total']})" : null,
            'rating' => self::ratings($r['ratings']),
        ];

        $rws = collect($summary['rws'] ?? [])->map(fn (array $r) => $row($r, $r['label']))->values()->all();
        $rts = collect($summary['rts'] ?? [])->map(fn (array $r) => $row($r, "{$r['label']}/{$r['rw']['label']}"))->values()->all();

        return array_filter(['per_rw' => $rws, 'per_rt' => $rts]);
    }

    /** @param  class-string<LetterStatus|ComplaintStatus>  $enum */
    private static function statuses(array $counts, string $enum): array
    {
        return collect($counts)
            ->mapWithKeys(fn (int $n, string $status) => [$enum::from($status)->label() => $n])
            ->all();
    }

    private static function top(array $rows): array
    {
        return array_map(fn (array $r) => Arr::only($r, ['name', 'total']), $rows);
    }
}
