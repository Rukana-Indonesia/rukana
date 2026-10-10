<?php

namespace Tests\Feature;

use App\Enums\ComplaintStatus;
use App\Enums\LetterFlow;
use App\Enums\LetterStatus;
use App\Enums\Level;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\LetterRequest;
use App\Models\Rt;
use App\Models\ServiceType;
use App\Models\User;
use App\Models\Village;
use Database\Seeders\ComplaintCategorySeeder;
use Database\Seeders\RegionSeeder;
use Database\Seeders\ServiceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class RecapTest extends TestCase
{
    use RefreshDatabase;

    /** RW 03: RT 01, RT 02. RW 04: RT 01, RT 02. */
    private Rt $rt1;

    private Rt $rt2;

    private Rt $rtRw4;

    private User $wargaRt1;

    private User $wargaRt2;

    private User $wargaRw4;

    private User $ketuaRt1;

    private User $ketuaRw3;

    private User $ketuaRw4;

    private User $operator;

    private User $kepalaDesa;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RegionSeeder::class, ServiceTypeSeeder::class, ComplaintCategorySeeder::class]);
        $this->withHeader('Referer', 'http://localhost:5173');

        [$this->rt1, $this->rt2, $this->rtRw4] = Rt::with('rw')->orderBy('id')->take(3)->get();
        $village = Village::firstOrFail();
        $this->wargaRt1 = User::factory()->forRt($this->rt1)->create(['name' => 'Budi Pelapor']);
        $this->wargaRt2 = User::factory()->forRt($this->rt2)->create();
        $this->wargaRw4 = User::factory()->forRt($this->rtRw4)->create();
        $this->ketuaRt1 = User::factory()->forRt($this->rt1)->role(Role::Rt)->create();
        $this->ketuaRw3 = User::factory()->forRw($this->rt1->rw)->role(Role::Rw)->create();
        $this->ketuaRw4 = User::factory()->forRw($this->rtRw4->rw)->role(Role::Rw)->create();
        $this->operator = User::factory()->forVillage($village)->role(Role::Operator)->create();
        $this->kepalaDesa = User::factory()->forVillage($village)->role(Role::KepalaDesa)->create();
        User::factory()->forRt($this->rt1)->pending()->create();
    }

    public function test_village_recap_counts_tickets_entered_in_the_period(): void
    {
        $this->seedOctober();

        $data = $this->actingAs($this->operator)->getJson('/api/recap?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->json('data');

        $this->assertSame(['level' => 'village', 'label' => 'Desa Sukamaju'], $data['scope']);
        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-31', 'label' => 'Oktober 2026'], $data['period']);

        // Surat September tidak ikut; rata-rata proses (2,5 + 1,5) / 2 = 2,0 hari.
        $this->assertSame(['total' => 4, 'completed' => 2, 'rejected' => 1, 'active' => 1], array_intersect_key($data['letters'], array_flip(['total', 'completed', 'rejected', 'active'])));
        $this->assertEquals(2.0, $data['letters']['avg_process_days']);
        $this->assertSame(['name' => 'Surat Pengantar Keterangan Domisili', 'total' => 3], $data['letters']['top_services'][0]);
        $this->assertSame(1, $data['letters']['by_status']['rw_approved']);

        $this->assertSame(3, $data['complaints']['total']);
        $this->assertSame(1, $data['complaints']['resolved']);
        $this->assertSame(2, $data['complaints']['active']);
        $this->assertSame(2, $data['complaints']['forwarded']);
        $this->assertSame(1, $data['complaints']['escalated_village']['total']);
        $this->assertSame(1, $data['complaints']['escalated_village']['by_status']['in_progress']);
        $this->assertEquals(3.0, $data['complaints']['avg_resolve_days']);
        $this->assertSame(['name' => 'Lampu Jalan Mati', 'total' => 2], $data['complaints']['top_categories'][0]);

        $this->assertSame(['total' => 2, 'average' => 4.5], $data['ratings']);

        // Antrean saat ini, termasuk tiket di luar periode yang belum selesai.
        $this->assertSame(1, $data['pending']['residents']);
        $this->assertSame(['rt' => 0, 'rw' => 0, 'village' => 2], $data['pending']['letters']);
        $this->assertSame(0, $data['pending']['letters_ready']);
        $this->assertSame(['rt' => 0, 'rw' => 1, 'village' => 1], $data['pending']['complaints']);

        // Rincian: semua RW dan RT, termasuk yang tanpa aktivitas.
        $this->assertSame(['RW 03', 'RW 04'], array_column($data['rws'], 'label'));
        $this->assertSame(3, $data['rws'][0]['letters']['total']);
        $this->assertSame(1, $data['rws'][1]['letters']['total']);
        $this->assertCount(4, $data['rts']);
        $this->assertSame('RT 01', $data['rts'][0]['label']);
        $this->assertSame('RW 03', $data['rts'][0]['rw']['label']);
        $this->assertSame(['name' => 'Lampu Jalan Mati', 'total' => 2], $data['rts'][0]['top_category']);
        $this->assertSame(0, $data['rts'][3]['complaints']['total']);
        $this->assertNull($data['rts'][3]['top_category']);
    }

    public function test_rt_and_rw_only_get_their_own_region(): void
    {
        $this->seedOctober();
        $query = '/api/recap?from=2026-10-01&to=2026-10-31';

        $rt = $this->actingAs($this->ketuaRt1)->getJson($query)->assertOk()->json('data');
        $this->assertSame('RT 01/RW 03', $rt['scope']['label']);
        $this->assertSame(2, $rt['letters']['total']);
        $this->assertSame(2, $rt['complaints']['total']);
        $this->assertSame([], $rt['rws']);
        $this->assertSame([$this->rt1->id], array_column($rt['rts'], 'id'));

        $rw = $this->actingAs($this->ketuaRw4)->getJson($query)->assertOk()->json('data');
        $this->assertSame('RW 04', $rw['scope']['label']);
        $this->assertSame(1, $rw['letters']['total']);
        $this->assertSame(0, $rw['complaints']['total']);
        $this->assertSame(['total' => 0, 'average' => null], $rw['ratings']);
        $this->assertSame(['RT 01', 'RT 02'], array_column($rw['rts'], 'label'));
        $this->assertSame(['RW 04'], array_unique(array_column(array_column($rw['rts'], 'rw'), 'label')));
    }

    /** UAT 14: filter hanya mempersempit; wilayah di luar scope ditolak dan dicatat. */
    public function test_region_filters_only_narrow_the_account_scope(): void
    {
        $this->seedOctober();
        $query = '/api/recap?from=2026-10-01&to=2026-10-31';

        $this->actingAs($this->operator)->getJson("{$query}&rw_id={$this->rtRw4->rw_id}")
            ->assertOk()
            ->assertJsonPath('data.scope.label', 'RW 04')
            ->assertJsonPath('data.letters.total', 1);
        $this->actingAs($this->ketuaRw3)->getJson("{$query}&rt_id={$this->rt2->id}")
            ->assertOk()
            ->assertJsonPath('data.scope.label', 'RT 02/RW 03')
            ->assertJsonPath('data.letters.total', 1);

        $before = AuditLog::where('action', 'auth.access_denied')->count();
        foreach ([
            [$this->ketuaRw3, "rt_id={$this->rtRw4->id}"],
            [$this->ketuaRw3, "rw_id={$this->rtRw4->rw_id}"],
            [$this->ketuaRt1, "rt_id={$this->rt2->id}"],
            [$this->ketuaRt1, "rw_id={$this->rt1->rw_id}"],
            [$this->operator, "rw_id={$this->rt1->rw_id}&rt_id={$this->rtRw4->id}"],
            [$this->operator, 'rt_id=99999'],
        ] as [$user, $filter]) {
            $this->actingAs($user)->getJson("{$query}&{$filter}")
                ->assertForbidden()
                ->assertJsonPath('message', 'Anda tidak punya akses ke data wilayah ini.');
        }
        $this->assertSame($before + 6, AuditLog::where('action', 'auth.access_denied')->count());
    }

    public function test_recap_and_export_follow_the_role_table(): void
    {
        $this->actingAs($this->wargaRt1)->getJson('/api/recap')->assertForbidden();
        $this->actingAs($this->kepalaDesa)->getJson('/api/recap')->assertOk();

        // Ekspor rekap hanya RW dan desa (PRD 9).
        $this->actingAs($this->kepalaDesa)->get('/api/recap/export?format=xlsx')->assertForbidden();
        $this->actingAs($this->ketuaRt1)->get('/api/recap/export?format=xlsx')->assertForbidden();
        $this->actingAs($this->wargaRt1)->get('/api/recap/export?format=pdf')->assertForbidden();
        $this->actingAs($this->ketuaRw3)->get('/api/recap/export?format=pdf')->assertOk();
    }

    public function test_period_defaults_to_the_current_month_and_is_validated(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 10:00', 'Asia/Jakarta'));

        $this->actingAs($this->operator)->getJson('/api/recap')
            ->assertOk()
            ->assertJsonPath('data.period', ['from' => '2026-10-01', 'to' => '2026-10-31', 'label' => 'Oktober 2026']);
        $this->actingAs($this->operator)->getJson('/api/recap?from=2026-10-05&to=2026-10-12')
            ->assertJsonPath('data.period.label', '5 Okt – 12 Okt 2026');

        $this->actingAs($this->operator)->getJson('/api/recap?from=2026-10-12&to=2026-10-05')
            ->assertUnprocessable()
            ->assertJsonPath('errors.to.0', 'Tanggal akhir tidak boleh sebelum tanggal awal.');
        $this->actingAs($this->operator)->getJson('/api/recap?from=2025-01-01&to=2026-10-05')
            ->assertUnprocessable()
            ->assertJsonPath('errors.to.0', 'Rentang rekap paling panjang 1 tahun.');
        $this->actingAs($this->operator)->getJson('/api/recap/export?format=docx')
            ->assertUnprocessable()
            ->assertJsonPath('errors.format.0', 'Format berkas yang dipilih tidak valid.');
    }

    /** UAT 13: operator mengekspor rekap satu bulan; berkas berisi rekap sesuai filter. */
    public function test_operator_exports_recap_to_excel(): void
    {
        $this->seedOctober();

        $response = $this->actingAs($this->operator)
            ->get('/api/recap/export?format=xlsx&from=2026-10-01&to=2026-10-31&rw_id='.$this->rt1->rw_id)
            ->assertOk()
            ->assertDownload('rekap-rukana-rw-03-oktober-2026.xlsx');

        $sheets = $this->readXlsx($response->baseResponse->getFile()->getPathname());
        $this->assertSame(['Ringkasan', 'Per Wilayah', 'Surat', 'Laporan'], array_keys($sheets));

        $this->assertSame('Rekap Layanan Rukana — RW 03', $sheets['Ringkasan'][0][0]);
        $this->assertSame(['Periode', 'Oktober 2026'], $sheets['Ringkasan'][1]);
        $this->assertContains(['Surat masuk', 3], $sheets['Ringkasan']);
        $this->assertContains(['Laporan masuk', 3], $sheets['Ringkasan']);
        $this->assertContains(['Eskalasi ke desa', 1], $sheets['Ringkasan']);

        // Per wilayah: RW 03 saja (sesuai filter), RT 01 dan RT 02.
        $this->assertSame([['RW 03', 'RT 01'], ['RW 03', 'RT 02']], array_map(fn ($r) => array_slice($r, 0, 2), array_slice($sheets['Per Wilayah'], 1)));

        $letters = array_slice($sheets['Surat'], 1);
        $this->assertCount(3, $letters);
        $this->assertSame('Surat Pengantar Keterangan Domisili', $letters[0][3]);
        $this->assertSame('Selesai', $letters[0][7]);
        $this->assertInstanceOf(\DateTimeInterface::class, $letters[0][2]);
        $this->assertSame('2026-10-02 15:00', $letters[0][2]->format('Y-m-d H:i'), 'Tanggal dalam WIB');
        $this->assertEquals(2.5, $letters[0][10]);

        // Daftar laporan tanpa identitas pelapor.
        $complaints = array_slice($sheets['Laporan'], 1);
        $this->assertCount(3, $complaints);
        $this->assertStringNotContainsString('Budi Pelapor', json_encode($sheets['Laporan']));
        $this->assertSame('Lampu jalan mati di gang 3', $complaints[0][4]);

        $log = AuditLog::where('action', 'export.downloaded')->sole();
        $this->assertSame($this->operator->id, $log->user_id);
        $this->assertSame('xlsx', $log->metadata['format']);
        $this->assertSame('rw', $log->metadata['scope']['level']);
        $this->assertSame('2026-10-01', $log->metadata['period']['from']);
    }

    public function test_rw_exports_recap_to_pdf(): void
    {
        $this->seedOctober();

        $response = $this->actingAs($this->ketuaRw3)
            ->get('/api/recap/export?format=pdf&from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertDownload('rekap-rukana-rw-03-oktober-2026.pdf');

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertSame(1, AuditLog::where('action', 'export.downloaded')->where('user_id', $this->ketuaRw3->id)->count());
    }

    /**
     * Data Oktober 2026 (WIB):
     * - surat: 3 di RW 03 (2 selesai 2,5 & 1,5 hari, 1 ditolak), 1 di RW 04 menunggu desa,
     *   ditambah 1 surat September yang masih menunggu desa (di luar periode);
     * - laporan: 2 lampu mati di RT 01 (1 selesai 3 hari, 1 diteruskan ke RW),
     *   1 jalan rusak di RT 02 yang dieskalasi ke desa;
     * - rating: 5 dan 4.
     */
    private function seedOctober(): void
    {
        $domisili = ServiceType::where('slug', 'pengantar-domisili')->firstOrFail();
        $skck = ServiceType::where('slug', 'pengantar-skck')->firstOrFail();
        $lampu = ComplaintCategory::where('slug', 'lampu-jalan-mati')->firstOrFail();
        $jalan = ComplaintCategory::where('slug', 'jalan-rusak')->firstOrFail();

        $done = $this->letter($this->wargaRt1, $domisili, LetterStatus::Completed, '2026-10-02 08:00', '2026-10-04 20:00');
        $this->letter($this->wargaRt2, $domisili, LetterStatus::Completed, '2026-10-03 08:00', '2026-10-04 20:00');
        $this->letter($this->wargaRt1, $skck, LetterStatus::Rejected, '2026-10-10 08:00');
        $this->letter($this->wargaRw4, $domisili, LetterStatus::RwApproved, '2026-10-12 08:00');
        $this->letter($this->wargaRt1, $domisili, LetterStatus::VillageReceived, '2026-09-20 08:00');

        $resolved = $this->complaint($this->wargaRt1, $lampu, ComplaintStatus::Resolved, Level::Rt, '2026-10-05 01:00', '2026-10-08 01:00', 'Lampu jalan mati di gang 3');
        $this->complaint($this->wargaRt1, $lampu, ComplaintStatus::Forwarded, Level::Rw, '2026-10-06 01:00');
        $this->complaint($this->wargaRt2, $jalan, ComplaintStatus::InProgress, Level::Village, '2026-10-07 01:00');

        $this->rate($done, $this->wargaRt1, 5, '2026-10-05 09:00');
        $this->rate($resolved, $this->wargaRt1, 4, '2026-10-09 09:00');
        $this->travelBack();
    }

    private function letter(User $user, ServiceType $service, LetterStatus $status, string $submittedAt, ?string $completedAt = null): LetterRequest
    {
        $this->travelTo(Carbon::parse($submittedAt, 'UTC'));

        return LetterRequest::create([
            'code' => sprintf('SRT-2610-%04d', ++$this->seq),
            'user_id' => $user->id,
            'service_type_id' => $service->id,
            'flow' => LetterFlow::RtRwVillage,
            'village_id' => $user->village_id,
            'rw_id' => $user->rw_id,
            'rt_id' => $user->rt_id,
            'purpose' => 'Keperluan administrasi',
            'status' => $status,
            'current_level' => $status->currentLevel(),
            'rejected_level' => $status === LetterStatus::Rejected ? Level::Rt : null,
            'rejection_reason' => $status === LetterStatus::Rejected ? 'Berkas belum lengkap' : null,
            'submitted_at' => now(),
            'completed_at' => $completedAt ? Carbon::parse($completedAt, 'UTC') : null,
        ]);
    }

    private function complaint(User $user, ComplaintCategory $category, ComplaintStatus $status, Level $level, string $createdAt, ?string $resolvedAt = null, string $title = 'Laporan warga'): Complaint
    {
        $this->travelTo(Carbon::parse($createdAt, 'UTC'));

        return Complaint::create([
            'code' => sprintf('LPR-2610-%04d', ++$this->seq),
            'reporter_id' => $user->id,
            'village_id' => $user->village_id,
            'rw_id' => $user->rw_id,
            'rt_id' => $user->rt_id,
            'title' => $title,
            'description' => 'Mohon segera ditangani.',
            'reported_category_id' => $category->id,
            'category_id' => $category->id,
            'handling_level' => $level,
            'status' => $status,
            'resolved_at' => $resolvedAt ? Carbon::parse($resolvedAt, 'UTC') : null,
        ]);
    }

    private function rate(LetterRequest|Complaint $rateable, User $user, int $score, string $at): void
    {
        $this->travelTo(Carbon::parse($at, 'UTC'));
        $rateable->rating()->create([
            'user_id' => $user->id,
            'score' => $score,
            'village_id' => $rateable->village_id,
            'rw_id' => $rateable->rw_id,
            'rt_id' => $rateable->rt_id,
        ]);
    }

    /** @return array<string, list<list<mixed>>> nilai sel per lembar */
    private function readXlsx(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                /** @var Row $row */
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }
        $reader->close();

        return $sheets;
    }
}
