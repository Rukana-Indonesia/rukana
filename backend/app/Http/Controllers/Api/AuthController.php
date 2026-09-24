<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Rt;
use App\Models\User;
use App\Support\Audit;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $rt = Rt::findOrFail($request->integer('rt_id'));

        $user = User::create([
            ...$request->safe()->only(['name', 'phone', 'password', 'residency_status']),
            'role' => Role::Warga,
            'status' => UserStatus::Pending,
            'village_id' => $rt->village_id,
            'rw_id' => $rt->rw_id,
            'rt_id' => $rt->id,
        ]);

        Audit::log('user.registered', $user, user: $user);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return $this->userResource($user)->response()->setStatusCode(201);
    }

    public function login(Request $request): UserResource
    {
        $credentials = $request->validate([
            'phone' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);
        $credentials['phone'] = Phone::normalize($credentials['phone']);

        if (! Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['phone' => 'Nomor HP atau kata sandi salah.']);
        }

        /** @var User $user */
        $user = Auth::guard('web')->user();

        if (in_array($user->status, [UserStatus::Rejected, UserStatus::Suspended], true)) {
            Auth::guard('web')->logout();

            throw ValidationException::withMessages([
                'phone' => $user->status === UserStatus::Rejected
                    ? 'Pendaftaran akun ditolak'.($user->rejection_reason ? ": {$user->rejection_reason}" : '.')
                    : 'Akun dinonaktifkan. Hubungi pengurus RT atau operator desa.',
            ]);
        }

        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        Audit::log('auth.login', $user, user: $user);

        return $this->userResource($user);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Berhasil keluar.']);
    }

    public function me(Request $request): UserResource
    {
        return $this->userResource($request->user());
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $request->user()->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();
        Audit::log('user.password_changed', $request->user());

        return response()->json(['message' => 'Kata sandi berhasil diubah.']);
    }

    private function userResource(User $user): UserResource
    {
        return new UserResource($user->loadMissing(['village', 'rw', 'rt']));
    }
}
