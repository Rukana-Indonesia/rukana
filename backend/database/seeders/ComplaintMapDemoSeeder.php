<?php

namespace Database\Seeders;

use App\Enums\ComplaintAction;
use App\Enums\ComplaintStatus;
use App\Enums\Level;
use App\Enums\Role;
use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\Rt;
use App\Models\User;
use App\Support\TicketCode;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Laporan contoh bertitik lokasi untuk peta sebaran (PRD 6.9): tersebar di 4 RT
 * dan ±75 hari terakhir, dengan tiga titik masalah berulang. Hanya untuk dev dan
 * demo. Tidak memicu Smart Complaint, jadi tidak memakai kuota Anthropic. Aman
 * dijalankan ulang: laporan yang sudah ada (per idempotency_key) dilewati.
 *
 *   php artisan db:seed --class=ComplaintMapDemoSeeder
 */
class ComplaintMapDemoSeeder extends Seeder
{
    /**
     * [kunci, rt (RW-RT), kategori, judul, status, hari lalu, lat, lng]. Tiga
     * kelompok pertama sengaja berada di sel ±110 m yang sama (titik berulang).
     */
    private const array ROWS = [
        // Jalan berlubang di tikungan RT 01/RW 04.
        ['map-01', '04-01', 'jalan-rusak', 'Jalan berlubang di tikungan dekat pos ronda', 'resolved', 68, -6.9165200, 107.6115100],
        ['map-02', '04-01', 'jalan-rusak', 'Lubang jalan makin lebar setelah hujan', 'in_progress', 21, -6.9166100, 107.6116300],
        ['map-03', '04-01', 'jalan-rusak', 'Motor tergelincir di lubang jalan tikungan', 'verified', 6, -6.9165700, 107.6114600],
        ['map-04', '04-01', 'saluran-air-mampet', 'Air menggenang di tikungan karena got tertutup', 'submitted', 2, -6.9164800, 107.6115800],
        // Sampah menumpuk di lahan kosong RT 01/RW 03.
        ['map-05', '03-01', 'sampah-menumpuk', 'Sampah menumpuk di lahan kosong ujung gang', 'resolved', 52, -6.9135300, 107.6085200],
        ['map-06', '03-01', 'sampah-menumpuk', 'Tumpukan sampah lahan kosong muncul lagi', 'forwarded', 14, -6.9136100, 107.6086000],
        ['map-07', '03-01', 'sampah-menumpuk', 'Bau sampah dari lahan kosong sampai ke rumah warga', 'submitted', 1, -6.9135600, 107.6084700],
        // Saluran mampet di RT 02/RW 04.
        ['map-08', '04-02', 'saluran-air-mampet', 'Saluran air depan mushola mampet', 'resolved', 40, -6.9175400, 107.6095300],
        ['map-09', '04-02', 'saluran-air-mampet', 'Got depan mushola meluap saat hujan deras', 'in_progress', 9, -6.9176000, 107.6096100],
        // Laporan tunggal.
        ['map-10', '03-02', 'lampu-jalan-mati', 'Lampu jalan gang mati sejak minggu lalu', 'resolved', 33, -6.9149800, 107.6102400],
        ['map-11', '03-02', 'fasilitas-umum-rusak', 'Bangku taman bermain patah', 'verified', 11, -6.9142200, 107.6093100],
        ['map-12', '03-01', 'gangguan-keamanan', 'Kerap ada orang asing nongkrong malam di gardu', 'rejected', 27, -6.9128700, 107.6078400],
        ['map-13', '04-01', 'lampu-jalan-mati', 'Lampu PJU depan lapangan berkedip', 'submitted', 4, -6.9159300, 107.6123900],
        ['map-14', '04-02', 'fasilitas-umum-rusak', 'Atap pos kamling bocor', 'resolved', 61, -6.9183100, 107.6088200],
        ['map-15', '03-02', 'saluran-air-mampet', 'Gorong-gorong dekat sekolah tersumbat', 'forwarded', 17, -6.9153700, 107.6108800],
        ['map-16', '04-02', 'sampah-menumpuk', 'Sampah di bantaran sungai belum diangkut', 'submitted', 3, -6.9188600, 107.6101500],
    ];

    public function run(): void
    {
        $categories = ComplaintCategory::pluck('id', 'slug');
        $rts = Rt::with('rw')->get()->keyBy(fn (Rt $rt) => sprintf('%02d-%02d', $rt->rw->number, $rt->number));
        $created = 0;

        foreach (self::ROWS as $i => [$key, $rtKey, $slug, $title, $status, $daysAgo, $lat, $lng]) {
            $rt = $rts->get($rtKey);
            $residents = User::where('role', Role::Warga)->where('rt_id', $rt?->id)->where('status', 'active')->orderBy('id')->get();

            if (! $rt || $residents->isEmpty() || Complaint::where('idempotency_key', "demo-{$key}")->exists()) {
                continue;
            }

            $reporter = $residents[$i % $residents->count()];
            $head = User::where('role', Role::Rt)->where('rt_id', $rt->id)->first() ?? $reporter;
            $at = now()->subDays($daysAgo)->setTime(1 + $i % 10, ($i * 7) % 60);

            DB::transaction(fn () => $this->complaint(
                $reporter, $head, $categories[$slug], $key, $title,
                ComplaintStatus::from($status), $at, $lat, $lng,
            ));
            $created++;
        }

        $this->command?->info("{$created} laporan contoh peta ditambahkan.");
    }

    private function complaint(
        User $reporter, User $head, int $categoryId, string $key, string $title,
        ComplaintStatus $status, CarbonInterface $at, float $lat, float $lng,
    ): void {
        $verified = $at->copy()->addHours(5);
        $level = $status === ComplaintStatus::Forwarded ? Level::Rw : Level::Rt;

        $complaint = Complaint::create([
            'code' => TicketCode::next(TicketCode::COMPLAINT, $at),
            'reporter_id' => $reporter->id,
            'village_id' => $reporter->village_id,
            'rw_id' => $reporter->rw_id,
            'rt_id' => $reporter->rt_id,
            'title' => $title,
            'description' => "{$title}. Mohon segera ditindaklanjuti. (Data contoh peta)",
            'reported_category_id' => $categoryId,
            'category_id' => $categoryId,
            'handling_level' => $level,
            'status' => $status,
            'latitude' => $lat,
            'longitude' => $lng,
            'occurred_at' => $at->copy()->subHours(3),
            'rejection_reason' => $status === ComplaintStatus::Rejected ? 'Bukan masalah layanan; sudah dikoordinasikan dengan siskamling.' : null,
            'verified_at' => $status === ComplaintStatus::Submitted || $status === ComplaintStatus::Rejected ? null : $verified,
            'resolved_at' => $status === ComplaintStatus::Resolved ? $at->copy()->addDays(3) : null,
            'idempotency_key' => "demo-{$key}",
        ]);
        $complaint->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();

        $steps = [[ComplaintAction::Submitted, null, Level::Rt, null, ComplaintStatus::Submitted, $reporter, $at]];

        if ($status === ComplaintStatus::Rejected) {
            $steps[] = [ComplaintAction::Rejected, Level::Rt, Level::Rt, ComplaintStatus::Submitted, ComplaintStatus::Rejected, $head, $verified];
        } elseif ($status !== ComplaintStatus::Submitted) {
            $steps[] = [ComplaintAction::Verified, Level::Rt, Level::Rt, ComplaintStatus::Submitted, ComplaintStatus::Verified, $head, $verified];
        }

        if ($status === ComplaintStatus::Forwarded) {
            $steps[] = [ComplaintAction::Forwarded, Level::Rt, Level::Rw, ComplaintStatus::Verified, ComplaintStatus::Forwarded, $head, $verified->copy()->addHour()];
        }

        if ($status === ComplaintStatus::InProgress || $status === ComplaintStatus::Resolved) {
            $steps[] = [ComplaintAction::Started, Level::Rt, Level::Rt, ComplaintStatus::Verified, ComplaintStatus::InProgress, $head, $verified->copy()->addDay()];
        }

        if ($status === ComplaintStatus::Resolved) {
            $steps[] = [ComplaintAction::Resolved, Level::Rt, Level::Rt, ComplaintStatus::InProgress, ComplaintStatus::Resolved, $head, $at->copy()->addDays(3)];
        }

        foreach ($steps as [$action, $from, $to, $fromStatus, $toStatus, $actor, $when]) {
            $complaint->steps()->create([
                'action' => $action,
                'from_level' => $from,
                'to_level' => $to,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'actor_id' => $actor->id,
                'created_at' => $when,
            ]);
        }
    }
}
