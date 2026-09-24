<?php

namespace Tests\Feature;

use App\Support\TicketCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TicketCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_codes_are_sequential_per_prefix_and_month(): void
    {
        // 31 Okt 2026 18:00 UTC = 1 Nov 01:00 WIB: periode ikut zona Asia/Jakarta.
        Carbon::setTestNow('2026-10-31 18:00:00');

        $this->assertSame('SRT-2611-0001', TicketCode::next(TicketCode::LETTER));
        $this->assertSame('SRT-2611-0002', TicketCode::next(TicketCode::LETTER));
        $this->assertSame('LPR-2611-0001', TicketCode::next(TicketCode::COMPLAINT));

        Carbon::setTestNow('2026-12-01 00:00:00');
        $this->assertSame('SRT-2612-0001', TicketCode::next(TicketCode::LETTER));
    }
}
