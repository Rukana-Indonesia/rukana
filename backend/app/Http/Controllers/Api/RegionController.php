<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Village;
use Illuminate\Http\JsonResponse;

class RegionController extends Controller
{
    /** Daftar RW/RT untuk form pendaftaran (publik, tanpa data warga). */
    public function index(): JsonResponse
    {
        // MVP: satu desa. Saat multi-desa, desa dipilih dari subdomain/kode desa.
        $village = Village::with('rws.rts')->firstOrFail();

        return response()->json([
            'data' => [
                'id' => $village->id,
                'name' => $village->name,
                'district' => $village->district,
                'rws' => $village->rws->map(fn ($rw) => [
                    'id' => $rw->id,
                    'number' => $rw->number,
                    'label' => $rw->label(),
                    'name' => $rw->name,
                    'rts' => $rw->rts->map(fn ($rt) => ['id' => $rt->id, 'number' => $rt->number, 'label' => $rt->label()]),
                ]),
            ],
        ]);
    }
}
