<?php

namespace App\Http\Controllers\Api;

use App\Enums\LetterAction;
use App\Enums\LetterStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Letters\StoreLetterRequest;
use App\Http\Resources\LetterResource;
use App\Models\LetterAttachment;
use App\Models\LetterRequest;
use App\Models\User;
use App\Support\Audit;
use App\Support\Letters\LetterWorkflow;
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
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Surat pengantar berjenjang. Daftar ini sekaligus register pengantar: warga
 * melihat suratnya sendiri, RT/RW suratnya di wilayahnya, desa seluruh desa.
 */
class LetterController extends Controller
{
    public function __construct(private readonly LetterWorkflow $workflow) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([...array_column(LetterStatus::cases(), 'value'), 'active'])],
            'inbox' => ['nullable', 'boolean'],
            'service_type_id' => ['nullable', 'integer'],
            'rt_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);
        $inbox = $request->boolean('inbox');
        $awaiting = $this->awaiting($request);

        $counts = $this->letters($request)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $letters = $this->letters($request)
            ->with(['serviceType', 'user', 'rt', 'rw'])
            ->when($inbox, fn (Builder $q) => $q->whereIn('status', $awaiting))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $status === 'active'
                ? $q->whereNotIn('status', [LetterStatus::Completed, LetterStatus::Rejected])
                : $q->where('status', $status))
            ->when($filters['service_type_id'] ?? null, fn (Builder $q, int $id) => $q->where('service_type_id', $id))
            ->when($filters['rt_id'] ?? null, fn (Builder $q, int $id) => $q->where('rt_id', $id))
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->where('submitted_at', '>=', $this->startOfDay($from)))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->where('submitted_at', '<', $this->startOfDay($to)->addDay()))
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->where(fn (Builder $q) => $q
                ->where('code', 'like', '%'.strtoupper(trim($search)).'%')
                ->orWhereHas('user', fn (Builder $q) => $q->where('name', 'like', "%{$search}%"))))
            // Antrean kerja: yang paling lama menunggu di atas.
            ->when($inbox, fn (Builder $q) => $q->oldest('submitted_at'), fn (Builder $q) => $q->latest('submitted_at'))
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 20);

        return LetterResource::collection($letters)->additional([
            'meta' => [
                'counts' => collect(LetterStatus::cases())->mapWithKeys(fn (LetterStatus $s) => [$s->value => (int) ($counts[$s->value] ?? 0)]),
                'inbox' => (int) collect($awaiting)->sum(fn (LetterStatus $s) => $counts[$s->value] ?? 0),
            ],
        ]);
    }

    public function store(StoreLetterRequest $request): JsonResponse
    {
        $user = $request->user();
        $key = $request->validated('idempotency_key');

        // Submit ganda dari HP (sinyal putus, tombol ditekan dua kali) mengembalikan tiket yang sama.
        if ($key && $existing = $user->letterRequests()->where('idempotency_key', $key)->first()) {
            return $this->resource($existing)->response();
        }

        $service = $request->service();
        $nik = $request->validated('nik');
        $stored = [];

        try {
            $letter = DB::transaction(function () use ($request, $user, $service, $nik, $key, &$stored) {
                $letter = LetterRequest::create([
                    'code' => TicketCode::next(TicketCode::LETTER),
                    'user_id' => $user->id,
                    'service_type_id' => $service->id,
                    'flow' => $service->flow,
                    'village_id' => $user->village_id,
                    'rw_id' => $user->rw_id,
                    'rt_id' => $user->rt_id,
                    'purpose' => $request->validated('purpose'),
                    'form_data' => $request->formData(),
                    'nik_encrypted' => $nik,
                    'nik_masked' => $nik ? substr($nik, 0, 4).str_repeat('*', 8).substr($nik, -4) : null,
                    'status' => LetterStatus::Submitted,
                    'current_level' => LetterStatus::Submitted->currentLevel(),
                    'submitted_at' => now(),
                    'idempotency_key' => $key,
                ]);

                $letter->steps()->create([
                    'action' => LetterAction::Submitted,
                    'to_status' => LetterStatus::Submitted,
                    'actor_id' => $user->id,
                ]);

                foreach ($request->validated('attachments') ?? [] as $item) {
                    $file = $item['file'];
                    $path = $file->store("letters/{$letter->id}", 'local');
                    $stored[] = $path;

                    $letter->attachments()->create([
                        'uploaded_by' => $user->id,
                        'requirement_label' => $item['label'] ?? null,
                        'disk' => 'local',
                        'path' => $path,
                        'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                        'mime' => $file->getMimeType(),
                        'size_bytes' => $file->getSize(),
                    ]);
                }

                return $letter;
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($stored);
            throw $e;
        }

        Audit::log('letter.submitted', $letter);

        return $this->resource($letter)
            ->additional(['message' => "Pengajuan terkirim. Kode tiket Anda {$letter->code}."])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, LetterRequest $letter): LetterResource
    {
        Gate::authorize('view', $letter);

        // Akses data warga oleh pengurus dicatat; pemohon membuka suratnya sendiri tidak.
        if ($letter->user_id !== $request->user()->id) {
            Audit::log('letter.viewed', $letter);
        }

        return $this->resource($letter);
    }

    /** verify (RT), approve (RW), receive/ready/pickup (desa), reject (tingkat yang memegang surat). */
    public function act(Request $request, #[CurrentUser] User $user, LetterRequest $letter, string $action): LetterResource
    {
        Gate::authorize('act', [$letter, $action]);

        $note = $request->validate([
            'note' => [$action === 'reject' ? 'required' : 'nullable', 'string', 'max:500'],
        ], ['note.required' => 'Tulis alasan penolakan supaya warga tahu apa yang perlu diperbaiki.'])['note'] ?? null;

        $letter = $this->workflow->apply($letter, $action, $user, $note);

        return $this->resource($letter)->additional(['message' => match ($action) {
            'reject' => "Surat {$letter->code} ditolak.",
            'pickup' => "Surat {$letter->code} selesai diambil.",
            default => "Surat {$letter->code} sekarang berstatus {$letter->status->label()}.",
        }]);
    }

    /** NIK utuh untuk operator yang memproses surat di sistem desa. Setiap pembukaan dicatat. */
    public function nik(LetterRequest $letter): JsonResponse
    {
        Gate::authorize('viewNik', $letter);
        Audit::log('letter.nik_viewed', $letter);

        return response()->json(['data' => ['nik' => $letter->nik_encrypted]]);
    }

    /** Lampiran disimpan di disk privat; hanya pihak yang boleh melihat surat yang bisa membukanya. */
    public function attachment(LetterRequest $letter, LetterAttachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $letter);

        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0',
        ]);
    }

    /** Surat di dalam scope wilayah akun yang login. Scope tidak pernah diambil dari input. */
    private function letters(Request $request): Builder
    {
        $query = LetterRequest::query();
        $request->user()->scope()->apply($query);

        return $query;
    }

    /** Status yang menunggu tindakan akun ini (kosong untuk warga dan kepala desa). */
    private function awaiting(Request $request): array
    {
        $level = $request->user()->role->actingLevel();

        return $level ? LetterStatus::awaiting($level) : [];
    }

    /** Tanggal filter dibaca dalam zona Asia/Jakarta, dibandingkan dengan kolom UTC. */
    private function startOfDay(string $date): Carbon
    {
        return Carbon::parse($date, config('app.display_timezone'))->startOfDay()->utc();
    }

    private function resource(LetterRequest $letter): LetterResource
    {
        return new LetterResource($letter->loadMissing(['serviceType', 'user', 'rt', 'rw', 'steps.actor', 'attachments']));
    }
}
