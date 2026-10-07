<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Notifikasi lonceng milik akun yang login (PRD 6.11). Semua query lewat relasi
 * `notifications()` akun itu sendiri, jadi notifikasi akun lain tidak bisa dibaca
 * atau ditandai walau ID-nya diketahui.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'unread' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);
        $user = $request->user();

        $page = $user->notifications()
            ->reorder()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->paginate($filters['per_page'] ?? 20);

        return response()->json([
            'data' => collect($page->items())->map(fn (DatabaseNotification $n) => $this->present($n)),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'unread' => $user->unreadNotifications()->count(),
            ],
        ]);
    }

    /** Dipanggil berkala oleh lonceng; dibuat seringan mungkin. */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['data' => ['unread' => $request->user()->unreadNotifications()->count()]]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json(['data' => $this->present($notification)]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['data' => ['unread' => 0], 'message' => 'Semua notifikasi ditandai sudah dibaca.']);
    }

    private function present(DatabaseNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'kind' => $notification->type,
            'title' => $notification->data['title'] ?? '',
            'body' => $notification->data['body'] ?? '',
            'url' => $notification->data['url'] ?? null,
            'icon' => $notification->data['icon'] ?? 'notification',
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
