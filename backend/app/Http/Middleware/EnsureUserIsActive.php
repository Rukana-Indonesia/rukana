<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Akun yang belum diverifikasi RT hanya boleh melihat profilnya sendiri. */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isActive()) {
            return response()->json([
                'message' => 'Akun Anda belum aktif. Tunggu verifikasi dari pengurus RT.',
                'code' => 'account_not_active',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
