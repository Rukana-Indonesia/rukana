<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesRecapFilters;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\Stats\RecapExport;
use App\Support\Stats\ServiceRecap;
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
    use ResolvesRecapFilters;

    public function show(Request $request, ServiceRecap $recap): JsonResponse
    {
        [$scope, $period] = $this->recapFilters($request);

        return response()->json(['data' => $recap->summary($scope, $period)]);
    }

    public function export(Request $request, RecapExport $export): Response
    {
        $format = $request->validate(['format' => ['required', Rule::in(['xlsx', 'pdf'])]])['format'];
        [$scope, $period] = $this->recapFilters($request);

        Audit::log('export.downloaded', metadata: [
            'format' => $format,
            'period' => $period->toArray(),
            'scope' => $scope->toArray(),
        ]);

        /** @var User $user Route ini di balik auth:sanctum. */
        $user = $request->user();

        return $export->download($format, $scope, $period, $user);
    }
}
