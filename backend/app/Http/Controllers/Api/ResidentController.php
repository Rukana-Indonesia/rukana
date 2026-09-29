<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Data dan verifikasi akun warga oleh ketua RT (RT sendiri) atau operator (seluruh desa). */
class ResidentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'rt_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);
        $status = isset($filters['status']) ? UserStatus::from($filters['status']) : null;

        $counts = $this->residents($request)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $residents = $this->residents($request)
            ->with(['rw', 'rt'])
            ->when($status, fn (Builder $q) => $q->where('status', $status))
            ->when($filters['rt_id'] ?? null, fn (Builder $q, int $rtId) => $q->where('rt_id', $rtId))
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
                $digits = preg_replace('/\D+/', '', $search);
                if ($digits !== '') {
                    $q->orWhere('phone', 'like', '%'.ltrim($digits, '0').'%');
                }
            }))
            // Antrean verifikasi: yang paling lama menunggu di atas.
            ->when($status === UserStatus::Pending, fn (Builder $q) => $q->oldest(), fn (Builder $q) => $q->latest())
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 20);

        return UserResource::collection($residents)->additional([
            'meta' => ['counts' => collect(UserStatus::cases())->mapWithKeys(fn (UserStatus $s) => [$s->value => (int) ($counts[$s->value] ?? 0)])],
        ]);
    }

    public function show(User $user): UserResource
    {
        Gate::authorize('manageResident', $user);
        Audit::log('user.viewed', $user);

        return $this->resource($user);
    }

    public function verify(Request $request, User $user): UserResource
    {
        Gate::authorize('manageResident', $user);
        $this->ensurePending($user);

        $user->forceFill([
            'status' => UserStatus::Active,
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
            'rejection_reason' => null,
        ])->save();
        Audit::log('user.verified', $user);

        return $this->resource($user)->additional(['message' => "Akun {$user->name} sudah aktif."]);
    }

    public function reject(Request $request, User $user): UserResource
    {
        Gate::authorize('manageResident', $user);
        $this->ensurePending($user);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $user->forceFill([
            'status' => UserStatus::Rejected,
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
            'rejection_reason' => $data['reason'],
        ])->save();
        Audit::log('user.rejected', $user, ['reason' => $data['reason']]);

        return $this->resource($user)->additional(['message' => "Pendaftaran {$user->name} ditolak."]);
    }

    /** Warga di dalam scope wilayah akun yang login. Scope tidak pernah diambil dari input. */
    private function residents(Request $request): Builder
    {
        $query = User::query()->where('role', Role::Warga);
        $request->user()->scope()->apply($query, 'id');

        return $query;
    }

    private function ensurePending(User $user): void
    {
        abort_unless(
            $user->status === UserStatus::Pending,
            409,
            "Akun ini sudah berstatus {$user->status->label()}.",
        );
    }

    private function resource(User $user): UserResource
    {
        return new UserResource($user->loadMissing(['rw', 'rt', 'verifier']));
    }
}
