<?php

use App\Http\Controllers\Api\Admin\OfficialController;
use App\Http\Controllers\Api\Admin\RegionController as AdminRegionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\RegionController;
use App\Http\Controllers\Api\ResetPasswordController;
use App\Http\Controllers\Api\ResidentController;
use Illuminate\Support\Facades\Route;

/*
| Konvensi respons:
|   sukses : { "data": ..., "meta"?: {...}, "message"?: "..." }
|   gagal  : { "message": "...", "errors"?: { field: [..] }, "code"?: "..." }
*/

// Publik
Route::get('regions', [RegionController::class, 'index']);

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::put('password', [AuthController::class, 'changePassword']);
    });
});

// Fitur yang butuh akun aktif (sudah diverifikasi) ditambahkan di grup ini.
// `role:` hanya saringan kasar; batas wilayah per record dicek di Policy/Gate.
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::middleware('role:rt,operator')->group(function () {
        Route::get('residents', [ResidentController::class, 'index']);
        Route::get('residents/{user}', [ResidentController::class, 'show']);
        Route::post('residents/{user}/verify', [ResidentController::class, 'verify']);
        Route::post('residents/{user}/reject', [ResidentController::class, 'reject']);
        Route::post('users/{user}/reset-password', ResetPasswordController::class);
    });

    Route::prefix('admin')->group(function () {
        Route::middleware('role:operator,kepala_desa')->group(function () {
            Route::get('regions', [AdminRegionController::class, 'index']);
            Route::get('officials', [OfficialController::class, 'index']);
        });

        Route::middleware('role:operator')->group(function () {
            Route::post('rws', [AdminRegionController::class, 'storeRw']);
            Route::put('rws/{rw}', [AdminRegionController::class, 'updateRw']);
            Route::delete('rws/{rw}', [AdminRegionController::class, 'destroyRw']);
            Route::post('rws/{rw}/rts', [AdminRegionController::class, 'storeRt']);
            Route::delete('rts/{rt}', [AdminRegionController::class, 'destroyRt']);

            Route::post('officials', [OfficialController::class, 'store']);
            Route::put('officials/{user}', [OfficialController::class, 'update']);
            Route::post('officials/{user}/suspend', [OfficialController::class, 'suspend']);
            Route::post('officials/{user}/activate', [OfficialController::class, 'activate']);
        });
    });
});
