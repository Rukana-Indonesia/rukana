<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Rt;
use App\Models\Rw;
use App\Support\Audit;
use App\Support\Scope\RegionScope;
use App\Support\Stats\Period;
use App\Support\Stats\RecapExport;
use App\Support\Stats\ServiceRecap;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rekap dasbor RT/RW/desa (PRD 6.8) dan ekspornya ke Excel/PDF (PRD 6.10, UAT 13).
 * Scope selalu dari akun; filter RW/RT hanya bisa mempersempitnya.
 */
class RecapController extends Controller
{
    public function show(Request $request, ServiceRecap $recap): JsonResponse
    {
        [$scope, $period] = $this->filters($request);

        return response()->json(['data' => $recap->summary($scope, $period)]);
    }

    public function export(Request $request, RecapExport $export): Response
    {
        $format = $request->validate(['format' => ['required', Rule::in(['xlsx', 'pdf'])]])['format'];
        [$scope, $period] = $this->filters($request);

        Audit::log('export.downloaded', metadata: [
            'format' => $format,
            'period' => $period->toArray(),
            'scope' => $scope->toArray(),
        ]);

        return $export->download($format, $scope, $period, $request->user());
    }

    /** @return array{RegionScope, Period} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'rw_id' => ['nullable', 'integer'],
            'rt_id' => ['nullable', 'integer'],
        ]);

        $scope = $request->user()->scope();
        $rw = isset($data['rw_id']) ? Rw::find($data['rw_id']) : null;
        $rt = isset($data['rt_id']) ? Rt::find($data['rt_id']) : null;

        // Wilayah yang tidak ada atau di luar scope sama-sama ditolak (dan tercatat di
        // audit log), supaya keberadaan wilayah desa lain tidak bocor.
        $narrowed = (isset($data['rw_id']) && ! $rw) || (isset($data['rt_id']) && ! $rt)
            ? null
            : $scope->narrow($rw, $rt);
        if (! $narrowed || ($rw && $rt && $rt->rw_id !== $rw->id)) {
            throw new AuthorizationException('Anda tidak punya akses ke data wilayah ini.');
        }

        return [$narrowed, Period::fromInput($data['from'] ?? null, $data['to'] ?? null)];
    }
}
