<?php

namespace Tests\Feature;

use App\Enums\ComplaintStatus;
use App\Enums\Level;
use App\Enums\Role;
use App\Models\AiConversation;
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
use Database\Seeders\ServiceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakeAiGateway;
use Tests\TestCase;

/** Ask Rukana (PRD 7.3 + 8, UAT 11). */
class AskRukanaTest extends TestCase
{
    use RefreshDatabase;

    private const ALL_NULL = ['rw' => null, 'rt' => null, 'from' => null, 'to' => null, 'category' => null];

    private FakeAiGateway $ai;

    private Rt $rt1;

    private Rt $rt2;

    private User $wargaRt1;

    private User $wargaRt2;

    private User $ketuaRt2;

    private User $ketuaRw3;

    private User $operator;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RegionSeeder::class, ServiceTypeSeeder::class, ComplaintCategorySeeder::class]);
        $this->withHeader('Referer', 'http://localhost:5173');
        $this->travelTo(Carbon::parse('2026-10-19 03:00', 'UTC'));

        $this->ai = new FakeAiGateway;
        $this->app->instance(AiGateway::class, $this->ai);

        // RT 01 dan RT 02 di RW 03.
        [$this->rt1, $this->rt2] = Rt::with('rw')->orderBy('id')->take(2)->get();
        $this->wargaRt1 = User::factory()->forRt($this->rt1)->create(['name' => 'Budi Pelapor']);
        $this->wargaRt2 = User::factory()->forRt($this->rt2)->create(['name' => 'Siti Pelapor']);
        $this->ketuaRt2 = User::factory()->forRt($this->rt2)->role(Role::Rt)->create();
        $this->ketuaRw3 = User::factory()->forRw($this->rt1->rw)->role(Role::Rw)->create();
        $this->operator = User::factory()->forVillage(Village::firstOrFail())->role(Role::Operator)->create();

        // Oktober: RT 01 punya 3 laporan sampah, RT 02 punya 2 laporan (1 selesai).
        foreach (range(1, 3) as $_) {
            $this->complaint($this->wargaRt1, 'sampah-menumpuk', ComplaintStatus::Submitted);
        }
        $this->complaint($this->wargaRt2, 'lampu-jalan-mati', ComplaintStatus::Resolved);
        $this->complaint($this->wargaRt2, 'sampah-menumpuk', ComplaintStatus::Verified);
    }

    /** UAT 11: pengurus RT 02 bertanya data RT lain; sistem hanya menjawab untuk RT 02. */
    public function test_rt_asking_about_another_rt_only_gets_their_own_rt(): void
    {
        $this->ai
            ->tools([
                ['get_complaint_summary', [...self::ALL_NULL, 'rt' => 5]],
                ['get_complaint_summary', [...self::ALL_NULL, 'rt' => 1]],
            ])
            ->tools([['get_complaint_summary', self::ALL_NULL]])
            ->text('Saya hanya bisa menjawab untuk RT 02/RW 03. Bulan ini ada 2 laporan di RT 02, 1 sudah selesai.');

        $response = $this->actingAs($this->ketuaRt2)->postJson('/api/ask', ['question' => 'Berapa laporan di RT 05 dan RT 01 bulan ini?'])
            ->assertCreated()
            ->assertJsonPath('data.messages.1.content', 'Saya hanya bisa menjawab untuk RT 02/RW 03. Bulan ini ada 2 laporan di RT 02, 1 sudah selesai.');

        // Sistem prompt menyebut wilayah penanya; scope tidak diambil dari pertanyaan.
        $this->assertStringContainsString('Wilayah datanya: RT 02/RW 03', $this->ai->requests[0]['system']);

        // Dua permintaan wilayah lain ditolak (dalam satu giliran), tanpa angka RT lain.
        $denied = $this->toolResults(1);
        $this->assertCount(2, $denied);
        foreach ($denied as $result) {
            $this->assertTrue($result['isError']);
            $this->assertStringContainsString('di luar wilayah Anda', $result['content']);
            $this->assertStringNotContainsString('jumlah', $result['content']);
        }

        // Tanpa wilayah: scope akun (RT 02), angka sama dengan rekap dasbor RT 02.
        $own = json_decode($this->toolResults(2)[0]['content'], true);
        $this->assertSame('RT 02/RW 03', $own['wilayah']);
        $this->assertSame('Oktober 2026', $own['periode']['label']);
        $this->assertSame(2, $own['laporan']['jumlah_masuk']);
        $this->assertSame(['RT 02/RW 03'], array_column($own['per_rt'], 'wilayah'));
        $dashboard = $this->actingAs($this->ketuaRt2)->getJson('/api/recap')->json('data.complaints');
        $this->assertSame($dashboard['total'], $own['laporan']['jumlah_masuk']);
        $this->assertSame($dashboard['resolved'], $own['laporan']['selesai']);

        // Data RT 01 dan nama pelapor tidak pernah dikirim ke model.
        $this->assertStringNotContainsString('RT 01', json_encode(array_column($this->toolResults(2), 'content')));
        $this->assertStringNotContainsString('Pelapor', $this->ai->sentText());

        // Semua panggilan tercatat dengan scope yang disisipkan backend.
        $calls = AiToolCall::where('feature', 'ask')->orderBy('id')->get();
        $this->assertSame(['denied', 'denied', 'ok'], $calls->pluck('status')->all());
        $this->assertSame(5, $calls[0]->arguments['input']['rt']);
        $this->assertSame($this->rt2->id, $calls[0]->arguments['account_scope']['rt_id']);
        $this->assertSame($this->rt2->id, $calls[2]->arguments['effective_scope']['rt_id']);
        $this->assertSame(2, AuditLog::where('action', 'auth.access_denied')->where('metadata->feature', 'ask')->count());
        $this->assertTrue(AuditLog::where('action', 'ai.invoked')->where('metadata->feature', 'ask')->exists());

        // Sumber data jawaban ikut disimpan untuk ditampilkan.
        $tools = $response->json('data.messages.1.tools');
        $this->assertSame(['denied', 'denied', 'ok'], array_column($tools, 'status'));
        $this->assertSame('Data laporan', $tools[2]['label']);
        $this->assertSame('RT 02/RW 03', $tools[2]['region']);
    }

    public function test_village_account_narrows_by_rw_rt_and_category(): void
    {
        $rw = $this->rt1->rw->number;
        $this->ai
            ->tools([
                ['get_complaint_summary', [...self::ALL_NULL, 'category' => 'sampah']],
                ['get_letter_stats', ['rw' => $rw, 'rt' => 1, 'from' => '2026-09-01', 'to' => '2026-09-30']],
                ['get_village_summary', ['from' => null, 'to' => null]],
            ])
            ->text('RT 01/RW 03 paling banyak laporan sampah bulan ini, yaitu 3 laporan.');

        $this->actingAs($this->operator)->postJson('/api/ask', ['question' => 'RT mana yang paling banyak laporan sampah bulan ini?'])->assertCreated();

        [$complaints, $letters, $village] = array_map(fn ($r) => json_decode($r['content'], true), $this->toolResults(1));

        $this->assertSame('sampah', $complaints['filter_kategori']);
        $this->assertSame(4, $complaints['laporan']['jumlah_masuk']);
        $this->assertSame(['wilayah' => 'RT 01/RW 03', 'jumlah' => 3], array_intersect_key($complaints['per_rt'][0], ['wilayah' => 1, 'jumlah' => 1]));
        $this->assertSame(['Sampah Menumpuk' => 3], $complaints['per_rt'][0]['per_kategori']);

        $this->assertSame('RT 01/RW 03', $letters['wilayah']);
        $this->assertSame('September 2026', $letters['periode']['label']);

        $this->assertSame('Desa Sukamaju', $village['wilayah']);
        $this->assertSame(5, $village['laporan']['jumlah_masuk']);
        $this->assertSame(['RW 03', 'RW 04'], array_column($village['per_rw'], 'wilayah'));
        $this->assertSame(['ok', 'ok', 'ok'], AiToolCall::orderBy('id')->pluck('status')->all());
    }

    public function test_village_summary_is_only_offered_to_village_accounts(): void
    {
        $this->ai
            ->tools([['get_village_summary', ['from' => null, 'to' => null]]])
            ->text('Saya hanya bisa menjawab untuk RW 03.');

        $this->actingAs($this->ketuaRw3)->postJson('/api/ask', ['question' => 'Ringkasan seluruh desa?'])->assertCreated();

        $offered = array_column($this->ai->requests[0]['options']['tools'], 'name');
        $this->assertNotContains('get_village_summary', $offered);
        $this->assertContains('get_pending_items', $offered);
        $this->assertTrue($this->toolResults(1)[0]['isError']);
        $this->assertSame('denied', AiToolCall::sole()->status);
    }

    public function test_conversation_continues_with_history_and_is_private(): void
    {
        $this->ai->tools([['get_pending_items', ['rw' => null, 'rt' => null]]])->text('Ada 1 laporan yang belum selesai di RT 02.');
        $first = $this->actingAs($this->ketuaRt2)->postJson('/api/ask', ['question' => 'Berapa laporan yang belum selesai?'])->assertCreated();
        $id = $first->json('data.conversation.id');

        $pending = json_decode($this->toolResults(1)[0]['content'], true);
        $this->assertSame(1, $pending['antrean_saat_ini']['laporan_belum_selesai']['di_RT']);

        $this->ai->text('Yang sudah selesai ada 1.');
        $this->actingAs($this->ketuaRt2)->postJson('/api/ask', ['question' => 'Kalau yang selesai?', 'conversation_id' => $id])->assertCreated();

        // Pertanyaan lanjutan membawa tanya-jawab sebelumnya sebagai konteks.
        $sent = $this->ai->requests[2]['messages'];
        $this->assertSame(['user', 'assistant', 'user'], array_column($sent, 'role'));
        $this->assertSame('Ada 1 laporan yang belum selesai di RT 02.', $sent[1]['content']);

        $this->actingAs($this->ketuaRt2)->getJson("/api/ask/conversations/{$id}")->assertOk()->assertJsonCount(4, 'data.messages');
        $this->actingAs($this->ketuaRt2)->getJson('/api/ask/conversations')->assertOk()->assertJsonPath('data.0.id', $id);

        // Percakapan orang lain: ditolak dan tercatat, termasuk lewat conversation_id.
        $this->actingAs($this->ketuaRw3)->getJson("/api/ask/conversations/{$id}")->assertForbidden();
        $this->actingAs($this->ketuaRw3)->postJson('/api/ask', ['question' => 'Lanjut', 'conversation_id' => $id])->assertForbidden();
        $this->actingAs($this->ketuaRw3)->deleteJson("/api/ask/conversations/{$id}")->assertForbidden();
        $this->assertTrue(AuditLog::where('action', 'auth.access_denied')->where('user_id', $this->ketuaRw3->id)->exists());

        $this->actingAs($this->ketuaRt2)->deleteJson("/api/ask/conversations/{$id}")->assertOk();
        $this->assertSame(0, AiConversation::count());
    }

    public function test_residents_cannot_use_ask_rukana(): void
    {
        $this->actingAs($this->wargaRt1)->postJson('/api/ask', ['question' => 'Berapa laporan?'])->assertForbidden();
        $this->actingAs($this->wargaRt1)->getJson('/api/ask/conversations')->assertForbidden();
        $this->assertSame([], $this->ai->requests);
    }

    public function test_unavailable_ai_returns_a_clear_error_and_keeps_nothing(): void
    {
        $this->ai->fail();
        $this->actingAs($this->ketuaRt2)->postJson('/api/ask', ['question' => 'Berapa laporan bulan ini?'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'ai_unavailable');
        $this->assertSame(0, AiConversation::count());

        $this->app->instance(AiGateway::class, new FakeAiGateway(configured: false));
        $this->actingAs($this->ketuaRt2)->getJson('/api/ask/conversations')
            ->assertOk()
            ->assertJsonPath('meta.ai_configured', false)
            ->assertJsonPath('meta.scope_label', 'RT 02/RW 03');
        $this->actingAs($this->ketuaRt2)->postJson('/api/ask', ['question' => 'Berapa laporan bulan ini?'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Fitur AI belum dikonfigurasi. Isi ANTHROPIC_API_KEY di backend/.env.');

        $this->actingAs($this->ketuaRt2)->postJson('/api/ask', ['question' => ''])->assertJsonValidationErrors(['question' => 'Tulis pertanyaan Anda dulu.']);
    }

    /** Hasil tool yang dikirim balik ke model pada permintaan ke-$request. */
    private function toolResults(int $request): array
    {
        return end($this->ai->requests[$request]['messages'])['content'];
    }

    private function complaint(User $user, string $category, ComplaintStatus $status): Complaint
    {
        $category = ComplaintCategory::where('slug', $category)->firstOrFail();

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
            'resolved_at' => $status === ComplaintStatus::Resolved ? now() : null,
        ]);
    }
}
