<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AiToolCall;
use App\Models\AuditLog;
use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\Rt;
use App\Models\User;
use App\Models\Village;
use App\Support\Ai\AiGateway;
use Database\Seeders\ComplaintCategorySeeder;
use Database\Seeders\RegionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAiGateway;
use Tests\TestCase;

/** Smart Complaint (PRD 7.1, UAT 07). */
class SmartComplaintTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiGateway $ai;

    private User $warga;

    private User $ketuaRt;

    private User $ketuaRtLain;

    private User $operator;

    private User $kepalaDesa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RegionSeeder::class, ComplaintCategorySeeder::class]);
        $this->withHeader('Referer', 'http://localhost:5173');
        Storage::fake('local');

        $this->ai = new FakeAiGateway;
        $this->app->instance(AiGateway::class, $this->ai);

        [$rt1, $rt2] = Rt::with('rw')->orderBy('id')->take(2)->get();
        $village = Village::firstOrFail();
        $this->warga = User::factory()->forRt($rt1)->create(['name' => 'Budi Pelapor', 'phone' => '081299990001']);
        $this->ketuaRt = User::factory()->forRt($rt1)->role(Role::Rt)->create();
        $this->ketuaRtLain = User::factory()->forRt($rt2)->role(Role::Rt)->create();
        $this->operator = User::factory()->forVillage($village)->role(Role::Operator)->create();
        $this->kepalaDesa = User::factory()->forVillage($village)->role(Role::KepalaDesa)->create();
    }

    /** UAT 07: laporan baru diproses AI; kategori, urgensi, saran level, dan ringkasan muncul untuk pengurus. */
    public function test_new_complaint_gets_ai_suggestion_for_staff(): void
    {
        $jalan = $this->category('jalan-rusak');
        $this->ai->json([
            'category_id' => $jalan,
            'urgency' => 'high',
            'level' => 'village',
            'summary' => 'Jalan utama dekat jembatan berlubang besar dan sudah menyebabkan dua motor jatuh.',
        ]);

        $id = $this->report()->assertCreated()->json('data.id');

        $label = $this->actingAs($this->ketuaRt)->getJson("/api/complaints/{$id}")->assertOk()->json('data.ai_label');
        $this->assertSame('done', $label['status']);
        $this->assertSame($jalan, $label['category']['id']);
        $this->assertSame('Infrastruktur', $label['category']['parent_name']);
        $this->assertSame('Tinggi', $label['urgency_label']);
        $this->assertSame('Desa', $label['level_label']);
        $this->assertStringContainsString('berlubang', $label['summary']);

        // AI hanya menyarankan: kategori, tingkat, dan status laporan tidak berubah.
        $complaint = Complaint::findOrFail($id);
        $this->assertSame($this->category('sampah-menumpuk'), $complaint->category_id);
        $this->assertSame('rt', $complaint->handling_level->value);
        $this->assertSame('submitted', $complaint->status->value);
        $this->assertNull($complaint->urgency);

        // Kepala desa ikut melihat saran; pelapor tidak.
        $this->assertSame('done', $this->actingAs($this->kepalaDesa)->getJson("/api/complaints/{$id}")->json('data.ai_label.status'));
        $this->assertArrayNotHasKey('ai_label', $this->actingAs($this->warga)->getJson("/api/complaints/{$id}")->json('data'));

        // Tercatat di ai_tool_calls dan audit log, dengan scope wilayah laporan.
        $call = AiToolCall::where('feature', 'smart_complaint')->sole();
        $this->assertSame('classify_complaint', $call->tool_name);
        $this->assertSame('ok', $call->status);
        $this->assertSame($complaint->rt_id, $call->arguments['scope']['rt_id']);
        $this->assertSame(['category_id' => $jalan, 'urgency' => 'high', 'level' => 'village'], $call->result);
        $this->assertTrue(AuditLog::where('action', 'ai.invoked')->where('subject_id', $id)->exists());
    }

    public function test_reporter_identity_and_location_are_not_sent_to_the_model(): void
    {
        $this->ai->json(['category_id' => $this->category('sampah-menumpuk'), 'urgency' => 'medium', 'level' => 'rt', 'summary' => 'Sampah menumpuk di pojok gang.']);

        $this->report(['location_text' => 'Rumah Pak Darto No. 12, Gang Melati'])->assertCreated();

        $sent = $this->ai->sentText();
        $this->assertStringContainsString('Sampah menumpuk di pojok gang', $sent);
        $this->assertStringContainsString('RT 01/RW 03', $sent);
        $this->assertStringNotContainsString('Budi Pelapor', $sent);
        $this->assertStringNotContainsString('081299990001', $sent);
        $this->assertStringNotContainsString('Pak Darto', $sent);
        $this->assertSame('low', $this->ai->requests[0]['options']['effort']);
    }

    public function test_failure_is_shown_and_handler_can_retry(): void
    {
        $this->app->instance(AiGateway::class, new FakeAiGateway(configured: false));

        $id = $this->report()->assertCreated()->json('data.id');

        $data = $this->actingAs($this->ketuaRt)->getJson("/api/complaints/{$id}")->json('data');
        $this->assertSame('failed', $data['ai_label']['status']);
        $this->assertStringContainsString('belum dikonfigurasi', $data['ai_label']['error']);
        $this->assertTrue($data['can']['retry_ai']);
        $this->assertSame('error', AiToolCall::sole()->status);

        // RT lain dan kepala desa tidak bisa meminta ulang.
        $this->actingAs($this->ketuaRtLain)->postJson("/api/complaints/{$id}/ai-label/retry")->assertForbidden();
        $this->actingAs($this->kepalaDesa)->postJson("/api/complaints/{$id}/ai-label/retry")->assertForbidden();

        $this->app->instance(AiGateway::class, $this->ai);
        $this->ai->json(['category_id' => $this->category('sampah-menumpuk'), 'urgency' => 'medium', 'level' => 'rt', 'summary' => 'Sampah menumpuk di pojok gang.']);

        $this->actingAs($this->ketuaRt)->postJson("/api/complaints/{$id}/ai-label/retry")
            ->assertOk()
            ->assertJsonPath('data.ai_label.status', 'done')
            ->assertJsonPath('data.can.retry_ai', false);

        // Saran yang sudah jadi tidak bisa ditimpa (dipakai evaluasi akurasi).
        $this->actingAs($this->ketuaRt)->postJson("/api/complaints/{$id}/ai-label/retry")->assertForbidden();
    }

    public function test_output_outside_the_category_list_is_rejected(): void
    {
        $this->ai->json(['category_id' => 9999, 'urgency' => 'high', 'level' => 'village', 'summary' => 'Ringkasan.']);

        $id = $this->report()->assertCreated()->json('data.id');

        $label = $this->actingAs($this->ketuaRt)->getJson("/api/complaints/{$id}")->json('data.ai_label');
        $this->assertSame('failed', $label['status']);
        $this->assertNull($label['category']);
    }

    /** Koreksi pengurus dicatat untuk evaluasi akurasi (target ≥ 80%). */
    public function test_staff_decisions_are_recorded_for_accuracy(): void
    {
        $sampah = $this->category('sampah-menumpuk');
        $saluran = $this->category('saluran-air-mampet');
        $this->ai->json(['category_id' => $sampah, 'urgency' => 'medium', 'level' => 'rt', 'summary' => 'Sampah menumpuk.']);
        $this->ai->json(['category_id' => $sampah, 'urgency' => 'low', 'level' => 'rw', 'summary' => 'Saluran mampet.']);

        $first = $this->report()->json('data.id');
        $second = $this->report(['title' => 'Got mampet', 'idempotency_key' => 'kedua'])->json('data.id');

        // Laporan pertama: pengurus memakai saran AI, lalu menyelesaikannya di RT.
        $this->actingAs($this->ketuaRt)->postJson("/api/complaints/{$first}/verify")->assertOk();
        $this->actingAs($this->ketuaRt)->putJson("/api/complaints/{$first}/classification", ['category_id' => $sampah, 'urgency' => 'medium'])->assertOk();
        $this->actingAs($this->ketuaRt)->postJson("/api/complaints/{$first}/start")->assertOk();
        $this->actingAs($this->ketuaRt)->postJson("/api/complaints/{$first}/resolve", ['note' => 'Sudah diangkut.'])->assertOk();

        // Laporan kedua: pengurus mengoreksi kategori dan urgensi.
        $this->actingAs($this->ketuaRt)->postJson("/api/complaints/{$second}/verify")->assertOk();
        $this->actingAs($this->ketuaRt)->putJson("/api/complaints/{$second}/classification", ['category_id' => $saluran, 'urgency' => 'high'])->assertOk();

        $label = Complaint::findOrFail($first)->aiLabel;
        $this->assertSame($sampah, $label->final_category_id);
        $this->assertSame('rt', $label->final_level->value);
        $this->assertSame($this->ketuaRt->id, $label->reviewed_by);

        $accuracy = $this->actingAs($this->operator)->getJson('/api/insights')->assertOk()->json('data.smart_complaint');
        $this->assertSame(2, $accuracy['reviewed']);
        $this->assertEquals(50.0, $accuracy['category']);
        $this->assertEquals(50.0, $accuracy['urgency']);
        $this->assertEquals(100.0, $accuracy['level']);
    }

    private function report(array $overrides = [])
    {
        return $this->actingAs($this->warga)->postJson('/api/complaints', array_merge([
            'title' => 'Sampah menumpuk di pojok gang',
            'description' => 'Sudah tiga hari tidak diangkut dan mulai bau.',
            'category_id' => $this->category('sampah-menumpuk'),
            'location_text' => 'Gang Melati, dekat pos ronda',
        ], $overrides));
    }

    private function category(string $slug): int
    {
        return ComplaintCategory::where('slug', $slug)->value('id');
    }
}
