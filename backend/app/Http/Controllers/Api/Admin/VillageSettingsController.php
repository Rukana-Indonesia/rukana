<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Village;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Pengaturan desa milik akun. Operator mengubah, kepala desa hanya melihat. */
class VillageSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->data(Village::findOrFail($request->user()->village_id), $request->user())]);
    }

    public function update(Request $request): JsonResponse
    {
        $village = Village::findOrFail($request->user()->village_id);

        $data = $request->validate([
            'map_latitude' => ['required', 'numeric', 'between:-90,90'],
            'map_longitude' => ['required', 'numeric', 'between:-180,180'],
            'map_zoom' => ['required', 'integer', 'between:10,19'],
        ]);

        $before = $village->only(array_keys($data));
        $village->update($data);
        Audit::log('village.settings_updated', $village, ['before' => $before, 'after' => $village->only(array_keys($data))]);

        return response()->json([
            'data' => $this->data($village, $request->user()),
            'message' => 'Titik tengah peta desa disimpan.',
        ]);
    }

    private function data(Village $village, User $user): array
    {
        return [
            'id' => $village->id,
            'name' => $village->name,
            'district' => $village->district,
            'regency' => $village->regency,
            'address' => $village->address,
            'map_center' => $village->mapCenter(),
            'can_update' => $user->role === Role::Operator,
        ];
    }
}
