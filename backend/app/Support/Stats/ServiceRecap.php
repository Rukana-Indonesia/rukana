<?php

namespace App\Support\Stats;

use App\Enums\ComplaintStatus;
use App\Enums\LetterStatus;
use App\Enums\Level;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\LetterRequest;
use App\Models\Rating;
use App\Models\Rt;
use App\Models\Rw;
use App\Models\ServiceType;
use App\Models\User;
use App\Models\Village;
use App\Support\Scope\RegionScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Angka rekap surat, laporan, dan rating untuk satu scope wilayah dan periode
 * (PRD 6.8, 6.10). Satu-satunya tempat angka ini dihitung: dasbor, ekspor, dan
 * nanti Monthly Insight serta Ask Rukana memakai kelas ini, jadi angkanya selalu sama.
 *
 * Aturan hitung:
 * - surat/laporan dihitung dari yang MASUK dalam periode (surat: submitted_at,
 *   laporan: created_at); status, rata-rata waktu proses, dan eskalasi berlaku untuk
 *   kelompok yang sama;
 * - rating dihitung dari rating yang DIBERIKAN dalam periode;
 * - "pending" adalah kondisi antrean saat ini, tidak terikat periode.
 *
 * Tidak ada nama pelapor, nomor HP, atau NIK di hasil rekap.
 */
class ServiceRecap
{
    public function summary(RegionScope $scope, Period $period): array
    {
        $letters = $this->letters($scope, $period)->get(['id', 'service_type_id', 'rw_id', 'rt_id', 'status', 'submitted_at', 'completed_at']);
        $complaints = $this->complaints($scope, $period)->get(['id', 'category_id', 'rw_id', 'rt_id', 'status', 'handling_level', 'created_at', 'resolved_at']);
        $ratings = $this->ratings($scope, $period)->get(['id', 'rw_id', 'rt_id', 'score']);

        $categoryNames = ComplaintCategory::whereIn('id', $complaints->pluck('category_id')->unique())->pluck('name', 'id');
        $escalated = $complaints->filter(fn (Complaint $c) => $c->handling_level === Level::Village);

        return [
            'scope' => ['level' => $scope->level?->value, 'label' => $this->scopeLabel($scope)],
            'period' => $period->toArray(),
            'letters' => [
                ...$this->letterStats($letters),
                'by_status' => $this->countBy($letters, LetterStatus::cases()),
                'avg_process_days' => $this->averageDays($letters->where('status', LetterStatus::Completed), 'submitted_at', 'completed_at'),
                'top_services' => $this->top(
                    $letters->countBy('service_type_id'),
                    ServiceType::withTrashed()->whereIn('id', $letters->pluck('service_type_id')->unique())->pluck('name', 'id'),
                ),
            ],
            'complaints' => [
                ...$this->complaintStats($complaints),
                'by_status' => $this->countBy($complaints, ComplaintStatus::cases()),
                'forwarded' => $complaints->filter(fn (Complaint $c) => $c->handling_level !== Level::Rt)->count(),
                'escalated_village' => [
                    'total' => $escalated->count(),
                    'by_status' => $this->countBy($escalated, ComplaintStatus::cases()),
                ],
                'avg_resolve_days' => $this->averageDays($complaints->where('status', ComplaintStatus::Resolved), 'created_at', 'resolved_at'),
                'top_categories' => $this->top($complaints->countBy('category_id'), $categoryNames),
            ],
            'ratings' => $this->ratingStats($ratings),
            'pending' => $this->pending($scope),
            ...$this->regions($scope, $letters, $complaints, $ratings, $categoryNames),
        ];
    }

    /** Surat yang masuk dalam periode, di dalam scope. Dipakai juga oleh ekspor. */
    public function letters(RegionScope $scope, Period $period): Builder
    {
        return LetterRequest::query()
            ->tap(fn (Builder $q) => $scope->apply($q))
            ->where('submitted_at', '>=', $period->start())
            ->where('submitted_at', '<', $period->end());
    }

    /** Laporan yang masuk dalam periode, di dalam scope. Dipakai juga oleh ekspor. */
    public function complaints(RegionScope $scope, Period $period): Builder
    {
        return Complaint::query()
            ->tap(fn (Builder $q) => $scope->apply($q, 'reporter_id'))
            ->where('created_at', '>=', $period->start())
            ->where('created_at', '<', $period->end());
    }

    /** Rating yang diberikan dalam periode, di dalam scope. */
    public function ratings(RegionScope $scope, Period $period): Builder
    {
        return Rating::query()
            ->tap(fn (Builder $q) => $scope->apply($q))
            ->where('created_at', '>=', $period->start())
            ->where('created_at', '<', $period->end());
    }

    /** "Desa Sukamaju", "RW 03", atau "RT 02/RW 03". */
    public function scopeLabel(RegionScope $scope): string
    {
        return match ($scope->level) {
            Level::Village => 'Desa '.Village::whereKey($scope->villageId)->value('name'),
            Level::Rw => Rw::findOrFail($scope->rwId)->label(),
            Level::Rt => Rt::findOrFail($scope->rtId)->label().'/'.Rw::findOrFail($scope->rwId)->label(),
            null => 'Data saya',
        };
    }

    private function letterStats(Collection $letters): array
    {
        return [
            'total' => $letters->count(),
            'completed' => $letters->where('status', LetterStatus::Completed)->count(),
            'rejected' => $letters->where('status', LetterStatus::Rejected)->count(),
            'active' => $letters->reject(fn (LetterRequest $l) => $l->status->isFinal())->count(),
        ];
    }

    private function complaintStats(Collection $complaints): array
    {
        return [
            'total' => $complaints->count(),
            'resolved' => $complaints->where('status', ComplaintStatus::Resolved)->count(),
            'rejected' => $complaints->where('status', ComplaintStatus::Rejected)->count(),
            'active' => $complaints->reject(fn (Complaint $c) => $c->status->isFinal())->count(),
        ];
    }

    private function ratingStats(Collection $ratings): array
    {
        return [
            'total' => $ratings->count(),
            'average' => $ratings->isEmpty() ? null : round($ratings->avg('score'), 1),
        ];
    }

    /** Antrean saat ini (tidak terikat periode), untuk kartu "menunggu". */
    private function pending(RegionScope $scope): array
    {
        $letters = LetterRequest::query()
            ->tap(fn (Builder $q) => $scope->apply($q))
            ->whereNotIn('status', [LetterStatus::Completed, LetterStatus::Rejected])
            ->selectRaw('current_level, status, count(*) as total')
            ->groupBy('current_level', 'status')
            ->get();
        $complaints = Complaint::query()
            ->tap(fn (Builder $q) => $scope->apply($q, 'reporter_id'))
            ->whereNotIn('status', [ComplaintStatus::Resolved, ComplaintStatus::Rejected])
            ->selectRaw('handling_level, count(*) as total')
            ->groupBy('handling_level')
            ->pluck('total', 'handling_level');
        $byLevel = fn (Collection $rows) => collect(Level::cases())
            ->mapWithKeys(fn (Level $level) => [$level->value => (int) $rows->where('current_level', $level)->sum('total')]);

        return [
            'residents' => User::query()
                ->tap(fn (Builder $q) => $scope->apply($q, 'id'))
                ->where('role', Role::Warga)
                ->where('status', UserStatus::Pending)
                ->count(),
            'letters' => $byLevel($letters),
            'letters_ready' => (int) $letters->where('status', LetterStatus::ReadyForPickup)->sum('total'),
            'complaints' => collect(Level::cases())->mapWithKeys(fn (Level $level) => [$level->value => (int) ($complaints[$level->value] ?? 0)]),
        ];
    }

    /**
     * Rincian per wilayah di bawah scope: per RT (dengan RW-nya) dan, untuk scope
     * desa, per RW. Wilayah tanpa aktivitas tetap ikut dengan angka 0.
     */
    private function regions(RegionScope $scope, Collection $letters, Collection $complaints, Collection $ratings, Collection $categoryNames): array
    {
        $rts = Rt::query()
            ->with('rw')
            ->where('village_id', $scope->villageId)
            ->when($scope->level === Level::Rw, fn (Builder $q) => $q->where('rw_id', $scope->rwId))
            ->when($scope->level === Level::Rt, fn (Builder $q) => $q->whereKey($scope->rtId))
            ->get()
            ->sortBy(fn (Rt $rt) => [$rt->rw->number, $rt->number]);

        $row = fn (string $column, int $id) => [
            'letters' => $this->letterStats($letters->where($column, $id)),
            'complaints' => $this->complaintStats($complaints->where($column, $id)),
            'top_category' => $this->top($complaints->where($column, $id)->countBy('category_id'), $categoryNames, 1)->first(),
            'ratings' => $this->ratingStats($ratings->where($column, $id)),
        ];

        $rtRows = $rts->map(fn (Rt $rt) => [
            'id' => $rt->id,
            'label' => $rt->label(),
            'rw' => ['id' => $rt->rw->id, 'label' => $rt->rw->label()],
            ...$row('rt_id', $rt->id),
        ])->values();

        $rwRows = $scope->level !== Level::Village ? collect() : $rts->groupBy('rw_id')->map(fn (Collection $group) => [
            'id' => $group->first()->rw->id,
            'label' => $group->first()->rw->label(),
            ...$row('rw_id', $group->first()->rw_id),
        ])->values();

        return ['rws' => $rwRows, 'rts' => $rtRows];
    }

    /**
     * Jumlah per status, lengkap dengan status yang 0.
     *
     * @param  list<LetterStatus|ComplaintStatus>  $statuses
     */
    private function countBy(Collection $items, array $statuses): Collection
    {
        $counts = $items->countBy(fn (LetterRequest|Complaint $item) => $item->status->value);

        return collect($statuses)->mapWithKeys(fn (LetterStatus|ComplaintStatus $s) => [$s->value => (int) ($counts[$s->value] ?? 0)]);
    }

    /** Rata-rata selisih dua timestamp dalam hari (1 desimal); null bila kosong. */
    private function averageDays(Collection $items, string $startColumn, string $endColumn): ?float
    {
        $seconds = $items
            ->filter(fn ($item) => $item->{$startColumn} && $item->{$endColumn})
            ->map(fn ($item) => $item->{$startColumn}->diffInSeconds($item->{$endColumn}));

        return $seconds->isEmpty() ? null : round($seconds->avg() / 86400, 1);
    }

    /** Peringkat teratas dari hitungan per id: [{name, total}], seri diurutkan nama. */
    private function top(Collection $counts, Collection $names, int $limit = 5): Collection
    {
        return $counts
            ->map(fn (int $total, int|string $id) => ['name' => $names[$id] ?? '—', 'total' => $total])
            ->sortBy([['total', 'desc'], ['name', 'asc']])
            ->take($limit)
            ->values();
    }
}
