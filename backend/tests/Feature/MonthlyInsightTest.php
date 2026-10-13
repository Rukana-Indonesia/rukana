<?php

namespace Tests\Feature;

use App\Enums\ComplaintStatus;
use App\Enums\LetterFlow;
use App\Enums\LetterStatus;
use App\Enums\Level;
use App\Enums\Role;
use App\Models\AiToolCall;
use App\Models\AuditLog;
use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\LetterRequest;
use App\Models\MonthlyInsight;
use App\Models\Rt;
use App\Models\ServiceType;
use App\Models\User;
use App\Models\Village;
use App\Support\Ai\AiGateway;
use Database\Seeders\ComplaintCategorySeeder;
use Database\Seeders\RegionSeeder;
use Database\Seeders\ServiceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakeAiGateway;
use Tests\TestCase;

/** Monthly Insight (PRD 7.2, UAT 12). */
class MonthlyInsightTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiGateway $ai;

    private Rt $rt1;

    private Rt $rtRw4;

    private User $warga;

    private User $ketuaRt;

    private User $ketuaRw3;

    private User $operator;

    private User $kepalaDesa;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RegionSeeder::class, ServiceTypeSeeder::class, ComplaintCategorySeeder::class]);
        $this->withHeader('Referer', 'http://localhost:5173');
        $this->travelTo(Carbon::parse('2026-10-19 03:00', 'UTC'));

        $this->ai = new FakeAiGateway;
        $this->app->instance(AiGateway::class, $this->ai);

        [$this->rt1, , $this->rtRw4] = Rt::with('rw')->orderBy('id')->take(3)->get();
        $village = Village::firstOrFail();
        $this->warga = User::factory()->forRt($this->rt1)->create(['name' => 'Budi Pelapor']);
        $this->ketuaRt = User::factory()->forRt($this->rt1)->role(Role::Rt)->create();
        $this->ketuaRw3 = User::factory()->forRw($this->rt1->rw)->role(Role::Rw)->create();
        $this->operator = User::factory()->forVillage($village)->role(Role::Operator)->create();
        $this->kepalaDesa = User::factory()->forVillage($village)->role(Role::KepalaDesa)->create();
    }

    /** UAT 12: kepala desa membuka ringkasan; narasi tampil dengan angka yang sama dengan dasbor. */
    public function test_head_of_village_sees_narrative_with_dashboard_numbers(): void
    {
        $this->seedOctober();
        $this->ai->json($this->narrative('Bulan ini ada 3 laporan masuk dan 1 sudah selesai. Rata-rata rating 4,5 dari 5.'));

        $this->actingAs($this->operator)->postJson('/api/insights', ['month' => '2026-10'])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'done');

        $data = $this->actingAs($this->kepalaDesa)->getJson('/api/insights?month=2026-10')->assertOk()->json('data');
        $this->assertSame('Desa Sukamaju', $data['scope']['label']);
        $this->assertSame('Oktober 2026', $data['period']['label']);
        $this->assertSame('done', $data['insight']['status']);
        $this->assertStringContainsString('3 laporan masuk', $data['insight']['narrative']['ringkasan']);
        $this->assertFalse($data['insight']['stale']);
        $this->assertFalse($data['can']['generate']);

        // Angka di insight = angka dasbor untuk bulan yang sama (tanpa antrean saat ini).
        $dashboard = $this->actingAs($this->kepalaDesa)->getJson('/api/recap?from=2026-10-01&to=2026-10-31')->json('data');
        unset($dashboard['pending']);
        $this->assertEquals($dashboard, $data['metrics']);
        $this->assertSame(3, $data['metrics']['complaints']['total']);
        $this->assertEquals(4.5, $data['metrics']['ratings']['average']);

        // Yang dikirim ke AI hanya angka agregat, tanpa nama warga.
        $this->assertStringNotContainsString('Budi Pelapor', $this->ai->sentText());
        $facts = json_decode($this->ai->requests[0]['messages'][0]['content'], true);
        $this->assertSame(3, $facts['laporan']['jumlah_masuk']);
        $this->assertSame(1, $facts['laporan']['per_status']['Selesai']);

        $call = AiToolCall::where('feature', 'monthly_insight')->sole();
        $this->assertSame('ok', $call->status);
        $this->assertSame('village', $call->arguments['scope']['level']);
        $this->assertTrue(AuditLog::where('action', 'ai.invoked')->where('metadata->feature', 'monthly_insight')->exists());

        // Data bulan itu berubah setelah disusun: ditandai perlu disusun ulang.
        $this->complaint(ComplaintStatus::Submitted, '2026-10-18 02:00');
        $this->assertTrue($this->actingAs($this->kepalaDesa)->getJson('/api/insights?month=2026-10')->json('data.insight.stale'));
    }

    public function test_narrative_with_numbers_outside_the_data_is_rejected(): void
    {
        $this->seedOctober();

        // Percobaan pertama memuat angka karangan; AI diminta menulis ulang.
        $this->ai->json($this->narrative('Laporan naik 50% dibanding bulan lalu.'));
        $this->ai->json($this->narrative('Ada 3 laporan masuk di RW 03, 1 di antaranya selesai.'));

        $this->actingAs($this->ketuaRw3)->postJson('/api/insights', ['month' => '2026-10'])->assertJsonPath('data.status', 'done');
        $this->assertCount(2, $this->ai->requests);
        $this->assertStringContainsString('50', json_encode(end($this->ai->requests[1]['messages'])));

        // Dua kali memuat angka di luar data: gagal, narasi tidak ditampilkan, angka tetap ada.
        $this->ai->json($this->narrative('Laporan naik 50% dibanding bulan lalu.'));
        $this->ai->json($this->narrative('Sekitar 12 RT melapor.'));

        $this->actingAs($this->operator)->postJson('/api/insights', ['month' => '2026-10'])->assertJsonPath('data.status', 'failed');

        $data = $this->actingAs($this->operator)->getJson('/api/insights?month=2026-10')->json('data');
        $this->assertNull($data['insight']['narrative']);
        $this->assertStringContainsString('angka yang tidak ada di data', $data['insight']['error']);
        $this->assertSame(3, $data['metrics']['complaints']['total']);
    }

    public function test_access_follows_the_role_table(): void
    {
        $rw4 = $this->rtRw4->rw;

        $this->actingAs($this->ketuaRt)->getJson('/api/insights')->assertForbidden();
        $this->actingAs($this->warga)->getJson('/api/insights')->assertForbidden();
        $this->actingAs($this->kepalaDesa)->postJson('/api/insights', ['month' => '2026-10'])->assertForbidden();

        $this->actingAs($this->ketuaRw3)->getJson('/api/insights')->assertOk()->assertJsonPath('data.scope.label', 'RW 03');
        $this->actingAs($this->operator)->getJson("/api/insights?rw_id={$rw4->id}")->assertOk()->assertJsonPath('data.scope.label', 'RW 04');

        // RW 03 meminta RW 04: ditolak dan tercatat.
        $this->actingAs($this->ketuaRw3)->getJson("/api/insights?rw_id={$rw4->id}")->assertForbidden();
        $this->assertTrue(AuditLog::where('action', 'auth.access_denied')->where('user_id', $this->ketuaRw3->id)->exists());

        $this->actingAs($this->operator)->getJson('/api/insights?month=2026-11')->assertUnprocessable()->assertJsonValidationErrors('month');
    }

    public function test_without_api_key_numbers_are_still_shown(): void
    {
        $this->app->instance(AiGateway::class, new FakeAiGateway(configured: false));
        $this->seedOctober();

        $this->actingAs($this->operator)->postJson('/api/insights', ['month' => '2026-10'])->assertJsonPath('data.status', 'failed');

        $data = $this->actingAs($this->operator)->getJson('/api/insights?month=2026-10')->json('data');
        $this->assertFalse($data['ai_configured']);
        $this->assertStringContainsString('belum dikonfigurasi', $data['insight']['error']);
        $this->assertSame(3, $data['metrics']['complaints']['total']);
    }

    public function test_scheduled_command_prepares_village_and_each_rw(): void
    {
        $this->seedOctober();
        foreach (range(1, 3) as $_) {
            $this->ai->json($this->narrative('Ringkasan bulan ini.'));
        }

        $this->artisan('insights:generate', ['--month' => '2026-10'])->assertSuccessful();

        $this->assertSame(3, MonthlyInsight::where('status', 'done')->count());
        $this->assertSame(1, MonthlyInsight::whereNull('rw_id')->count());

        // Dijalankan lagi: yang sudah jadi dilewati.
        $this->artisan('insights:generate', ['--month' => '2026-10'])->assertSuccessful();
        $this->assertCount(3, $this->ai->requests);
    }

    private function narrative(string $summary): array
    {
        return ['ringkasan' => $summary, 'sorotan' => ['Laporan dan surat tercatat di Rukana.'], 'perlu_perhatian' => []];
    }

    /** RW 03: 3 laporan (1 selesai), 2 surat, 2 rating (5 dan 4). */
    private function seedOctober(): void
    {
        $service = ServiceType::where('slug', 'pengantar-domisili')->firstOrFail();
        $done = $this->letter($service, LetterStatus::Completed, '2026-10-02 08:00');
        $this->letter($service, LetterStatus::Submitted, '2026-10-05 08:00');

        $resolved = $this->complaint(ComplaintStatus::Resolved, '2026-10-05 01:00');
        $this->complaint(ComplaintStatus::Verified, '2026-10-06 01:00');
        $this->complaint(ComplaintStatus::Submitted, '2026-10-07 01:00');

        foreach ([[$done, 5], [$resolved, 4]] as [$rateable, $score]) {
            $rateable->rating()->create(['user_id' => $this->warga->id, 'score' => $score, 'village_id' => $rateable->village_id, 'rw_id' => $rateable->rw_id, 'rt_id' => $rateable->rt_id]);
        }
    }

    private function letter(ServiceType $service, LetterStatus $status, string $at): LetterRequest
    {
        return LetterRequest::create([
            'code' => sprintf('SRT-2610-%04d', ++$this->seq),
            'user_id' => $this->warga->id,
            'service_type_id' => $service->id,
            'flow' => LetterFlow::RtRwVillage,
            'village_id' => $this->warga->village_id,
            'rw_id' => $this->warga->rw_id,
            'rt_id' => $this->warga->rt_id,
            'purpose' => 'Keperluan administrasi',
            'status' => $status,
            'current_level' => $status->currentLevel(),
            'submitted_at' => Carbon::parse($at, 'UTC'),
            'completed_at' => $status === LetterStatus::Completed ? Carbon::parse($at, 'UTC')->addDay() : null,
        ]);
    }

    private function complaint(ComplaintStatus $status, string $at): Complaint
    {
        $category = ComplaintCategory::where('slug', 'sampah-menumpuk')->firstOrFail();
        $complaint = Complaint::create([
            'code' => sprintf('LPR-2610-%04d', ++$this->seq),
            'reporter_id' => $this->warga->id,
            'village_id' => $this->warga->village_id,
            'rw_id' => $this->warga->rw_id,
            'rt_id' => $this->warga->rt_id,
            'title' => 'Sampah menumpuk',
            'description' => 'Mohon segera ditangani.',
            'reported_category_id' => $category->id,
            'category_id' => $category->id,
            'handling_level' => Level::Rt,
            'status' => $status,
            'resolved_at' => $status === ComplaintStatus::Resolved ? Carbon::parse($at, 'UTC')->addDays(2) : null,
        ]);
        $complaint->forceFill(['created_at' => Carbon::parse($at, 'UTC')])->save();

        return $complaint;
    }
}
