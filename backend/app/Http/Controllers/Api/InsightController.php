<?php

namespace App\Http\Controllers\Api;

use App\Enums\AiTaskStatus;
use App\Enums\Level;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\MonthlyInsight;
use App\Models\Rw;
use App\Support\Ai\AiGateway;
use App\Support\Ai\MonthlyInsights;
use App\Support\Ai\SmartComplaint;
use App\Support\Scope\RegionScope;
use App\Support\Stats\ServiceRecap;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Monthly Insight per desa atau per RW (PRD 7.2, UAT 12). RW melihat RW-nya;
 * operator dan kepala desa melihat desa atau satu RW. Kepala desa hanya membaca;
 * menyusun (ulang) narasi oleh RW dan operator.
 */
class InsightController extends Controller
{
    public function __construct(private readonly MonthlyInsights $insights) {}

    public function show(Request $request, ServiceRecap $recap, SmartComplaint $smartComplaint, AiGateway $ai): JsonResponse
    {
        [$scope, $month] = $this->filters($request);
        $insight = $this->insights->find($scope, $month);
        $period = $this->insights->period($month);

        return response()->json(['data' => [
            'scope' => ['level' => $scope->level->value, 'label' => $recap->scopeLabel($scope)],
            'month' => $month->format('Y-m'),
            'period' => $period->toArray(),
            'insight' => $insight ? $this->insight($insight, $scope) : null,
            // Angka yang dipakai narasi (snapshot), atau angka terkini bila belum disusun.
            'metrics' => $insight?->metrics ?? $this->insights->metrics($scope, $month),
            // Akurasi Smart Complaint untuk laporan bulan itu yang sudah diputuskan pengurus.
            'smart_complaint' => $smartComplaint->accuracy($recap->complaints($scope, $period)),
            'ai_configured' => $ai->configured(),
            'can' => ['generate' => $this->canGenerate($request)],
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->canGenerate($request)) {
            throw new AuthorizationException('Anda tidak punya akses ke data ini.');
        }

        [$scope, $month] = $this->filters($request);
        $insight = $this->insights->request($scope, $month, $request->user());

        return response()->json([
            'data' => $this->insight($insight->refresh(), $scope),
            'message' => $insight->status === AiTaskStatus::Pending
                ? 'Ringkasan sedang disusun. Biasanya selesai dalam satu menit.'
                : 'Ringkasan diperbarui.',
        ], 202);
    }

    private function insight(MonthlyInsight $insight, RegionScope $scope): array
    {
        $failed = $insight->status === AiTaskStatus::Failed;

        return [
            'status' => $insight->status->value,
            'status_label' => $insight->status->label(),
            'narrative' => $insight->status === AiTaskStatus::Done ? $insight->narrative : null,
            'error' => $failed ? ($insight->narrative['error'] ?? 'Ringkasan gagal disusun.') : null,
            'model' => $insight->model,
            'generated_at' => $insight->generated_at?->toIso8601String(),
            'requested_at' => $insight->updated_at?->toIso8601String(),
            'stale' => $insight->status === AiTaskStatus::Done && $this->insights->isStale($insight, $scope),
        ];
    }

    private function canGenerate(Request $request): bool
    {
        return in_array($request->user()->role, [Role::Rw, Role::Operator], true);
    }

    /** @return array{RegionScope, CarbonImmutable} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'rw_id' => ['nullable', 'integer'],
        ]);

        $tz = config('app.display_timezone');
        $month = isset($data['month'])
            ? CarbonImmutable::createFromFormat('!Y-m', $data['month'], $tz)
            : CarbonImmutable::now($tz)->startOfMonth();

        if ($month->gt(CarbonImmutable::now($tz)->startOfMonth())) {
            throw ValidationException::withMessages(['month' => 'Ringkasan hanya untuk bulan berjalan atau bulan yang sudah lewat.']);
        }

        $scope = $request->user()->scope();
        $rw = isset($data['rw_id']) ? Rw::find($data['rw_id']) : null;
        $narrowed = isset($data['rw_id']) && ! $rw ? null : $scope->narrow($rw);

        // Insight hanya per desa atau per RW (PRD 9); RT tidak punya Monthly Insight.
        if (! $narrowed || ! in_array($narrowed->level, [Level::Village, Level::Rw], true)) {
            throw new AuthorizationException('Anda tidak punya akses ke data wilayah ini.');
        }

        return [$narrowed, $month];
    }
}
