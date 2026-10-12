<?php

namespace App\Support\Ai;

use App\Enums\ComplaintStatus;
use App\Enums\Level;
use App\Models\AiConversation;
use App\Models\ComplaintCategory;
use App\Models\Rt;
use App\Models\Rw;
use App\Models\User;
use App\Support\Audit;
use App\Support\Scope\RegionScope;
use App\Support\Stats\Period;
use App\Support\Stats\ServiceRecap;
use Illuminate\Validation\ValidationException;

/**
 * Tool yang boleh dipakai Ask Rukana (PRD 8). Model tidak menyentuh database: ia
 * hanya memilih tool dan, paling jauh, nomor RW/RT serta rentang tanggal. Scope
 * selalu dari akun penanya dan wilayah yang diminta hanya bisa mempersempitnya
 * (RegionScope::narrow), jadi pengurus RT 02 tidak bisa membaca RT lain lewat
 * prompt (UAT 11). Setiap panggilan dicatat di ai_tool_calls.
 *
 * Hasil tool hanya angka agregat dan nama kategori/layanan/wilayah.
 */
class AskTools
{
    public function __construct(private readonly ServiceRecap $recap) {}

    /** Definisi tool untuk API. get_village_summary hanya untuk akun tingkat desa. */
    public function definitions(RegionScope $scope): array
    {
        $region = [
            'rw' => ['type' => ['integer', 'null'], 'description' => 'Nomor RW, mis. 3 untuk "RW 03". null = seluruh wilayah penanya.'],
            'rt' => ['type' => ['integer', 'null'], 'description' => 'Nomor RT, mis. 2 untuk "RT 02". Untuk penanya tingkat desa, isi juga rw. null = semua RT.'],
        ];
        $period = [
            'from' => ['type' => ['string', 'null'], 'description' => 'Tanggal awal YYYY-MM-DD (WIB). null = awal bulan ini.'],
            'to' => ['type' => ['string', 'null'], 'description' => 'Tanggal akhir YYYY-MM-DD (WIB), termasuk. null = akhir bulan ini, atau hari ini bila from diisi.'],
        ];
        $tool = fn (string $name, string $description, array $properties) => [
            'name' => $name,
            'description' => $description,
            'strict' => true,
            'inputSchema' => [
                'type' => 'object',
                'properties' => $properties,
                'required' => array_keys($properties),
                'additionalProperties' => false,
            ],
        ];

        $tools = [
            $tool('get_complaint_summary', 'Rekap laporan warga yang MASUK dalam periode: jumlah, selesai, berjalan, ditolak, per status, eskalasi ke desa, rata-rata hari penyelesaian, kategori terbanyak, dan rincian per RT (termasuk jumlah per kategori). Bisa difilter satu kategori.', [
                ...$region,
                ...$period,
                'category' => ['type' => ['string', 'null'], 'description' => 'Nama kategori atau subkategori, mis. "Sampah Menumpuk" atau "Infrastruktur". null = semua.'],
            ]),
            $tool('get_letter_stats', 'Rekap surat pengantar yang DIAJUKAN dalam periode: jumlah, selesai, berjalan, ditolak, per status, rata-rata hari proses, layanan terbanyak, dan rincian per RT.', [...$region, ...$period]),
            $tool('get_pending_items', 'Antrean SAAT INI (tidak terikat periode): akun warga menunggu verifikasi, surat menunggu tindakan per tingkat, surat siap diambil, laporan belum selesai per tingkat.', $region),
            $tool('get_rating_summary', 'Rating kepuasan (1–5) yang DIBERIKAN dalam periode: jumlah dan rata-rata, juga per RW/RT.', [...$region, ...$period]),
        ];

        if ($scope->level === Level::Village) {
            $tools[] = $tool('get_village_summary', 'Ringkasan lengkap seluruh desa dalam periode: surat, laporan, rating, dan rincian per RW.', $period);
        }

        return $tools;
    }

    /**
     * Jalankan satu tool. Kembalian: [hasil untuk model, status log].
     *
     * @return array{0: array<string, mixed>, 1: 'ok'|'error'|'denied'}
     */
    public function run(string $name, array $input, User $user, AiConversation $conversation): array
    {
        $startedAt = microtime(true);
        $scope = $user->scope();
        $effective = null;

        try {
            [$result, $status, $effective] = $this->dispatch($name, $input, $scope);
        } catch (ValidationException $e) {
            [$result, $status] = [['error' => collect($e->errors())->flatten()->first()], 'error'];
        }

        if ($status === 'denied') {
            Audit::log('auth.access_denied', metadata: [
                'feature' => 'ask',
                'tool' => $name,
                'requested' => array_intersect_key($input, array_flip(['rw', 'rt'])),
                'scope' => $scope->toArray(),
            ], user: $user);
        }

        AiLog::tool('ask', $name, [
            'input' => $input,
            'account_scope' => $scope->toArray(),
            'effective_scope' => $effective?->toArray(),
        ], $result, $status, $startedAt, $user, $conversation);

        return [$result, $status];
    }

    /** @return array{0: array, 1: string, 2: ?RegionScope} */
    private function dispatch(string $name, array $input, RegionScope $scope): array
    {
        if ($name === 'get_village_summary' && $scope->level !== Level::Village) {
            return [['error' => 'Ringkasan seluruh desa hanya untuk perangkat desa. Pakai tool lain untuk wilayah Anda.'], 'denied', null];
        }

        $target = $name === 'get_village_summary' ? $scope : $this->region($scope, $input['rw'] ?? null, $input['rt'] ?? null);
        if (! $target) {
            return [['error' => 'Wilayah itu tidak ada atau di luar wilayah Anda. Anda hanya bisa melihat data '.$this->recap->scopeLabel($scope).'.'], 'denied', null];
        }

        if ($name === 'get_pending_items') {
            return [[
                'wilayah' => $this->recap->scopeLabel($target),
                'antrean_saat_ini' => RecapFacts::pending(RecapFacts::plain($this->recap->summary($target, Period::fromInput()))['pending']),
            ], 'ok', $target];
        }

        $period = Period::fromInput($input['from'] ?? null, $input['to'] ?? null);
        $summary = RecapFacts::plain($this->recap->summary($target, $period));
        $base = ['wilayah' => $summary['scope']['label'], 'periode' => RecapFacts::period($summary['period'])];
        $regions = RecapFacts::regions($summary);

        return match ($name) {
            'get_complaint_summary' => $this->complaints($target, $period, $input['category'] ?? null, $base),
            'get_letter_stats' => [[
                ...$base,
                'surat' => RecapFacts::letters($summary['letters']),
                ...$this->only($regions, 'surat'),
            ], 'ok', $target],
            'get_rating_summary' => [[
                ...$base,
                'rating' => RecapFacts::ratings($summary['ratings']),
                ...$this->only($regions, 'rating'),
            ], 'ok', $target],
            'get_village_summary' => [[...RecapFacts::full($summary), 'per_rt' => null], 'ok', $target],
            default => [['error' => "Tool {$name} tidak dikenal."], 'error', $target],
        };
    }

    /** Laporan, opsional satu kategori, dengan jumlah per kategori di tiap RT. */
    private function complaints(RegionScope $scope, Period $period, ?string $category, array $base): array
    {
        $categoryIds = null;
        if ($category !== null && trim($category) !== '') {
            $match = ComplaintCategory::where('name', 'like', '%'.trim($category).'%')->get(['id', 'parent_id']);
            if ($match->isEmpty()) {
                return [['error' => 'Kategori itu tidak ada. Kategori yang ada: '.ComplaintCategory::whereNotNull('parent_id')->orderBy('sort_order')->pluck('name')->implode(', ').'.'], 'error', $scope];
            }
            $categoryIds = ComplaintCategory::whereIn('id', $match->pluck('id'))->orWhereIn('parent_id', $match->pluck('id'))->pluck('id');
        }

        $complaints = $this->recap->complaints($scope, $period)
            ->when($categoryIds, fn ($q) => $q->whereIn('category_id', $categoryIds))
            ->with(['category', 'rt', 'rw'])
            ->get(['id', 'category_id', 'rt_id', 'rw_id', 'status', 'handling_level']);

        $summary = RecapFacts::plain($this->recap->summary($scope, $period));
        $perRt = $complaints
            ->groupBy('rt_id')
            ->map(fn ($group) => [
                'wilayah' => "{$group->first()->rt->label()}/{$group->first()->rw->label()}",
                'jumlah' => $group->count(),
                'selesai' => $group->filter(fn ($c) => $c->status === ComplaintStatus::Resolved)->count(),
                'belum_selesai' => $group->reject(fn ($c) => $c->status->isFinal())->count(),
                'per_kategori' => $group->countBy(fn ($c) => $c->category->name)->sortDesc()->all(),
            ])
            ->sortByDesc('jumlah')
            ->values()
            ->all();

        return [[
            ...$base,
            'filter_kategori' => $categoryIds ? trim($category) : null,
            // Tanpa filter kategori, angka utama sama persis dengan dasbor.
            'laporan' => $categoryIds
                ? ['jumlah_masuk' => $complaints->count(), 'selesai' => $complaints->filter(fn ($c) => $c->status === ComplaintStatus::Resolved)->count(), 'belum_selesai' => $complaints->reject(fn ($c) => $c->status->isFinal())->count()]
                : RecapFacts::complaints($summary['complaints']),
            'per_rt' => $perRt,
        ], 'ok', $scope];
    }

    /**
     * Wilayah yang diminta model, dipersempit dari scope akun. Null bila tidak ada
     * atau di luar scope. Untuk akun RT/RW, nomor RT tanpa RW dibaca sebagai RT di RW-nya.
     */
    private function region(RegionScope $scope, ?int $rwNumber, ?int $rtNumber): ?RegionScope
    {
        if ($rwNumber === null && $rtNumber === null) {
            return $scope;
        }

        $rw = $rwNumber !== null
            ? Rw::where('village_id', $scope->villageId)->where('number', $rwNumber)->first()
            : ($scope->rwId ? Rw::find($scope->rwId) : null);

        if (! $rw) {
            return null;
        }

        $rt = $rtNumber !== null ? Rt::where('rw_id', $rw->id)->where('number', $rtNumber)->first() : null;
        if ($rtNumber !== null && ! $rt) {
            return null;
        }

        return $scope->narrow($rt ? null : $rw, $rt);
    }

    /** Rincian per wilayah, hanya satu bagian (surat atau rating). */
    private function only(array $regions, string $key): array
    {
        return collect($regions)
            ->map(fn (array $rows) => array_map(fn (array $r) => ['wilayah' => $r['wilayah'], $key => $r[$key]], $rows))
            ->all();
    }
}
