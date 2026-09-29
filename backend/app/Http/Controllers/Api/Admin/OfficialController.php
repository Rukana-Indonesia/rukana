<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OfficialRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Audit;
use App\Support\TemporaryPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** Akun pengurus RT/RW dan perangkat desa. Operator mengelola, kepala desa hanya melihat. */
class OfficialController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $officials = User::where('village_id', $request->user()->village_id)
            ->where('role', '!=', Role::Warga)
            ->with(['rw', 'rt'])
            ->orderByRaw("CASE role WHEN 'operator' THEN 1 WHEN 'kepala_desa' THEN 2 WHEN 'rw' THEN 3 ELSE 4 END")
            ->orderBy('rw_id')
            ->orderBy('rt_id')
            ->orderBy('name')
            ->get();

        return UserResource::collection($officials);
    }

    /** Akun baru langsung aktif dengan kata sandi sementara yang wajib diganti saat pertama masuk. */
    public function store(OfficialRequest $request): JsonResponse
    {
        $password = TemporaryPassword::generate();

        $user = User::create([
            ...$request->safe()->only(['name', 'phone', 'role']),
            ...$request->region(),
            'village_id' => $request->user()->village_id,
            'password' => $password,
            'status' => UserStatus::Active,
            'must_change_password' => true,
        ]);
        $user->forceFill(['verified_by' => $request->user()->id, 'verified_at' => now()])->save();
        Audit::log('user.official_created', $user, ['role' => $user->role->value]);

        return $this->resource($user)
            ->additional([
                'meta' => ['temporary_password' => $password],
                'message' => "Akun {$user->name} dibuat. Sampaikan kata sandi sementaranya.",
            ])
            ->response()
            ->setStatusCode(201);
    }

    public function update(OfficialRequest $request, User $user): UserResource
    {
        Gate::authorize('manageOfficial', $user);

        $user->update([...$request->safe()->only(['name', 'phone', 'role']), ...$request->region()]);
        Audit::log('user.official_updated', $user, ['changed' => array_keys($user->getChanges())]);

        return $this->resource($user)->additional(['message' => "Akun {$user->name} diperbarui."]);
    }

    public function suspend(User $user): UserResource
    {
        Gate::authorize('manageOfficial', $user);

        $user->status = UserStatus::Suspended;
        $user->revokeSessions();
        Audit::log('user.suspended', $user);

        return $this->resource($user)->additional(['message' => "Akun {$user->name} dinonaktifkan."]);
    }

    public function activate(User $user): UserResource
    {
        Gate::authorize('manageOfficial', $user);

        $user->update(['status' => UserStatus::Active]);
        Audit::log('user.activated', $user);

        return $this->resource($user)->additional(['message' => "Akun {$user->name} diaktifkan kembali."]);
    }

    private function resource(User $user): UserResource
    {
        return new UserResource($user->loadMissing(['rw', 'rt']));
    }
}
