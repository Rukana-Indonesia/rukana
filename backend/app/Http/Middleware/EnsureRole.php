<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Support\Audit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pemeriksaan kasar per peran di level route, mis. `role:rt,rw`. Pemeriksaan
 * wilayah per record tetap di Policy.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $role = $request->user()?->role;

        if (! $role || ! in_array($role, array_map(Role::from(...), $roles), true)) {
            Audit::log('auth.access_denied', metadata: ['route' => $request->path(), 'required_roles' => $roles]);

            return response()->json(['message' => 'Anda tidak punya akses ke fitur ini.'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
