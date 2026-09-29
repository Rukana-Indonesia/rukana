<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\TemporaryPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Lupa kata sandi tanpa OTP: ketua RT/operator membuat kata sandi sementara,
 * lalu menyampaikannya langsung ke pemilik akun. Kata sandi hanya ditampilkan sekali.
 */
class ResetPasswordController extends Controller
{
    public function __invoke(User $user): JsonResponse
    {
        Gate::authorize('resetPassword', $user);

        $password = TemporaryPassword::generate();
        $user->forceFill(['password' => $password, 'must_change_password' => true]);
        $user->revokeSessions();
        Audit::log('user.password_reset', $user);

        return response()->json([
            'data' => ['temporary_password' => $password],
            'message' => "Kata sandi {$user->name} sudah direset. Sampaikan kata sandi sementara ini kepadanya.",
        ]);
    }
}
