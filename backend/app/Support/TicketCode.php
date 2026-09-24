<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class TicketCode
{
    public const LETTER = 'SRT';

    public const COMPLAINT = 'LPR';

    /**
     * Kode tiket berikutnya, mis. SRT-2610-0042. Nomor urut direset tiap bulan
     * (zona Asia/Jakarta). Panggil di dalam transaksi yang sama dengan insert
     * tiketnya supaya kunci baris dipegang sampai commit.
     */
    public static function next(string $prefix): string
    {
        $period = now(config('app.display_timezone'))->format('ym');

        return DB::transaction(function () use ($prefix, $period) {
            DB::table('ticket_sequences')->insertOrIgnore(['prefix' => $prefix, 'period' => $period, 'last_number' => 0]);

            $number = DB::table('ticket_sequences')
                ->where(['prefix' => $prefix, 'period' => $period])
                ->lockForUpdate()
                ->value('last_number') + 1;

            DB::table('ticket_sequences')
                ->where(['prefix' => $prefix, 'period' => $period])
                ->update(['last_number' => $number]);

            return sprintf('%s-%s-%04d', $prefix, $period, $number);
        });
    }
}
