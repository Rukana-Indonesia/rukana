<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Rt;
use App\Models\Rw;
use App\Models\User;
use App\Models\Village;
use App\Support\Audit;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Master wilayah RW/RT. Operator mengelola, kepala desa hanya melihat. */
class RegionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $village = Village::with('rws.rts')->findOrFail($request->user()->village_id);

        $residents = User::where('village_id', $village->id)
            ->where('role', Role::Warga)
            ->whereIn('status', [UserStatus::Active, UserStatus::Pending])
            ->selectRaw('rt_id, status, count(*) as total')
            ->groupBy('rt_id', 'status')
            ->get()
            ->groupBy('rt_id');

        $officials = User::where('village_id', $village->id)
            ->whereIn('role', [Role::Rt, Role::Rw])
            ->where('status', UserStatus::Active)
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'rw_id', 'rt_id']);

        $count = fn (int $rtId, UserStatus $status) => (int) ($residents->get($rtId)?->firstWhere('status', $status)?->total ?? 0);
        $names = fn ($users) => $users->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values();

        return response()->json([
            'data' => [
                'id' => $village->id,
                'name' => $village->name,
                'district' => $village->district,
                'rws' => $village->rws->map(fn (Rw $rw) => [
                    ...$this->rwData($rw),
                    'officials' => $names($officials->where('role', Role::Rw)->where('rw_id', $rw->id)),
                    'rts' => $rw->rts->map(fn (Rt $rt) => [
                        ...$this->rtData($rt),
                        'residents_active' => $count($rt->id, UserStatus::Active),
                        'residents_pending' => $count($rt->id, UserStatus::Pending),
                        'officials' => $names($officials->where('role', Role::Rt)->where('rt_id', $rt->id)),
                    ]),
                ]),
            ],
        ]);
    }

    public function storeRw(Request $request): JsonResponse
    {
        $villageId = $request->user()->village_id;
        $data = $request->validate([
            'number' => ['required', 'integer', 'between:1,999', Rule::unique('rws')->where('village_id', $villageId)],
            'name' => ['nullable', 'string', 'max:100'],
        ], ['number.unique' => 'Nomor RW ini sudah ada.']);

        $rw = Rw::create([...$data, 'village_id' => $villageId]);
        Audit::log('region.rw_created', $rw);

        return response()->json(['data' => $this->rwData($rw), 'message' => "{$rw->label()} ditambahkan."], 201);
    }

    public function updateRw(Request $request, Rw $rw): JsonResponse
    {
        Gate::authorize('manage-region', $rw);
        $data = $request->validate([
            'number' => ['required', 'integer', 'between:1,999', Rule::unique('rws')->where('village_id', $rw->village_id)->ignore($rw)],
            'name' => ['nullable', 'string', 'max:100'],
        ], ['number.unique' => 'Nomor RW ini sudah ada.']);

        $rw->update($data);
        Audit::log('region.rw_updated', $rw, $rw->getChanges());

        return response()->json(['data' => $this->rwData($rw), 'message' => "{$rw->label()} diperbarui."]);
    }

    public function destroyRw(Rw $rw): JsonResponse
    {
        Gate::authorize('manage-region', $rw);
        abort_if($rw->rts()->exists(), 409, 'Hapus dulu semua RT di RW ini.');

        return $this->destroy($rw, 'region.rw_deleted', "{$rw->label()} dihapus.");
    }

    public function storeRt(Request $request, Rw $rw): JsonResponse
    {
        Gate::authorize('manage-region', $rw);
        $data = $request->validate([
            'number' => ['required', 'integer', 'between:1,999', Rule::unique('rts')->where('rw_id', $rw->id)],
        ], ['number.unique' => "Nomor RT ini sudah ada di {$rw->label()}."]);

        $rt = $rw->rts()->create($data);
        Audit::log('region.rt_created', $rt);

        return response()->json(['data' => $this->rtData($rt), 'message' => "{$rt->label()}/{$rw->label()} ditambahkan."], 201);
    }

    public function destroyRt(Rt $rt): JsonResponse
    {
        Gate::authorize('manage-region', $rt);

        return $this->destroy($rt, 'region.rt_deleted', "{$rt->label()} dihapus.");
    }

    /** Wilayah yang masih punya akun atau riwayat layanan tidak boleh dihapus. */
    private function destroy(Rw|Rt $region, string $action, string $message): JsonResponse
    {
        $column = $region instanceof Rw ? 'rw_id' : 'rt_id';
        abort_if(
            User::withTrashed()->where($column, $region->id)->exists(),
            409,
            'Wilayah ini masih punya akun warga atau pengurus.',
        );

        try {
            $region->delete();
        } catch (QueryException) {
            abort(409, 'Wilayah ini sudah punya riwayat surat, laporan, atau pengumuman.');
        }
        Audit::log($action, metadata: ['id' => $region->id, 'number' => $region->number]);

        return response()->json(['message' => $message]);
    }

    private function rwData(Rw $rw): array
    {
        return ['id' => $rw->id, 'number' => $rw->number, 'label' => $rw->label(), 'name' => $rw->name];
    }

    private function rtData(Rt $rt): array
    {
        return ['id' => $rt->id, 'rw_id' => $rt->rw_id, 'number' => $rt->number, 'label' => $rt->label()];
    }
}
