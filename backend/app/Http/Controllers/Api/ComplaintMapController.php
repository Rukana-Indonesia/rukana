<?php

namespace App\Http\Controllers\Api;

use App\Enums\ComplaintStatus;
use App\Http\Controllers\Concerns\ResolvesRecapFilters;
use App\Http\Controllers\Controller;
use App\Support\Complaints\ComplaintMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Peta sebaran laporan (PRD 6.9): RT melihat RT-nya, RW melihat RW-nya, operator dan
 * kepala desa seluruh desa. Filter wilayah hanya bisa mempersempit scope akun.
 */
class ComplaintMapController extends Controller
{
    use ResolvesRecapFilters;

    public function __invoke(Request $request, ComplaintMap $map): JsonResponse
    {
        [$scope, $period, $data] = $this->recapFilters($request, [
            'category_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in([...array_column(ComplaintStatus::cases(), 'value'), 'active'])],
        ]);

        return response()->json(['data' => [
            ...$map->build($scope, $period, $data['category_id'] ?? null, $data['status'] ?? null),
            'regions' => $map->regions($request->user()->scope()),
        ]]);
    }
}
