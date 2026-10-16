<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Rt;
use App\Models\Rw;
use App\Support\Scope\RegionScope;
use App\Support\Stats\Period;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

/**
 * Filter periode + wilayah untuk rekap, ekspor, dan peta sebaran. Scope selalu dari
 * akun; rw_id/rt_id hanya bisa mempersempitnya lewat RegionScope::narrow().
 */
trait ResolvesRecapFilters
{
    /**
     * @param  array<string, mixed>  $rules  aturan validasi tambahan
     * @return array{RegionScope, Period, array<string, mixed>}
     */
    private function recapFilters(Request $request, array $rules = []): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'rw_id' => ['nullable', 'integer'],
            'rt_id' => ['nullable', 'integer'],
            ...$rules,
        ]);

        $scope = $request->user()->scope();
        $rw = isset($data['rw_id']) ? Rw::whereKey($data['rw_id'])->first() : null;
        $rt = isset($data['rt_id']) ? Rt::whereKey($data['rt_id'])->first() : null;

        // Wilayah yang tidak ada atau di luar scope sama-sama ditolak (dan tercatat di
        // audit log), supaya keberadaan wilayah desa lain tidak bocor.
        $narrowed = (isset($data['rw_id']) && ! $rw) || (isset($data['rt_id']) && ! $rt)
            ? null
            : $scope->narrow($rw, $rt);
        if (! $narrowed || ($rw && $rt && $rt->rw_id !== $rw->id)) {
            throw new AuthorizationException('Anda tidak punya akses ke data wilayah ini.');
        }

        return [$narrowed, Period::fromInput($data['from'] ?? null, $data['to'] ?? null), $data];
    }
}
