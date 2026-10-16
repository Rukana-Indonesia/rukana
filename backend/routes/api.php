<?php

use App\Http\Controllers\Api\Admin\OfficialController;
use App\Http\Controllers\Api\Admin\RegionController as AdminRegionController;
use App\Http\Controllers\Api\Admin\ServiceTypeController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\AskController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ComplaintController;
use App\Http\Controllers\Api\ComplaintMapController;
use App\Http\Controllers\Api\InsightController;
use App\Http\Controllers\Api\LetterController;
use App\Http\Controllers\Api\LetterPdfController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\RatingController;
use App\Http\Controllers\Api\RecapController;
use App\Http\Controllers\Api\RegionController;
use App\Http\Controllers\Api\ResetPasswordController;
use App\Http\Controllers\Api\ResidentController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\TicketController;
use App\Support\Complaints\ComplaintWorkflow;
use App\Support\Letters\LetterWorkflow;
use Illuminate\Support\Facades\Route;

/*
| Konvensi respons:
|   sukses : { "data": ..., "meta"?: {...}, "message"?: "..." }
|   gagal  : { "message": "...", "errors"?: { field: [..] }, "code"?: "..." }
*/

// Publik
Route::get('regions', [RegionController::class, 'index']);
Route::get('services', [ServiceController::class, 'index']);
Route::get('services/{slug}', [ServiceController::class, 'show']);
Route::get('tickets/{code}', [TicketController::class, 'show'])->middleware('throttle:ticket-check');

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
    // Surat pengantar. Daftar & detail untuk semua peran (dibatasi scope wilayah);
    // pengajuan hanya warga, aksi per tingkat dicek di LetterRequestPolicy.
    Route::get('letters', [LetterController::class, 'index']);
    Route::post('letters', [LetterController::class, 'store'])->middleware(['role:warga', 'throttle:letter-submit']);
    Route::get('letters/{letter}', [LetterController::class, 'show']);
    Route::get('letters/{letter}/pdf', LetterPdfController::class);
    Route::get('letters/{letter}/nik', [LetterController::class, 'nik']);
    Route::get('letters/{letter}/attachments/{attachment}', [LetterController::class, 'attachment'])->scopeBindings();
    Route::post('letters/{letter}/rating', [RatingController::class, 'storeForLetter'])->middleware('role:warga');
    Route::post('letters/{letter}/{action}', [LetterController::class, 'act'])
        ->whereIn('action', LetterWorkflow::ACTIONS)
        ->middleware('role:rt,rw,operator');

    // Laporan warga. Sama seperti surat: pelaporan hanya warga, aksi dicek per
    // tingkat di ComplaintPolicy (hanya tingkat yang sedang memegang laporan).
    Route::get('complaint-categories', [ComplaintController::class, 'categories']);
    Route::get('complaints', [ComplaintController::class, 'index']);
    Route::post('complaints', [ComplaintController::class, 'store'])->middleware(['role:warga', 'throttle:complaint-submit']);
    Route::get('complaints/{complaint}', [ComplaintController::class, 'show']);
    Route::get('complaints/{complaint}/photos/{photo}', [ComplaintController::class, 'photo'])->scopeBindings();
    Route::post('complaints/{complaint}/comments', [ComplaintController::class, 'comment'])->middleware('throttle:complaint-comment');
    Route::post('complaints/{complaint}/rating', [RatingController::class, 'storeForComplaint'])->middleware('role:warga');
    Route::put('complaints/{complaint}/classification', [ComplaintController::class, 'classify'])->middleware('role:rt,rw,operator');
    Route::post('complaints/{complaint}/ai-label/retry', [ComplaintController::class, 'retryAi'])->middleware(['role:rt,rw,operator', 'throttle:ai-generate']);
    Route::post('complaints/{complaint}/{action}', [ComplaintController::class, 'act'])
        ->whereIn('action', ComplaintWorkflow::ACTIONS)
        ->middleware('role:rt,rw,operator');

    // Pengumuman bertarget. Siapa boleh membuat/mengubah dan untuk wilayah mana
    // dicek di AnnouncementPolicy + AnnouncementRequest (target RT/RW dari akun).
    Route::get('announcements', [AnnouncementController::class, 'index']);
    Route::post('announcements', [AnnouncementController::class, 'store'])->middleware(['role:rt,rw,operator', 'throttle:announcement-write']);
    Route::get('announcements/{announcement}', [AnnouncementController::class, 'show']);
    Route::put('announcements/{announcement}', [AnnouncementController::class, 'update'])->middleware(['role:rt,rw,operator', 'throttle:announcement-write']);
    Route::delete('announcements/{announcement}', [AnnouncementController::class, 'destroy'])->middleware('role:rt,rw,operator');
    Route::get('announcements/{announcement}/attachments/{attachment}', [AnnouncementController::class, 'attachment'])->scopeBindings();

    // Notifikasi lonceng milik akun sendiri.
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->whereUuid('id');

    // Rekap rating kepuasan untuk dasbor pengurus (scope wilayah dari akun).
    Route::get('ratings/summary', [RatingController::class, 'summary'])->middleware('role:rt,rw,operator,kepala_desa');

    // Rekap dasbor per wilayah dan periode. Ekspor Excel/PDF hanya RW dan operator (PRD 9).
    Route::get('recap', [RecapController::class, 'show'])->middleware('role:rt,rw,operator,kepala_desa');
    Route::get('recap/export', [RecapController::class, 'export'])->middleware(['role:rw,operator', 'throttle:recap-export']);

    // Peta sebaran laporan (PRD 6.9): pengurus dan desa, dibatasi wilayah akun.
    Route::get('complaint-map', ComplaintMapController::class)->middleware('role:rt,rw,operator,kepala_desa');

    // AI (PRD 7–9). Ask Rukana: RT/RW/operator/kepala desa, dibatasi wilayah akun.
    // Monthly Insight: RW dan desa; kepala desa hanya membaca.
    Route::middleware('role:rt,rw,operator,kepala_desa')->group(function () {
        Route::get('ask/conversations', [AskController::class, 'index']);
        Route::get('ask/conversations/{conversation}', [AskController::class, 'show']);
        Route::delete('ask/conversations/{conversation}', [AskController::class, 'destroy']);
        Route::post('ask', [AskController::class, 'store'])->middleware('throttle:ai-ask');
    });
    Route::get('insights', [InsightController::class, 'show'])->middleware('role:rw,operator,kepala_desa');
    Route::post('insights', [InsightController::class, 'store'])->middleware(['role:rw,operator', 'throttle:ai-generate']);

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
            Route::get('services', [ServiceTypeController::class, 'index']);
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

            Route::post('services', [ServiceTypeController::class, 'store']);
            Route::put('services/{serviceType}', [ServiceTypeController::class, 'update']);
            Route::delete('services/{serviceType}', [ServiceTypeController::class, 'destroy']);
        });
    });
});
