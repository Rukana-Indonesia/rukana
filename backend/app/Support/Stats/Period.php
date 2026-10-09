<?php

namespace App\Support\Stats;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Rentang tanggal rekap, dalam tanggal lokal desa (WIB). Kedua ujung termasuk:
 * 1–31 Oktober berarti sampai 31 Oktober 23.59. Tanpa input = bulan berjalan.
 */
final readonly class Period
{
    /** Batas rentang supaya ekspor tidak menyapu seluruh riwayat sekaligus. */
    public const int MAX_DAYS = 366;

    private function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    public static function fromInput(?string $from = null, ?string $to = null): self
    {
        $tz = config('app.display_timezone');
        $today = CarbonImmutable::now($tz)->startOfDay();

        $start = $from ? CarbonImmutable::parse($from, $tz)->startOfDay() : $today->startOfMonth();
        $end = $to ? CarbonImmutable::parse($to, $tz)->startOfDay() : ($from ? $today : $today->endOfMonth()->startOfDay());

        if ($end->lt($start)) {
            throw ValidationException::withMessages(['to' => 'Tanggal akhir tidak boleh sebelum tanggal awal.']);
        }
        if ($start->diffInDays($end) + 1 > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'Rentang rekap paling panjang 1 tahun.']);
        }

        return new self($start, $end);
    }

    /** Awal rentang dalam UTC, untuk query kolom timestamp. */
    public function start(): CarbonImmutable
    {
        return $this->from->utc();
    }

    /** Akhir rentang dalam UTC, eksklusif (awal hari setelah tanggal akhir). */
    public function end(): CarbonImmutable
    {
        return $this->to->addDay()->utc();
    }

    /** "Oktober 2026" untuk satu bulan penuh, selain itu "1 Okt – 15 Okt 2026". */
    public function label(): string
    {
        if ($this->from->isSameDay($this->from->startOfMonth()) && $this->to->isSameDay($this->from->endOfMonth())) {
            return $this->from->translatedFormat('F Y');
        }

        if ($this->from->isSameDay($this->to)) {
            return $this->from->translatedFormat('j F Y');
        }

        $fromFormat = $this->from->year === $this->to->year ? 'j M' : 'j M Y';

        return $this->from->translatedFormat($fromFormat).' – '.$this->to->translatedFormat('j M Y');
    }

    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'label' => $this->label(),
        ];
    }
}
