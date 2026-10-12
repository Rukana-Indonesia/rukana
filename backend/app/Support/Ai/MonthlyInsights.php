<?php

namespace App\Support\Ai;

use App\Enums\AiTaskStatus;
use App\Enums\Level;
use App\Jobs\GenerateMonthlyInsight;
use App\Models\MonthlyInsight;
use App\Models\User;
use App\Support\Scope\RegionScope;
use App\Support\Stats\Period;
use App\Support\Stats\ServiceRecap;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Monthly Insight (PRD 7.2, UAT 12): narasi bulanan per desa atau per RW.
 *
 * Angka dihitung backend lewat ServiceRecap (sama dengan dasbor) dan disimpan di
 * `metrics`; AI hanya menyusun kalimatnya. Setiap angka di narasi diperiksa ulang
 * terhadap `metrics`, dan narasi yang memuat angka lain ditolak.
 */
class MonthlyInsights
{
    /** Angka yang boleh muncul walau tidak ada di data, mis. "4,3 dari 5". */
    private const ALWAYS_ALLOWED = [5.0];

    public function __construct(
        private readonly AiGateway $ai,
        private readonly ServiceRecap $recap,
    ) {}

    /** Bulan "2026-10" sebagai Period satu bulan penuh. */
    public function period(CarbonImmutable $month): Period
    {
        return Period::fromInput($month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString());
    }

    /** Angka rekap bulan itu (tanpa antrean saat ini, yang tidak terikat periode). */
    public function metrics(RegionScope $scope, CarbonImmutable $month): array
    {
        $summary = RecapFacts::plain($this->recap->summary($scope, $this->period($month)));
        unset($summary['pending']);

        return $summary;
    }

    public function find(RegionScope $scope, CarbonImmutable $month): ?MonthlyInsight
    {
        return MonthlyInsight::query()
            ->where('village_id', $scope->villageId)
            ->when($scope->level === Level::Rw, fn ($q) => $q->where('rw_id', $scope->rwId), fn ($q) => $q->whereNull('rw_id'))
            ->whereDate('period', $month->startOfMonth()->toDateString())
            ->first();
    }

    /**
     * Simpan angka bulan itu dan antrekan penyusunan narasi. Insight lama untuk
     * bulan dan wilayah yang sama ditimpa (angka diperbarui).
     */
    public function request(RegionScope $scope, CarbonImmutable $month, ?User $user = null): MonthlyInsight
    {
        $insight = DB::transaction(function () use ($scope, $month, $user) {
            $insight = $this->find($scope, $month) ?? new MonthlyInsight([
                'village_id' => $scope->villageId,
                'rw_id' => $scope->level === Level::Rw ? $scope->rwId : null,
                'period' => $month->startOfMonth()->toDateString(),
            ]);

            if ($insight->exists && $insight->status === AiTaskStatus::Pending) {
                throw new ConflictHttpException('Ringkasan bulan ini sedang disusun. Tunggu sebentar.');
            }

            $insight->fill([
                'metrics' => $this->metrics($scope, $month),
                'narrative' => null,
                'status' => AiTaskStatus::Pending,
                'model' => null,
                'generated_by' => $user?->id,
                'generated_at' => null,
            ])->save();

            return $insight;
        });

        GenerateMonthlyInsight::dispatch($insight);

        return $insight;
    }

    /** Susun narasi dari `metrics` yang tersimpan. Dipanggil job GenerateMonthlyInsight. */
    public function compose(MonthlyInsight $insight): MonthlyInsight
    {
        $startedAt = microtime(true);
        $facts = RecapFacts::full($insight->metrics);
        $arguments = [
            'insight_id' => $insight->id,
            'period' => $insight->period->format('Y-m'),
            'scope' => ['level' => $insight->rw_id ? 'rw' : 'village', 'village_id' => $insight->village_id, 'rw_id' => $insight->rw_id],
        ];
        $messages = [['role' => 'user', 'content' => json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]];
        $usage = ['input_tokens' => 0, 'output_tokens' => 0];

        try {
            // Satu kesempatan memperbaiki bila ada angka yang tidak berasal dari data.
            for ($attempt = 1; ; $attempt++) {
                $reply = $this->ai->send($this->system(), $messages, [
                    'schema' => $this->schema(),
                    'effort' => 'medium',
                    'max_tokens' => 8000,
                ]);
                $usage['input_tokens'] += $reply->inputTokens;
                $usage['output_tokens'] += $reply->outputTokens;

                $narrative = $this->validated($reply->json())
                    ?? throw new AiUnavailable('Jawaban AI tidak sesuai format. Coba susun ulang.');
                $unknown = $this->unknownNumbers($narrative, $insight->metrics);

                if ($unknown === []) {
                    break;
                }
                if ($attempt === 2) {
                    throw new AiUnavailable('Narasi AI memuat angka yang tidak ada di data rekap ('.implode(', ', $unknown).'), jadi tidak ditampilkan. Coba susun ulang.');
                }

                $messages[] = ['role' => 'assistant', 'content' => $reply->assistantContent];
                $messages[] = ['role' => 'user', 'content' => 'Angka berikut tidak ada di data: '.implode(', ', $unknown)
                    .'. Tulis ulang ringkasan hanya dengan angka yang tertulis di data, tanpa menghitung angka baru.'];
            }
        } catch (AiUnavailable $e) {
            $insight->fill(['status' => AiTaskStatus::Failed, 'narrative' => ['error' => $e->getMessage()]])->save();
            AiLog::tool('monthly_insight', 'compose_narrative', $arguments, ['error' => $e->getMessage()], 'error', $startedAt, $insight->generator, subject: $insight);

            return $insight;
        }

        $insight->fill([
            'narrative' => $narrative,
            'status' => AiTaskStatus::Done,
            'model' => $reply->model,
            'generated_at' => now(),
        ])->save();

        AiLog::tool('monthly_insight', 'compose_narrative', $arguments, ['attempts' => $attempt, 'numbers_checked' => true], 'ok', $startedAt, $insight->generator, subject: $insight);
        AiLog::invoked('monthly_insight', ['model' => $reply->model, ...$usage], $insight, $insight->generator);

        return $insight;
    }

    /** Apakah angka rekap bulan itu sudah berubah sejak insight disusun. */
    public function isStale(MonthlyInsight $insight, RegionScope $scope): bool
    {
        return $this->canonical($insight->metrics) !== $this->canonical($this->metrics($scope, CarbonImmutable::parse($insight->period)));
    }

    /** Kolom JSON MySQL mengurutkan ulang key objek; bandingkan setelah diurutkan. */
    private function canonical(array $data): string
    {
        $sort = function (array $a) use (&$sort): array {
            if (! array_is_list($a)) {
                ksort($a);
            }

            return array_map(fn ($v) => is_array($v) ? $sort($v) : (is_float($v) || is_int($v) ? round((float) $v, 4) : $v), $a);
        };

        return json_encode($sort($data));
    }

    /**
     * Angka di narasi yang tidak ada di data. Angka dibaca dengan koma desimal
     * ("2,4 hari") maupun nomor wilayah ("RW 03" = 3).
     *
     * @return list<string>
     */
    public function unknownNumbers(array $narrative, array $metrics): array
    {
        $allowed = collect(self::ALWAYS_ALLOWED);
        array_walk_recursive($metrics, function ($value) use ($allowed) {
            if (is_int($value) || is_float($value)) {
                $allowed->push((float) $value);
            } elseif (is_string($value)) {
                $allowed->push(...$this->numbersIn($value));
            }
        });
        $allowed = $allowed->map(fn (float $n) => round($n, 1))->unique();

        $text = implode(' ', [$narrative['ringkasan'], ...$narrative['sorotan'], ...$narrative['perlu_perhatian']]);

        return collect($this->numbersIn($text, raw: true))
            ->reject(fn (string $raw) => $allowed->contains(round((float) str_replace(',', '.', $raw), 1)))
            ->unique()
            ->values()
            ->all();
    }

    /** @return ($raw is true ? list<string> : list<float>) */
    private function numbersIn(string $text, bool $raw = false): array
    {
        preg_match_all('/\d+(?:[.,]\d+)?/', $text, $m);

        return $raw ? $m[0] : array_map(fn (string $n) => (float) str_replace(',', '.', $n), $m[0]);
    }

    private function system(): string
    {
        return <<<'PROMPT'
        Anda menyusun ringkasan bulanan kondisi layanan warga untuk kepala desa dan perangkat
        desa di Indonesia. Data rekap (surat pengantar, laporan warga, rating kepuasan) dihitung
        oleh sistem dan diberikan sebagai JSON.

        Aturan angka, wajib:
        - Pakai hanya angka yang tertulis di data, persis seperti di data. Jangan menghitung
          angka baru: tanpa persentase, selisih, penjumlahan, atau perbandingan "kali lipat".
        - Tulis desimal dengan koma (2,4 hari; 4,3 dari 5).
        - Bila datanya kosong atau nol, katakan apa adanya.

        Isi:
        - ringkasan: satu paragraf (3–5 kalimat) gambaran bulan itu.
        - sorotan: 3–6 poin singkat angka terpenting (laporan masuk/selesai, eskalasi ke desa,
          kategori terbanyak dan wilayahnya, surat terbanyak, waktu proses, rating).
        - perlu_perhatian: 0–3 poin hal yang layak ditindaklanjuti, berdasarkan data saja
          (mis. laporan yang masih berjalan, wilayah dengan laporan terbanyak). Jangan menebak
          penyebab dan jangan memberi rekomendasi kebijakan atau bantuan sosial.

        Bahasa Indonesia yang lugas dan sopan, tanpa istilah teknis, tanpa nama orang.
        PROMPT;
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ringkasan' => ['type' => 'string'],
                'sorotan' => ['type' => 'array', 'items' => ['type' => 'string']],
                'perlu_perhatian' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['ringkasan', 'sorotan', 'perlu_perhatian'],
            'additionalProperties' => false,
        ];
    }

    /** @return array{ringkasan: string, sorotan: list<string>, perlu_perhatian: list<string>}|null */
    private function validated(?array $output): ?array
    {
        $clean = fn ($items) => is_array($items)
            ? array_values(array_filter(array_map(fn ($s) => is_string($s) ? trim($s) : '', $items)))
            : null;

        $summary = trim((string) ($output['ringkasan'] ?? ''));
        $highlights = $clean($output['sorotan'] ?? null);
        $attention = $clean($output['perlu_perhatian'] ?? null);

        if ($summary === '' || ! $highlights || $attention === null) {
            return null;
        }

        return ['ringkasan' => $summary, 'sorotan' => array_slice($highlights, 0, 8), 'perlu_perhatian' => array_slice($attention, 0, 5)];
    }
}
