<?php

namespace App\Jobs;

use App\Models\MonthlyInsight;
use App\Support\Ai\MonthlyInsights;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Narasi Monthly Insight lewat queue. Kegagalan dicatat di insight dan bisa disusun ulang. */
class GenerateMonthlyInsight implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly MonthlyInsight $insight)
    {
        $this->afterCommit();
    }

    public function handle(MonthlyInsights $insights): void
    {
        $insights->compose($this->insight);
    }
}
