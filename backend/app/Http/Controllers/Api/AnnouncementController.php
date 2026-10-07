<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Announcements\AnnouncementRequest;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use App\Models\AnnouncementAttachment;
use App\Support\Audit;
use App\Support\Notifications\Notifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Pengumuman bertarget (PRD 6.6, UAT 09). Daftar biasa berisi pengumuman yang
 * sedang tayang untuk wilayah akun yang login; `manage=1` berisi pengumuman yang
 * dikelola akun itu, termasuk yang terjadwal dan kedaluwarsa.
 */
class AnnouncementController extends Controller
{
    private const DETAIL = ['author', 'attachments', 'targetRw', 'targetRt'];

    public function __construct(private readonly Notifier $notifier) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'manage' => ['nullable', 'boolean'],
            'state' => ['nullable', Rule::in(['scheduled', 'active', 'expired'])],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);
        $user = $request->user();
        $manage = $request->boolean('manage');

        if ($manage) {
            Gate::authorize('create', Announcement::class);
        }

        $base = fn () => $manage ? Announcement::manageableBy($user) : Announcement::visibleTo($user)->live();
        $counts = $manage ? [
            'scheduled' => $base()->where('published_at', '>', now())->count(),
            'active' => $base()->live()->count(),
            'expired' => $base()->where('expires_at', '<=', now())->count(),
        ] : null;

        $announcements = $base()
            ->with(['author', 'targetRw', 'targetRt'])
            ->withCount('attachments')
            ->when($manage ? ($filters['state'] ?? null) : null, fn (Builder $q, string $state) => match ($state) {
                'scheduled' => $q->where('published_at', '>', now()),
                'active' => $q->live(),
                'expired' => $q->where('expires_at', '<=', now()),
            })
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->where(fn (Builder $q) => $q
                ->where('title', 'like', "%{$search}%")
                ->orWhere('body', 'like', "%{$search}%")))
            ->latest('published_at')
            ->latest('id')
            ->paginate($filters['per_page'] ?? 20);

        return AnnouncementResource::collection($announcements)->additional(['meta' => array_filter(['counts' => $counts])]);
    }

    public function show(Announcement $announcement): AnnouncementResource
    {
        Gate::authorize('view', $announcement);

        return $this->resource($announcement);
    }

    public function store(AnnouncementRequest $request): JsonResponse
    {
        Gate::authorize('create', Announcement::class);

        [$level, $rwId, $rtId] = $request->target();
        $stored = [];

        try {
            $announcement = DB::transaction(function () use ($request, $level, $rwId, $rtId, &$stored) {
                $announcement = Announcement::create([
                    'village_id' => $request->user()->village_id,
                    'author_id' => $request->user()->id,
                    'title' => $request->validated('title'),
                    'body' => $request->validated('body'),
                    'target_level' => $level,
                    'target_rw_id' => $rwId,
                    'target_rt_id' => $rtId,
                    'published_at' => $request->publishedAt() ?? now(),
                    'expires_at' => $request->expiresAt(),
                ]);
                $this->storeAttachments($announcement, $request->file('attachments', []), $stored);

                return $announcement;
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($stored);
            throw $e;
        }

        Audit::log('announcement.created', $announcement, ['target' => $level->value, 'rw_id' => $rwId, 'rt_id' => $rtId]);
        $sent = $this->notifier->announcementPublished($announcement);

        return $this->resource($announcement)
            ->additional(['message' => $announcement->state() === 'scheduled'
                ? 'Pengumuman dijadwalkan. Warga diberi tahu saat pengumuman terbit.'
                : "Pengumuman diterbitkan dan dikirim ke {$sent} akun."])
            ->response()
            ->setStatusCode(201);
    }

    /** Dikirim sebagai POST dengan `_method=PUT` karena membawa berkas (multipart). */
    public function update(AnnouncementRequest $request, Announcement $announcement): AnnouncementResource
    {
        Gate::authorize('manage', $announcement);

        [$level, $rwId, $rtId] = $request->target();
        $stored = [];
        $removed = [];

        try {
            DB::transaction(function () use ($request, $announcement, $level, $rwId, $rtId, &$stored, &$removed) {
                $announcement->fill([
                    'title' => $request->validated('title'),
                    'body' => $request->validated('body'),
                    'target_level' => $level,
                    'target_rw_id' => $rwId,
                    'target_rt_id' => $rtId,
                    'published_at' => $request->publishedAt() ?? $announcement->published_at,
                    'expires_at' => $request->expiresAt(),
                ])->save();

                $files = $announcement->attachments()->whereIn('id', $request->validated('remove_attachments') ?? [])->get();
                $removed = $files->pluck('path')->all();
                $files->each->delete();

                $this->storeAttachments($announcement, $request->file('attachments', []), $stored);
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($stored);
            throw $e;
        }

        Storage::disk('local')->delete($removed);
        Audit::log('announcement.updated', $announcement, ['target' => $level->value, 'rw_id' => $rwId, 'rt_id' => $rtId]);
        // Pengumuman terjadwal yang dimajukan ke sekarang langsung dikirim; yang sudah
        // pernah dikirim tidak dikirim ulang.
        $this->notifier->announcementPublished($announcement);

        return $this->resource($announcement->refresh())->additional(['message' => 'Pengumuman disimpan.']);
    }

    public function destroy(Announcement $announcement): JsonResponse
    {
        Gate::authorize('manage', $announcement);

        DB::transaction(function () use ($announcement) {
            $announcement->delete();
            // Notifikasi lama tidak boleh menautkan ke pengumuman yang sudah dihapus.
            DB::table('notifications')
                ->where('type', 'announcement.published')
                ->where('data->announcement_id', $announcement->id)
                ->delete();
        });
        Audit::log('announcement.deleted', $announcement);

        return response()->json(['message' => 'Pengumuman dihapus.']);
    }

    /** Lampiran di disk privat; hanya pihak yang boleh melihat pengumuman yang bisa membukanya. */
    public function attachment(Announcement $announcement, AnnouncementAttachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $announcement);

        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** @param  list<UploadedFile>  $files */
    private function storeAttachments(Announcement $announcement, array $files, array &$stored): void
    {
        foreach ($files as $file) {
            $path = $file->store("announcements/{$announcement->id}", 'local');
            $stored[] = $path;

            $announcement->attachments()->create([
                'disk' => 'local',
                'path' => $path,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'mime' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
            ]);
        }
    }

    private function resource(Announcement $announcement): AnnouncementResource
    {
        return new AnnouncementResource($announcement->loadMissing(self::DETAIL));
    }
}
