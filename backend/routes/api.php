<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\RegionController;
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
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    //
});
