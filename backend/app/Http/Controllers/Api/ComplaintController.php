<?php

namespace App\Http\Controllers\Api;

use App\Enums\ComplaintAction;
use App\Enums\ComplaintStatus;
use App\Enums\Level;
use App\Enums\Urgency;
use App\Http\Controllers\Controller;
use App\Http\Requests\Complaints\StoreComplaintRequest;
use App\Http\Resources\ComplaintResource;
use App\Jobs\ClassifyComplaint;
use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\ComplaintPhoto;
use App\Models\User;
use App\Support\Ai\SmartComplaint;
use App\Support\Audit;
use App\Support\Complaints\ComplaintWorkflow;
use App\Support\Complaints\PhotoProcessor;
use App\Support\Notifications\Notifier;
use App\Support\TicketCode;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Laporan warga (PRD 6.4). Warga melihat laporannya sendiri, RT/RW laporan di
 * wilayahnya, desa seluruh desa. Tab "Perlu Tindakan" berisi laporan yang sedang
 * dipegang tingkat akun yang login.
 */
class ComplaintController extends Controller
{
    private const DETAIL = ['category.parent', 'reportedCategory.parent', 'reporter', 'rt', 'rw', 'steps.actor', 'photos', 'comments.user', 'rating', 'aiLabel.suggestedCategory.parent'];

    public function __construct(private readonly ComplaintWorkflow $workflow) {}

    /** Kategori dua tingkat untuk form laporan dan filter. */
    public function categories(): JsonResponse
    {
        $parents = ComplaintCategory::whereNull('parent_id')
            ->where('is_active', true)
            ->with(['children' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $parents->map(fn (ComplaintCategory $parent) => [
            'id' => $parent->id,
            'name' => $parent->name,
            'children' => $parent->children->map(fn (ComplaintCategory $child) => [
                'id' => $child->id,
                'name' => $child->name,
                'default_level' => $child->default_level?->value,
            ]),
        ])]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([...array_column(ComplaintStatus::cases(), 'value'), 'active'])],
            'inbox' => ['nullable', 'boolean'],
            'category_id' => ['nullable', 'integer'],
            'rt_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);
        $inbox = $request->boolean('inbox');
        $level = $request->user()->role->actingLevel();

        $counts = $this->complaints($request)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $inboxCount = $level ? $this->inbox($this->complaints($request), $level)->count() : 0;

        $complaints = $this->complaints($request)
            ->with(['category.parent', 'reporter', 'rt', 'rw', 'forwards', 'rating'])
            ->withCount('photos')
            ->when($inbox, fn (Builder $q) => $level ? $this->inbox($q, $level) : $q->whereRaw('1 = 0'))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $status === 'active'
                ? $q->whereNotIn('status', [ComplaintStatus::Resolved, ComplaintStatus::Rejected])
                : $q->where('status', $status))
            // Kategori induk (mis. Infrastruktur) mencakup semua subkategorinya.
            ->when($filters['category_id'] ?? null, fn (Builder $q, int $id) => $q->whereIn('category_id', ComplaintCategory::where('id', $id)->orWhere('parent_id', $id)->select('id')))
            ->when($filters['rt_id'] ?? null, fn (Builder $q, int $id) => $q->where('rt_id', $id))
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->where('created_at', '>=', $this->startOfDay($from)))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->where('created_at', '<', $this->startOfDay($to)->addDay()))
            // Nama pelapor sengaja tidak bisa dicari: tidak semua pengurus boleh melihatnya.
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->where(fn (Builder $q) => $q
                ->where('code', 'like', '%'.strtoupper(trim($search)).'%')
                ->orWhere('title', 'like', "%{$search}%")))
            // Antrean kerja: yang paling lama menunggu di atas.
            ->when($inbox, fn (Builder $q) => $q->oldest(), fn (Builder $q) => $q->latest())
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 20);

        return ComplaintResource::collection($complaints)->additional([
            'meta' => [
                'counts' => collect(ComplaintStatus::cases())->mapWithKeys(fn (ComplaintStatus $s) => [$s->value => (int) ($counts[$s->value] ?? 0)]),
                'inbox' => $inboxCount,
            ],
        ]);
    }

    public function store(StoreComplaintRequest $request, PhotoProcessor $processor, Notifier $notifier, SmartComplaint $smartComplaint): JsonResponse
    {
        $user = $request->user();
        $key = $request->validated('idempotency_key');

        // Submit ganda dari HP (sinyal putus, tombol ditekan dua kali) mengembalikan tiket yang sama.
        if ($key && $existing = $user->complaints()->where('idempotency_key', $key)->first()) {
            return $this->resource($existing)->response();
        }

        // Kompres dulu di luar transaksi; foto yang tidak terbaca ditolak sebelum laporan dibuat.
        $photos = [];
        foreach ($request->validated('photos') ?? [] as $i => $file) {
            try {
                $photos[] = $processor->process($file);
            } catch (RuntimeException) {
                throw ValidationException::withMessages(["photos.{$i}" => 'Foto ini tidak bisa dibaca. Coba ambil ulang atau pilih foto lain.']);
            }
        }

        $stored = [];

        try {
            $complaint = DB::transaction(function () use ($request, $user, $key, $photos, &$stored) {
                $categoryId = (int) $request->validated('category_id');
                $complaint = Complaint::create([
                    'code' => TicketCode::next(TicketCode::COMPLAINT),
                    'reporter_id' => $user->id,
                    'village_id' => $user->village_id,
                    'rw_id' => $user->rw_id,
                    'rt_id' => $user->rt_id,
                    'title' => $request->validated('title'),
                    'description' => $request->validated('description'),
                    'reported_category_id' => $categoryId,
                    'category_id' => $categoryId,
                    'handling_level' => Level::Rt,
                    'status' => ComplaintStatus::Submitted,
                    'latitude' => $request->validated('latitude'),
                    'longitude' => $request->validated('longitude'),
                    'location_text' => $request->validated('location_text'),
                    'occurred_at' => $request->occurredAt(),
                    'idempotency_key' => $key,
                ]);

                $complaint->steps()->create([
                    'action' => ComplaintAction::Submitted,
                    'to_level' => Level::Rt,
                    'to_status' => ComplaintStatus::Submitted,
                    'actor_id' => $user->id,
                ]);

                foreach ($photos as $photo) {
                    $path = "complaints/{$complaint->id}/".Str::uuid().'.jpg';
                    Storage::disk('local')->put($path, $photo['contents']);
                    $stored[] = $path;

                    $complaint->photos()->create([
                        'disk' => 'local',
                        'path' => $path,
                        'mime' => $photo['mime'],
                        'size_bytes' => strlen($photo['contents']),
                        'width' => $photo['width'],
                        'height' => $photo['height'],
                    ]);
                }

                return $complaint;
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($stored);
            throw $e;
        }

        Audit::log('complaint.submitted', $complaint);
        $notifier->complaintSubmitted($complaint);

        // Saran AI disusun di queue; pengurus melihat "AI sedang menganalisis" sampai selesai.
        $smartComplaint->queue($complaint);
        ClassifyComplaint::dispatch($complaint);

        return $this->resource($complaint)
            ->additional(['message' => "Laporan terkirim. Kode tiket Anda {$complaint->code}."])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Complaint $complaint): ComplaintResource
    {
        Gate::authorize('view', $complaint);

        // Akses laporan warga oleh pengurus dicatat; pelapor membuka laporannya sendiri tidak.
        if ($complaint->reporter_id !== $request->user()->id) {
            Audit::log('complaint.viewed', $complaint);
        }

        return $this->resource($complaint);
    }

    /** verify (RT), start/accept/forward/resolve/reject (tingkat yang sedang memegang laporan). */
    public function act(Request $request, #[CurrentUser] User $user, Complaint $complaint, string $action): ComplaintResource
    {
        Gate::authorize('act', [$complaint, $action]);

        $data = $request->validate([
            'note' => [in_array($action, ['reject', 'resolve'], true) ? 'required' : 'nullable', 'string', 'max:1000'],
            'target' => [$action === 'forward' ? 'required' : 'exclude', Rule::in([Level::Rw->value, Level::Village->value])],
        ], [
            'note.required' => $action === 'reject'
                ? 'Tulis alasan penolakan, mis. laporan ganda atau bukan masalah lingkungan.'
                : 'Tulis tindak lanjut yang sudah dilakukan supaya pelapor tahu hasilnya.',
            'target.required' => 'Pilih tujuan penerusan.',
        ]);

        $complaint = $this->workflow->apply(
            $complaint,
            $action,
            $user,
            $data['note'] ?? null,
            isset($data['target']) ? Level::from($data['target']) : null,
        );

        return $this->resource($complaint)->additional(['message' => match ($action) {
            'forward' => "Laporan {$complaint->code} diteruskan ke {$complaint->handling_level->label()}.",
            'reject' => "Laporan {$complaint->code} ditolak.",
            'resolve' => "Laporan {$complaint->code} selesai ditangani.",
            default => "Laporan {$complaint->code} sekarang berstatus {$complaint->status->label()}.",
        }]);
    }

    /** Koreksi kategori dan tetapkan urgensi. */
    public function classify(Request $request, #[CurrentUser] User $user, Complaint $complaint): ComplaintResource
    {
        Gate::authorize('classify', $complaint);

        $data = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('complaint_categories', 'id')->whereNotNull('parent_id')->where('is_active', true)],
            'urgency' => ['nullable', new Enum(Urgency::class)],
        ], ['category_id.exists' => 'Pilih kategori laporan dari daftar.']);

        $complaint = $this->workflow->classify(
            $complaint,
            $user,
            ComplaintCategory::findOrFail($data['category_id']),
            isset($data['urgency']) ? Urgency::from($data['urgency']) : null,
        );

        return $this->resource($complaint)->additional(['message' => 'Kategori dan urgensi disimpan.']);
    }

    /** Ulangi Smart Complaint yang gagal (mis. AI belum dikonfigurasi atau sedang tidak bisa dihubungi). */
    public function retryAi(Complaint $complaint, SmartComplaint $smartComplaint): ComplaintResource
    {
        Gate::authorize('retryAi', $complaint);

        $smartComplaint->queue($complaint);
        ClassifyComplaint::dispatch($complaint);

        return $this->resource($complaint->unsetRelation('aiLabel'))->additional(['message' => 'Saran AI sedang disusun ulang.']);
    }

    /** Tanggapan untuk pelapor, atau catatan internal antar-pengurus. */
    public function comment(Request $request, Complaint $complaint): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:1000'],
            'is_internal' => ['nullable', 'boolean'],
        ]);
        $internal = (bool) ($data['is_internal'] ?? false);

        Gate::authorize($internal ? 'commentInternal' : 'comment', $complaint);

        $complaint->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $data['body'],
            'is_internal' => $internal,
        ]);

        return $this->resource($complaint)
            ->additional(['message' => $internal ? 'Catatan internal disimpan.' : 'Tanggapan terkirim.'])
            ->response()
            ->setStatusCode(201);
    }

    /** Foto disimpan di disk privat; hanya pihak yang boleh melihat laporan yang bisa membukanya. */
    public function photo(Complaint $complaint, ComplaintPhoto $photo): StreamedResponse
    {
        Gate::authorize('view', $complaint);

        return Storage::disk($photo->disk)->response($photo->path, "{$complaint->code}-{$photo->id}.jpg", [
            'Content-Type' => $photo->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** Laporan di dalam scope wilayah akun yang login. Scope tidak pernah diambil dari input. */
    private function complaints(Request $request): Builder
    {
        $query = Complaint::query();
        $request->user()->scope()->apply($query, 'reporter_id');

        return $query;
    }

    /** Laporan yang sedang dipegang tingkat ini dan belum final. */
    private function inbox(Builder $query, Level $level): Builder
    {
        return $query->where('handling_level', $level)
            ->whereNotIn('status', [ComplaintStatus::Resolved, ComplaintStatus::Rejected]);
    }

    /** Tanggal filter dibaca dalam zona Asia/Jakarta, dibandingkan dengan kolom UTC. */
    private function startOfDay(string $date): Carbon
    {
        return Carbon::parse($date, config('app.display_timezone'))->startOfDay()->utc();
    }

    private function resource(Complaint $complaint): ComplaintResource
    {
        return new ComplaintResource($complaint->loadMissing(self::DETAIL));
    }
}
