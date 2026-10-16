<?php

namespace Tests\Feature;

use App\Enums\ComplaintStatus;
use App\Enums\Level;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\Rt;
use App\Models\User;
use App\Models\Village;
use Database\Seeders\ComplaintCategorySeeder;
use Database\Seeders\RegionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Peta sebaran laporan (PRD 6.9): scope wilayah, filter, dan titik berulang. */
class ComplaintMapTest extends TestCase
{
    use RefreshDatabase;

    /** RW 03: RT 01, RT 02. RW 04: RT 01. */
    private Rt $rt1;

    private Rt $rt2;

    private Rt $rtRw4;

    private User $wargaRt1;

    private User $wargaRt2;

    private User $wargaRw4;

    private User $ketuaRt1;

    private User $ketuaRw3;

    private User $operator;

    private User $kepalaDesa;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RegionSeeder::class, ComplaintCategorySeeder::class]);
        $this->withHeader('Referer', 'http://localhost:5173');

        [$this->rt1, $this->rt2, $this->rtRw4] = Rt::with('rw')->orderBy('id')->take(3)->get();
        $village = Village::firstOrFail();
        $this->wargaRt1 = User::factory()->forRt($this->rt1)->create(['name' => 'Budi Pelapor']);
        $this->wargaRt2 = User::factory()->forRt($this->rt2)->create();
        $this->wargaRw4 = User::factory()->forRt($this->rtRw4)->create();
        $this->ketuaRt1 = User::factory()->forRt($this->rt1)->role(Role::Rt)->create();
        $this->ketuaRw3 = User::factory()->forRw($this->rt1->rw)->role(Role::Rw)->create();
        $this->operator = User::factory()->forVillage($village)->role(Role::Operator)->create();
        $this->kepalaDesa = User::factory()->forVillage($village)->role(Role::KepalaDesa)->create();
    }

    public function test_village_map_shows_located_complaints_of_the_period_without_personal_data(): void
    {
        $this->seedOctober();

        $data = $this->actingAs($this->kepalaDesa)->getJson('/api/complaint-map?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->json('data');

        $this->assertSame(['level' => 'village', 'label' => 'Desa Sukamaju'], $data['scope']);
        $this->assertSame('Oktober 2026', $data['period']['label']);
        // 5 laporan masuk Oktober (sama dengan rekap dasbor), 4 di antaranya punya titik.
        $this->assertSame(5, $data['total']);
        $this->assertSame(4, $data['with_location']);
        $this->assertFalse($data['truncated']);
        $this->assertSame(['LPR-2610-0005', 'LPR-2610-0004', 'LPR-2610-0003', 'LPR-2610-0001'], array_column($data['points'], 'code'));

        $point = collect($data['points'])->firstWhere('code', 'LPR-2610-0001');
        $this->assertSame(-6.9147444, $point['latitude']);
        $this->assertSame('resolved', $point['status']);
        $this->assertSame('Selesai', $point['status_label']);
        $this->assertFalse($point['is_active']);
        $this->assertSame(['id' => $this->category('lampu-jalan-mati')->id, 'name' => 'Lampu Jalan Mati', 'parent' => 'Infrastruktur'], $point['category']);
        $this->assertSame('RT 01/RW 03', $point['region']);

        $this->assertSame(['RW 03', 'RW 04'], array_column($data['regions']['rws'], 'label'));
        $this->assertCount(4, $data['regions']['rts']);
        $this->assertSame(config('app.map_center'), $data['center']);

        // Tanpa identitas pelapor dan patokan lokasi.
        $json = (string) json_encode($data);
        $this->assertStringNotContainsString('Budi Pelapor', $json);
        $this->assertStringNotContainsString('Depan rumah', $json);
        $this->assertStringNotContainsString($this->wargaRt1->phone, $json);
    }

    public function test_rt_and_rw_only_see_their_own_region(): void
    {
        $this->seedOctober();
        $query = '/api/complaint-map?from=2026-10-01&to=2026-10-31';

        $rt = $this->actingAs($this->ketuaRt1)->getJson($query)->assertOk()->json('data');
        $this->assertSame(['level' => 'rt', 'label' => 'RT 01/RW 03'], $rt['scope']);
        $this->assertSame(['LPR-2610-0004', 'LPR-2610-0001'], array_column($rt['points'], 'code'));
        $this->assertSame(['rws' => [], 'rts' => []], $rt['regions']);

        $rw = $this->actingAs($this->ketuaRw3)->getJson($query)->assertOk()->json('data');
        $this->assertSame('rw', $rw['scope']['level']);
        $this->assertSame(['LPR-2610-0004', 'LPR-2610-0003', 'LPR-2610-0001'], array_column($rw['points'], 'code'));
        $this->assertSame([], $rw['regions']['rws']);
        $this->assertSame(['RT 01', 'RT 02'], array_column($rw['regions']['rts'], 'label'));

        // Operator mempersempit ke RW 04.
        $narrowed = $this->actingAs($this->operator)->getJson("{$query}&rw_id={$this->rtRw4->rw_id}")->assertOk()->json('data');
        $this->assertSame('RW 04', $narrowed['scope']['label']);
        $this->assertSame(['LPR-2610-0005'], array_column($narrowed['points'], 'code'));
        // Pilihan filter tetap seluruh scope akun.
        $this->assertCount(2, $narrowed['regions']['rws']);
    }

    public function test_filters_by_category_and_status(): void
    {
        $this->seedOctober();
        $query = '/api/complaint-map?from=2026-10-01&to=2026-10-31';
        $codes = fn (string $filter) => array_column($this->actingAs($this->operator)->getJson($query.$filter)->assertOk()->json('data.points'), 'code');

        // Kategori induk mencakup subkategorinya.
        $infrastruktur = ComplaintCategory::where('slug', 'infrastruktur')->value('id');
        $this->assertSame(['LPR-2610-0005', 'LPR-2610-0004', 'LPR-2610-0001'], $codes("&category_id={$infrastruktur}"));
        $this->assertSame(['LPR-2610-0003'], $codes('&category_id='.$this->category('sampah-menumpuk')->id));

        $this->assertSame(['LPR-2610-0005', 'LPR-2610-0004', 'LPR-2610-0003'], $codes('&status=active'));
        $this->assertSame(['LPR-2610-0001'], $codes('&status=resolved'));

        $this->actingAs($this->operator)->getJson($query.'&status=hilang')
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'Status yang dipilih tidak valid.');
    }

    public function test_nearby_complaints_form_a_recurring_hotspot(): void
    {
        $this->seedOctober();

        $hotspots = $this->actingAs($this->operator)->getJson('/api/complaint-map?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->json('data.hotspots');

        // LPR-0001 dan LPR-0004 berjarak ±20 m; titik lain berjauhan.
        $this->assertCount(1, $hotspots);
        $this->assertSame(2, $hotspots[0]['total']);
        $this->assertSame(1, $hotspots[0]['active']);
        $this->assertSame('Lampu Jalan Mati', $hotspots[0]['top_category']);
        $this->assertSame(['RT 01/RW 03'], $hotspots[0]['regions']);
        $this->assertEqualsCanonicalizing(
            Complaint::whereIn('code', ['LPR-2610-0001', 'LPR-2610-0004'])->pluck('id')->all(),
            $hotspots[0]['ids'],
        );
    }

    public function test_region_filter_cannot_widen_the_account_scope(): void
    {
        $before = AuditLog::where('action', 'auth.access_denied')->count();

        // RW 03 meminta RT di RW 04; RT meminta RW-nya sendiri; RT yang tidak ada.
        $this->actingAs($this->ketuaRw3)->getJson("/api/complaint-map?rt_id={$this->rtRw4->id}")->assertForbidden();
        $this->actingAs($this->ketuaRt1)->getJson("/api/complaint-map?rw_id={$this->rt1->rw_id}")->assertForbidden();
        $this->actingAs($this->operator)->getJson('/api/complaint-map?rt_id=99999')->assertForbidden();
        // Warga tidak punya peta sebaran.
        $this->actingAs($this->wargaRt1)->getJson('/api/complaint-map')->assertForbidden();

        // Keempat penolakan tercatat di audit log (UAT 14).
        $this->assertSame($before + 4, AuditLog::where('action', 'auth.access_denied')->count());
    }

    /**
     * Oktober 2026: 5 laporan (4 bertitik). LPR-0001 dan LPR-0004 berdekatan di RT 01/RW 03.
     * Satu laporan September (bertitik) tidak ikut.
     */
    private function seedOctober(): void
    {
        $lampu = $this->category('lampu-jalan-mati');
        $this->complaint($this->wargaRt1, $lampu, ComplaintStatus::Resolved, '2026-10-02 03:00', [-6.9147444, 107.6098111]);
        $this->complaint($this->wargaRt2, $this->category('gangguan-keamanan'), ComplaintStatus::Submitted, '2026-10-03 03:00');
        $this->complaint($this->wargaRt2, $this->category('sampah-menumpuk'), ComplaintStatus::Verified, '2026-10-05 03:00', [-6.9182, 107.6131]);
        $this->complaint($this->wargaRt1, $lampu, ComplaintStatus::InProgress, '2026-10-08 03:00', [-6.9148901, 107.6097502]);
        $this->complaint($this->wargaRw4, $this->category('jalan-rusak'), ComplaintStatus::Forwarded, '2026-10-10 03:00', [-6.9231, 107.6045]);
        $this->complaint($this->wargaRt1, $lampu, ComplaintStatus::Submitted, '2026-09-20 03:00', [-6.9147, 107.6098]);
        $this->travelBack();
    }

    /** @param  array{float, float}|null  $point */
    private function complaint(User $user, ComplaintCategory $category, ComplaintStatus $status, string $createdAt, ?array $point = null): Complaint
    {
        $this->travelTo(Carbon::parse($createdAt, 'UTC'));

        return Complaint::create([
            'code' => sprintf('LPR-2610-%04d', ++$this->seq),
            'reporter_id' => $user->id,
            'village_id' => $user->village_id,
            'rw_id' => $user->rw_id,
            'rt_id' => $user->rt_id,
            'title' => 'Laporan warga',
            'description' => 'Mohon segera ditangani.',
            'reported_category_id' => $category->id,
            'category_id' => $category->id,
            'handling_level' => Level::Rt,
            'status' => $status,
            'latitude' => $point[0] ?? null,
            'longitude' => $point[1] ?? null,
            'location_text' => 'Depan rumah warga',
        ]);
    }

    private function category(string $slug): ComplaintCategory
    {
        return ComplaintCategory::where('slug', $slug)->firstOrFail();
    }
}
