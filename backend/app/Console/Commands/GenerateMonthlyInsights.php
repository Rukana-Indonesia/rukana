<?php

namespace App\Console\Commands;

use App\Enums\AiTaskStatus;
use App\Enums\Level;
use App\Models\Village;
use App\Support\Ai\MonthlyInsights;
use App\Support\Scope\RegionScope;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Susun Monthly Insight bulan lalu untuk setiap desa dan setiap RW-nya, supaya
 * kepala desa tinggal membukanya (UAT 12). Dijalankan scheduler tiap tanggal 1;
 * insight yang sudah jadi dilewati kecuali --force.
 */
#[Signature('insights:generate {--month= : Bulan YYYY-MM, bawaan bulan lalu} {--force : Susun ulang yang sudah jadi}')]
#[Description('Susun Monthly Insight per desa dan per RW')]
class GenerateMonthlyInsights extends Command
{
    public function handle(MonthlyInsights $insights): int
    {
        $tz = config('app.display_timezone');
        $month = $this->option('month')
            ? CarbonImmutable::createFromFormat('!Y-m', $this->option('month'), $tz)
            : CarbonImmutable::now($tz)->subMonthNoOverflow()->startOfMonth();

        foreach (Village::with('rws')->get() as $village) {
            $scopes = [
                new RegionScope($village->id, Level::Village),
                ...$village->rws->map(fn ($rw) => new RegionScope($village->id, Level::Rw, $rw->id)),
            ];

            foreach ($scopes as $scope) {
                $existing = $insights->find($scope, $month);
                if ($existing && ! $this->option('force') && $existing->status !== AiTaskStatus::Failed) {
                    continue;
                }

                $insight = $insights->request($scope, $month);
                $this->line("Insight #{$insight->id} ({$month->format('Y-m')}, ".($scope->level === Level::Rw ? "RW id {$scope->rwId}" : "desa {$village->name}").') diantrekan.');
            }
        }

        return self::SUCCESS;
    }
}
