<?php

namespace Tests\Feature;

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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ComplaintTest extends TestCase
{
    use RefreshDatabase;

    private Rt $rt1;

    private Rt $rt2;

    private User $warga;

    private User $ketuaRt1;

    private User $ketuaRt2;

    private User $ketuaRw;

    private User $ketuaRwLain;

    private User $operator;

    private User $kepalaDesa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RegionSeeder::class, ComplaintCategorySeeder::class]);
        $this->withHeader('Referer', 'http://localhost:5173');
        Storage::fake('local');

        // RT 01 dan RT 02 di RW 03; RW 04 sebagai RW lain.
        [$this->rt1, $this->rt2, $rtRwLain] = Rt::with('rw')->orderBy('id')->take(3)->get();
        $village = Village::firstOrFail();
        $this->warga = User::factory()->forRt($this->rt1)->create(['name' => 'Budi Pelapor']);
        $this->ketuaRt1 = User::factory()->forRt($this->rt1)->role(Role::Rt)->create();
        $this->ketuaRt2 = User::factory()->forRt($this->rt2)->role(Role::Rt)->create();
        $this->ketuaRw = User::factory()->forRw($this->rt1->rw)->role(Role::Rw)->create();
        $this->ketuaRwLain = User::factory()->forRw($rtRwLain->rw)->role(Role::Rw)->create();
        $this->operator = User::factory()->forVillage($village)->role(Role::Operator)->create();
        $this->kepalaDesa = User::factory()->forVillage($village)->role(Role::KepalaDesa)->create();
    }

    /** UAT 05: laporan dari HP dengan foto masuk ke dasbor (antrean) RT yang sesuai. */
    public function test_resident_reports_with_photo_and_it_reaches_their_rt(): void
    {
        $response = $this->report([
            'title' => 'Lampu jalan mati depan masjid',
            'category_id' => $this->category('lampu-jalan-mati'),
            'latitude' => -6.9147444,
            'longitude' => 107.6098111,
            'location_text' => '',
            'occurred_at' => now()->subHours(2)->toIso8601String(),
            'photos' => [UploadedFile::fake()->image('lampu.jpg', 2400, 1800)],
        ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'LPR-'.now('Asia/Jakarta')->format('ym').'-0001')
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.handling_level', 'rt')
            ->assertJsonPath('data.location.latitude', -6.9147444)
            ->assertJsonPath('data.photos.0.width', 1600)
            ->assertJsonPath('data.photos.0.height', 1200)
            ->assertJsonPath('data.reporter.name', 'Budi Pelapor');

        $complaint = Complaint::with('photos')->findOrFail($response->json('data.id'));
        $photo = $complaint->photos->first();
        $this->assertSame('image/jpeg', $photo->mime);
        Storage::disk('local')->assertExists($photo->path);
        $this->assertSame($this->rt1->id, $complaint->rt_id);

        $this->actingAs($this->ketuaRt1)->getJson('/api/complaints?inbox=1')
            ->assertOk()
            ->assertJsonPath('meta.inbox', 1)
            ->assertJsonPath('data.0.code', $complaint->code)
            ->assertJsonPath('data.0.photo_count', 1)
            ->assertJsonPath('data.0.can.actions', ['verify', 'reject']);

        $this->actingAs($this->ketuaRt2)->getJson('/api/complaints')->assertJsonCount(0, 'data');
        $this->actingAs($this->ketuaRwLain)->getJson('/api/complaints')->assertJsonCount(0, 'data');

        $this->actingAs($this->warga)->get("/api/complaints/{$complaint->id}/photos/{$photo->id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    /** UAT 06: RT meneruskan laporan jalan rusak ke desa; laporan muncul di antrean desa. */
    public function test_rt_forwards_to_village_and_village_resolves(): void
    {
        $id = $this->report(['category_id' => $this->category('jalan-rusak')])->json('data.id');

        $this->actingAs($this->ketuaRt1)->postJson("/api/complaints/{$id}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.can.forward_to.1.value', 'village');

        $this->actingAs($this->ketuaRt1)->postJson("/api/complaints/{$id}/forward", ['target' => 'village', 'note' => 'Perlu alat berat'])
            ->assertOk()
            ->assertJsonPath('data.status', 'forwarded')
            ->assertJsonPath('data.handling_level', 'village')
            ->assertJsonPath('data.can.actions', []);

        $this->actingAs($this->operator)->getJson('/api/complaints?inbox=1')
            ->assertJsonPath('meta.inbox', 1)
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.reporter.name', 'Budi Pelapor');

        // RW tidak pernah memegang laporan ini.
        $this->actingAs($this->ketuaRw)->postJson("/api/complaints/{$id}/accept")->assertForbidden();

        $this->actingAs($this->operator)->postJson("/api/complaints/{$id}/accept")->assertJsonPath('data.status', 'in_progress');
        $this->actingAs($this->operator)->postJson("/api/complaints/{$id}/resolve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['note' => 'Tulis tindak lanjut yang sudah dilakukan supaya pelapor tahu hasilnya.']);
        $this->actingAs($this->operator)->postJson("/api/complaints/{$id}/resolve", ['note' => 'Sudah ditambal Dinas PU'])
            ->assertJsonPath('data.status', 'resolved');

        $this->actingAs($this->warga)->getJson("/api/complaints/{$id}")
            ->assertJsonPath('data.status_label', 'Selesai')
            ->assertJsonPath('data.stages.2.status', 'forwarded')
            ->assertJsonCount(5, 'data.steps')
            ->assertJsonPath('data.steps.2.to_level_label', 'Desa')
            ->assertJsonPath('data.steps.4.note', 'Sudah ditambal Dinas PU')
            ->assertJsonPath('data.can.comment', false);
        $this->assertNotNull(Complaint::findOrFail($id)->resolved_at);
        $this->assertSame(4, AuditLog::where('action', 'complaint.status_changed')->where('subject_id', $id)->count());
    }

    public function test_rt_handles_complaint_itself(): void
    {
        $id = $this->report()->json('data.id');

        $this->actingAs($this->ketuaRt1)->postJson("/api/complaints/{$id}/verify");
        $this->actingAs($this->ketuaRt1)->postJson("/api/complaints/{$id}/start")
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.can.actions', ['forward', 'resolve']);
        $this->actingAs($this->ketuaRt1)->postJson("/api/complaints/{$id}/resolve", ['note' => 'Sampah sudah diangkut'])
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.stages.2.status', 'in_progress');

        $this->actingAs($this->ketuaRt1)->postJson("/api/complaints/{$id}/start")->assertStatus(409);
    }

    public function test_forwarding_only_goes_upward_and_stays_in_scope(): void
    {
        $id = $this->report()->json('data.id');
        $this->actingAs($this->ketuaRt1)->postJson("/api/complaints/{$id}/forward", ['target' => 'rw'])->assertStatus(409);

        $this->actingAs($this->ketuaRt1)->postJson("/api/complaints/{$id}/verify");
        $this->actingAs($this->ketuaRt1)->postJson("/api/complaints/{$id}/forward", ['target' => 'rt'])->assertJsonValidationErrors('target');
        $this->actingAs($this->ketuaRt1)->postJson("/api/complaints/{$id}/forward", ['target' => 'rw'])->assertJsonPath('data.handling_level', 'rw');

        // RW di RW lain tidak bisa menyentuh laporan ini.
        $this->actingAs($this->ketuaRwLain)->postJson("/api/complaints/{$id}/accept")->assertForbidden();

        $this->actingAs($this->ketuaRw)->getJson("/api/complaints/{$id}")->assertJsonPath('data.can.actions', ['accept', 'forward', 'reject']);
        $this->actingAs($this->ketuaRw)->postJson("/api/complaints/{$id}/forward", ['target' => 'village'])->assertJsonPath('data.handling_level', 'village');
        $this->actingAs($this->ketuaRw)->postJson("/api/complaints/{$id}/accept")->assertForbidden();

        $this->actingAs($this->operator)->postJson("/api/complaints/{$id}/accept");
        $this->actingAs($this->operator)->getJson("/api/complaints/{$id}")->assertJsonPath('data.can.actions', ['resolve']);
        $this->actingAs($this->operator)->postJson("/api/complaints/{$id}/forward", ['target' => 'village'])->assertStatus(409);
    }

    public function test_rejection_requires_reason_and_reporter_sees_it(): void
    {
        $id = $this->report()->json('data.id');

        $this->actingAs($this->ketuaRt1)->postJson("/api/complaints/{$id}/reject")->assertJsonValidationErrors('note');
        $this->actingAs($this->ketuaRt1)->postJson("/api/complaints/{$id}/reject", ['note' => 'Laporan ganda dengan LPR sebelumnya'])
            ->assertJsonPath('data.status', 'rejected');

        $this->actingAs($this->warga)->getJson("/api/complaints/{$id}")
            ->assertJsonPath('data.status_label', 'Ditolak')
            ->assertJsonPath('data.rejection_reason', 'Laporan ganda dengan LPR sebelumnya')
            ->assertJsonPath('data.handling_level_label', 'RT');
    }

    /** Identitas pelapor hanya untuk pengurus yang menangani (PRD 6.4). */
    public function test_reporter_identity_is_hidden_from_staff_not_handling_it(): void
    {
        $id = $this->report()->json('data.id');

        $this->actingAs($this->ketuaRt1)->getJson("/api/complaints/{$id}")
            ->assertJsonPath('data.reporter.name', 'Budi Pelapor')
            ->assertJsonPath('data.steps.0.actor.name', 'Budi Pelapor');

        foreach ([$this->ketuaRw, $this->operator, $this->kepalaDesa] as $viewer) {
            $response = $this->actingAs($viewer)->getJson("/api/complaints/{$id}")
                ->assertOk()
                ->assertJsonMissingPath('data.reporter')
                ->assertJsonPath('data.steps.0.actor.name', 'Pelapor')
                ->assertJsonPath('data.can.view_reporter', false);
            $this->assertStringNotContainsString('Budi', (string) $response->getContent());
        }

        $this->actingAs($this->ketuaRt1)->postJson("/api/complaints/{$id}/verify");
        $this->actingAs($this->ketuaRt1)->postJson("/api/complaints/{$id}/forward", ['target' => 'rw']);

        $this->actingAs($this->ketuaRw)->getJson("/api/complaints/{$id}")->assertJsonPath('data.reporter.name', 'Budi Pelapor');
        $this->actingAs($this->operator)->getJson("/api/complaints/{$id}")->assertJsonMissingPath('data.reporter');
        $this->actingAs($this->operator)->getJson('/api/complaints')->assertJsonMissingPath('data.0.reporter');
    }

    public function test_internal_notes_are_hidden_from_reporter(): void
    {
        $id = $this->report()->json('data.id');

        $this->actingAs($this->ketuaRt1)->postJson("/api/complaints/{$id}/comments", ['body' => 'Sudah kami cek ke lokasi besok pagi.'])
            ->assertCreated()
            ->assertJsonPath('message', 'Tanggapan terkirim.');
        $this->actingAs($this->ketuaRt1)->postJson("/api/complaints/{$id}/comments", ['body' => 'Pelapor sering lapor ganda', 'is_internal' => true])
            ->assertCreated();
        $this->actingAs($this->warga)->postJson("/api/complaints/{$id}/comments", ['body' => 'Terima kasih Pak RT'])->assertCreated();

        $this->actingAs($this->warga)->postJson("/api/complaints/{$id}/comments", ['body' => 'x', 'is_internal' => true])->assertForbidden();
        $this->actingAs($this->ketuaRw)->postJson("/api/complaints/{$id}/comments", ['body' => 'Ikut menanggapi'])->assertForbidden();

        $this->actingAs($this->warga)->getJson("/api/complaints/{$id}")
            ->assertJsonCount(2, 'data.comments')
            ->assertJsonPath('data.comments.0.body', 'Sudah kami cek ke lokasi besok pagi.')
            ->assertJsonPath('data.comments.1.is_mine', true)
            ->assertJsonPath('data.can.comment_internal', false);

        $this->actingAs($this->ketuaRt1)->getJson("/api/complaints/{$id}")->assertJsonCount(3, 'data.comments');

        // Kepala desa melihat tanggapan, tapi pelapor tetap anonim dan catatan internal tidak ikut.
        $this->actingAs($this->kepalaDesa)->getJson("/api/complaints/{$id}")
            ->assertJsonCount(2, 'data.comments')
            ->assertJsonPath('data.comments.1.author.name', 'Pelapor');
    }

    public function test_handler_corrects_category_and_sets_urgency(): void
    {
        $id = $this->report(['category_id' => $this->category('sampah-menumpuk')])->json('data.id');
        $road = $this->category('jalan-rusak');

        $this->actingAs($this->ketuaRw)->putJson("/api/complaints/{$id}/classification", ['category_id' => $road])->assertForbidden();
        $this->actingAs($this->warga)->putJson("/api/complaints/{$id}/classification", ['category_id' => $road])->assertForbidden();
        $this->actingAs($this->ketuaRt1)->putJson("/api/complaints/{$id}/classification", ['category_id' => $this->category('infrastruktur')])
            ->assertJsonValidationErrors(['category_id' => 'Pilih kategori laporan dari daftar.']);

        $this->actingAs($this->ketuaRt1)->putJson("/api/complaints/{$id}/classification", ['category_id' => $road, 'urgency' => 'high'])
            ->assertOk()
            ->assertJsonPath('data.category.name', 'Jalan Rusak')
            ->assertJsonPath('data.category.parent_name', 'Infrastruktur')
            ->assertJsonPath('data.reported_category.name', 'Sampah Menumpuk')
            ->assertJsonPath('data.urgency_label', 'Tinggi')
            ->assertJsonPath('data.steps.1.action', 'category_corrected')
            ->assertJsonPath('data.steps.1.note', 'Sampah Menumpuk → Jalan Rusak');

        $complaint = Complaint::findOrFail($id);
        $this->assertSame($this->category('sampah-menumpuk'), $complaint->reported_category_id);

        $this->actingAs($this->ketuaRt1)->postJson("/api/complaints/{$id}/reject", ['note' => 'Bukan laporan']);
        $this->actingAs($this->ketuaRt1)->putJson("/api/complaints/{$id}/classification", ['category_id' => $road])->assertForbidden();
    }

    /** UAT 14: data laporan di luar wilayah ditolak dan dicatat. */
    public function test_complaints_outside_scope_are_denied_and_logged(): void
    {
        $id = $this->report(['photos' => [UploadedFile::fake()->image('a.jpg')]])->json('data.id');
        $photoId = Complaint::findOrFail($id)->photos()->value('id');
        $tetangga = User::factory()->forRt($this->rt1)->create();

        $this->actingAs($this->ketuaRt2)->getJson("/api/complaints/{$id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'Anda tidak punya akses ke data ini.');
        $this->actingAs($this->ketuaRt2)->postJson("/api/complaints/{$id}/verify")->assertForbidden();
        $this->actingAs($tetangga)->getJson("/api/complaints/{$id}")->assertForbidden();
        $this->actingAs($tetangga)->get("/api/complaints/{$id}/photos/{$photoId}")->assertForbidden();
        $this->actingAs($tetangga)->postJson("/api/complaints/{$id}/comments", ['body' => 'Saya juga'])->assertForbidden();
        $this->actingAs($this->kepalaDesa)->postJson("/api/complaints/{$id}/verify")->assertForbidden();
        $this->actingAs($this->ketuaRt1)->postJson('/api/complaints', ['title' => 'x'])->assertForbidden();

        $this->assertSame(7, AuditLog::where('action', 'auth.access_denied')->count());
        $this->assertSame(0, $this->actingAs($tetangga)->getJson('/api/complaints')->json('meta.counts.submitted'));
    }

    public function test_report_requires_location_and_valid_input(): void
    {
        $this->report([
            'title' => '',
            'category_id' => $this->category('infrastruktur'),
            'location_text' => '',
            'occurred_at' => now()->addDay()->toIso8601String(),
            'photos' => array_fill(0, 4, UploadedFile::fake()->image('a.jpg')),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'title' => 'Judul wajib diisi.',
                'category_id' => 'Pilih kategori laporan dari daftar.',
                'location_text' => 'Tulis patokan lokasi, atau tekan "Gunakan lokasi saya".',
                'occurred_at' => 'Waktu kejadian tidak boleh di masa depan.',
                'photos' => 'Maksimal 3 foto per laporan.',
            ]);

        $this->report(['photos' => [UploadedFile::fake()->create('virus.pdf', 10, 'application/pdf')]])
            ->assertJsonValidationErrors(['photos.0' => 'Foto harus berupa JPG, PNG, atau WebP.']);

        $this->assertSame(0, Complaint::count());
    }

    public function test_double_submit_returns_the_same_ticket(): void
    {
        $first = $this->report(['idempotency_key' => 'abc-123'])->assertCreated()->json('data.code');
        $this->report(['idempotency_key' => 'abc-123'])->assertOk()->assertJsonPath('data.code', $first);

        $this->assertSame(1, Complaint::count());
    }

    public function test_categories_are_listed_as_a_tree(): void
    {
        $this->actingAs($this->warga)->getJson('/api/complaint-categories')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.name', 'Infrastruktur')
            ->assertJsonPath('data.0.children.0.name', 'Jalan Rusak');
    }

    private function report(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->warga)->post('/api/complaints', array_merge([
            'title' => 'Sampah menumpuk di pojok gang',
            'description' => 'Sudah tiga hari tidak diangkut dan mulai bau.',
            'category_id' => $this->category('sampah-menumpuk'),
            'location_text' => 'Gang Melati, dekat pos ronda',
        ], $overrides), ['Accept' => 'application/json']);
    }

    private function category(string $slug): int
    {
        return ComplaintCategory::where('slug', $slug)->value('id');
    }
}
