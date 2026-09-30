<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA: request dari origin di SANCTUM_STATEFUL_DOMAINS memakai sesi + cookie.
        $middleware->statefulApi();

        // API tidak punya halaman login (itu di Vue): tamu di /api/* selalu dapat 401 JSON.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : rtrim((string) config('app.frontend_url'), '/').'/masuk');

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'role' => EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Policy yang menolak (mis. warga membuka data warga lain) dicatat di audit log.
        $exceptions->report(function (AuthorizationException $e) {
            Audit::log('auth.access_denied', metadata: [
                'route' => request()->path(),
                'method' => request()->method(),
            ]);

            return false;
        });
        $exceptions->stopIgnoring(AuthorizationException::class);

        // Route model binding yang tidak menemukan data membawa nama kelas PHP di pesannya.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->expectsJson() && $e->getPrevious() instanceof ModelNotFoundException) {
                return response()->json(['message' => 'Data tidak ditemukan.'], 404);
            }
        });

        // Pesan bawaan Laravel ("This action is unauthorized.") tampil langsung di UI.
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if ($request->expectsJson() && $e->getMessage() === 'This action is unauthorized.') {
                return response()->json(['message' => 'Anda tidak punya akses ke data ini.'], 403);
            }
        });
    })->create();
