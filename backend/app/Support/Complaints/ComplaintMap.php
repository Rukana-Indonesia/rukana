<?php

namespace App\Support\Complaints;

use App\Enums\ComplaintStatus;
use App\Enums\Level;
use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\Rt;
use App\Models\Rw;
use App\Models\Village;
use App\Support\Scope\RegionScope;
use App\Support\Stats\Period;
use App\Support\Stats\ServiceRecap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Titik laporan untuk peta sebaran (PRD 6.9). Laporan diambil lewat
 * ServiceRecap::complaints(), jadi "laporan masuk" di peta sama dengan dasbor untuk
 * periode dan wilayah yang sama.
 *
 * Tanpa nama pelapor, nomor HP, dan patokan lokasi: peta hanya butuh titik,
 * kategori, status, dan tautan ke detail (yang tetap dicek ComplaintPolicy).
 */
class ComplaintMap
{
    /** Batas titik per muatan supaya peta tetap ringan di HP. */
    public const int MAX_POINTS = 1000;

    /** Ukuran sel "titik berulang", dalam derajat (0,001° ≈ 110 m). */
    public const float HOTSPOT_CELL = 0.001;

    public function __construct(private readonly ServiceRecap $recap) {}

    /**
     * @param  int|null  $categoryId  kategori induk mencakup semua subkategorinya
     * @param  string|null  $status  nilai ComplaintStatus, atau 'active' untuk yang belum selesai
     */
    public function build(RegionScope $scope, Period $period, ?int $categoryId = null, ?string $status = null): array
    {
        $query = $this->recap->complaints($scope, $period)
            ->when($categoryId, fn (Builder $q, int $id) => $q->whereIn(
                'category_id',
                ComplaintCategory::where('id', $id)->orWhere('parent_id', $id)->select('id'),
            ))
            ->when($status, fn (Builder $q, string $status) => $status === 'active'
                ? $q->whereNotIn('status', [ComplaintStatus::Resolved, ComplaintStatus::Rejected])
                : $q->where('status', $status));

        $total = (clone $query)->count();
        $located = (clone $query)->whereNotNull('latitude')->whereNotNull('longitude');
        $withLocation = (clone $located)->count();

        $complaints = $located
            ->with(['category.parent', 'rt', 'rw'])
            ->latest()
            ->orderByDesc('id')
            ->limit(self::MAX_POINTS)
            ->get(['id', 'code', 'title', 'category_id', 'rw_id', 'rt_id', 'status', 'urgency', 'latitude', 'longitude', 'created_at']);

        $points = $complaints->map(fn (Complaint $c) => [
            'id' => $c->id,
            'code' => $c->code,
            'title' => $c->title,
            'latitude' => (float) $c->latitude,
            'longitude' => (float) $c->longitude,
            'status' => $c->status->value,
            'status_label' => $c->status->label(),
            'is_active' => ! $c->status->isFinal(),
            'urgency' => $c->urgency?->value,
            'urgency_label' => $c->urgency?->label(),
            'category' => [
                'id' => $c->category->id,
                'name' => $c->category->name,
                'parent' => $c->category->parent?->name,
            ],
            'region' => $c->rt->label().'/'.$c->rw->label(),
            'created_at' => $c->created_at->toIso8601String(),
        ])->values();

        return [
            'scope' => ['level' => $scope->level->value, 'label' => $this->recap->scopeLabel($scope)],
            'period' => $period->toArray(),
            'total' => $total,
            'with_location' => $withLocation,
            'truncated' => $withLocation > self::MAX_POINTS,
            'points' => $points,
            'hotspots' => $this->hotspots($points),
            'center' => Village::findOrFail($scope->villageId)->mapCenter(),
        ];
    }

    /**
     * RW/RT di dalam scope akun, untuk pilihan filter wilayah. RT tidak punya pilihan.
     *
     * @return array{rws: list<array>, rts: list<array>}
     */
    public function regions(RegionScope $scope): array
    {
        if ($scope->level === Level::Rt) {
            return ['rws' => [], 'rts' => []];
        }

        $rts = Rt::query()
            ->with('rw')
            ->where('village_id', $scope->villageId)
            ->when($scope->level === Level::Rw, fn (Builder $q) => $q->where('rw_id', $scope->rwId))
            ->get()
            ->sortBy(fn (Rt $rt) => [$rt->rw->number, $rt->number]);

        return [
            'rws' => $scope->level !== Level::Village ? [] : $rts->pluck('rw')->unique('id')
                ->map(fn (Rw $rw) => ['id' => $rw->id, 'label' => $rw->label()])->values()->all(),
            'rts' => $rts->map(fn (Rt $rt) => [
                'id' => $rt->id,
                'label' => $rt->label(),
                'rw' => ['id' => $rt->rw->id, 'label' => $rt->rw->label()],
            ])->values()->all(),
        ];
    }

    /**
     * Titik masalah berulang: sel ±110 m yang berisi 2 laporan atau lebih, terbanyak
     * dulu. Titik tengahnya rata-rata titik laporan di sel itu.
     */
    private function hotspots(Collection $points, int $limit = 5): array
    {
        $cell = fn (float $value) => (int) floor($value / self::HOTSPOT_CELL);

        return $points
            ->groupBy(fn (array $p) => $cell($p['latitude']).':'.$cell($p['longitude']))
            ->filter(fn (Collection $group) => $group->count() >= 2)
            ->map(fn (Collection $group) => [
                'latitude' => round($group->avg('latitude'), 7),
                'longitude' => round($group->avg('longitude'), 7),
                'total' => $group->count(),
                'active' => $group->where('is_active', true)->count(),
                'top_category' => $group->countBy(fn (array $p) => $p['category']['name'])->sortDesc()->keys()->first(),
                'regions' => $group->pluck('region')->unique()->sort()->values()->all(),
                'ids' => $group->pluck('id')->all(),
            ])
            ->sortBy([['total', 'desc'], ['active', 'desc']])
            ->take($limit)
            ->values()
            ->all();
    }
}
