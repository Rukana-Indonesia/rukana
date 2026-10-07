<?php

namespace App\Http\Controllers\Api;

use App\Enums\Level;
use App\Http\Controllers\Controller;
use App\Http\Resources\RatingResource;
use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\LetterRequest;
use App\Models\Rating;
use App\Models\Rt;
use App\Models\Rw;
use App\Models\ServiceType;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Rating kepuasan layanan (PRD 6.7). Pemohon/pelapor memberi rating sekali setelah
 * tiketnya selesai; pengurus melihat rekap untuk wilayahnya (RT: RT-nya, RW: RW-nya,
 * desa: semua). Rekap tidak pernah memuat nama pemberi rating.
 */
class RatingController extends Controller
{
    public function storeForLetter(Request $request, LetterRequest $letter): JsonResponse
    {
        Gate::authorize('rate', $letter);

        return $this->store($request, $letter, ['service_type_id' => $letter->service_type_id]);
    }

    public function storeForComplaint(Request $request, Complaint $complaint): JsonResponse
    {
        Gate::authorize('rate', $complaint);

        return $this->store($request, $complaint, ['category_id' => $complaint->category_id]);
    }

    /** Rekap rating di dalam scope wilayah akun yang login. */
    public function summary(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(['letter_request', 'complaint'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $scope = $request->user()->scope();
        $ratings = fn () => Rating::query()
            ->tap(fn (Builder $q) => $scope->apply($q))
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->where('rateable_type', $type))
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->where('created_at', '>=', $this->startOfDay($from)))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->where('created_at', '<', $this->startOfDay($to)->addDay()));

        $overall = $ratings()->selectRaw('count(*) as total, avg(score) as average')->first();
        $distribution = $ratings()->selectRaw('score, count(*) as total')->groupBy('score')->pluck('total', 'score');
        $byType = $ratings()->selectRaw('rateable_type, count(*) as total, avg(score) as average')->groupBy('rateable_type')->get()->keyBy('rateable_type');

        // Rincian satu tingkat di bawah scope: desa → per RW, RW → per RT.
        $regionColumn = match ($scope->level) {
            Level::Village => 'rw_id',
            Level::Rw => 'rt_id',
            default => null,
        };
        $byRegion = [];
        if ($regionColumn) {
            $rows = $ratings()->selectRaw("{$regionColumn} as region_id, count(*) as total, avg(score) as average")->groupBy($regionColumn)->get();
            $labels = $regionColumn === 'rw_id'
                ? Rw::whereIn('id', $rows->pluck('region_id'))->get()->mapWithKeys(fn (Rw $rw) => [$rw->id => $rw->label()])
                : Rt::whereIn('id', $rows->pluck('region_id'))->get()->mapWithKeys(fn (Rt $rt) => [$rt->id => $rt->label()]);
            $byRegion = $rows->map(fn ($row) => [
                'id' => (int) $row->region_id,
                'label' => $labels[$row->region_id] ?? '—',
                ...$this->stat($row),
            ])->sortBy('label')->values();
        }

        $services = $ratings()->whereNotNull('service_type_id')
            ->selectRaw('service_type_id, count(*) as total, avg(score) as average')
            ->groupBy('service_type_id')->get();
        $serviceNames = ServiceType::withTrashed()->whereIn('id', $services->pluck('service_type_id'))->pluck('name', 'id');
        $categories = $ratings()->whereNotNull('category_id')
            ->selectRaw('category_id, count(*) as total, avg(score) as average')
            ->groupBy('category_id')->get();
        $categoryNames = ComplaintCategory::whereIn('id', $categories->pluck('category_id'))->pluck('name', 'id');

        $recent = $ratings()->whereNotNull('comment')->with(['rt', 'rw', 'serviceType', 'category'])->latest('created_at')->latest('id')->limit(5)->get();

        return response()->json(['data' => [
            ...$this->stat($overall),
            'distribution' => collect(range(5, 1))->map(fn (int $score) => ['score' => $score, 'total' => (int) ($distribution[$score] ?? 0)]),
            'by_type' => [
                'letter_request' => $this->stat($byType['letter_request'] ?? null),
                'complaint' => $this->stat($byType['complaint'] ?? null),
            ],
            'by_region' => $byRegion,
            'by_service' => $services->map(fn ($row) => ['name' => $serviceNames[$row->service_type_id] ?? '—', ...$this->stat($row)])->sortByDesc('total')->values(),
            'by_category' => $categories->map(fn ($row) => ['name' => $categoryNames[$row->category_id] ?? '—', ...$this->stat($row)])->sortByDesc('total')->values(),
            'recent' => $recent->map(fn (Rating $rating) => [
                'id' => $rating->id,
                'score' => $rating->score,
                'comment' => $rating->comment,
                'type' => $rating->rateable_type,
                'subject' => $rating->serviceType?->name ?? $rating->category?->name,
                'region' => "{$rating->rt->label()}/{$rating->rw->label()}",
                'created_at' => $rating->created_at?->toIso8601String(),
            ]),
        ]]);
    }

    private function store(Request $request, LetterRequest|Complaint $rateable, array $snapshot): JsonResponse
    {
        $data = $request->validate([
            'score' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:500'],
        ], ['score.required' => 'Pilih jumlah bintang (1–5).']);

        try {
            $rating = $rateable->rating()->create([
                'user_id' => $request->user()->id,
                'score' => $data['score'],
                'comment' => filled($data['comment'] ?? null) ? $data['comment'] : null,
                'village_id' => $rateable->village_id,
                'rw_id' => $rateable->rw_id,
                'rt_id' => $rateable->rt_id,
                ...$snapshot,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Dua kiriman bersamaan: yang pertama tersimpan, yang kedua ditolak.
            throw new ConflictHttpException('Anda sudah memberi rating untuk tiket ini.');
        }

        Audit::log('rating.submitted', $rateable, ['score' => $rating->score]);

        return (new RatingResource($rating))
            ->additional(['message' => 'Terima kasih! Rating Anda membantu pengurus memperbaiki layanan.'])
            ->response()
            ->setStatusCode(201);
    }

    private function stat(?Model $row): array
    {
        $total = (int) ($row?->total ?? 0);

        return [
            'total' => $total,
            'average' => $total > 0 ? round((float) $row->average, 1) : null,
        ];
    }

    private function startOfDay(string $date): Carbon
    {
        return Carbon::parse($date, config('app.display_timezone'))->startOfDay()->utc();
    }
}
