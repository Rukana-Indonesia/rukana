<?php

namespace Tests\Feature;

use App\Enums\LetterStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\LetterRequest;
use App\Models\Rt;
use App\Models\ServiceType;
use App\Models\User;
use App\Models\Village;
use Database\Seeders\RegionSeeder;
use Database\Seeders\ServiceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LetterTest extends TestCase
{
    use RefreshDatabase;

    private Rt $rt1;

    private Rt $rt2;

    private User $warga;

    private User $ketuaRt1;

    private User $ketuaRt2;

    private User $ketuaRw;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RegionSeeder::class, ServiceTypeSeeder::class]);
        $this->withHeader('Referer', 'http://localhost:5173');
        Storage::fake('local');

        // RT 01 dan RT 02 di RW 03.
        [$this->rt1, $this->rt2] = Rt::with('rw')->orderBy('id')->take(2)->get();
        $this->warga = User::factory()->forRt($this->rt1)->create();
        $this->ketuaRt1 = User::factory()->forRt($this->rt1)->role(Role::Rt)->create();
        $this->ketuaRt2 = User::factory()->forRt($this->rt2)->role(Role::Rt)->create();
        $this->ketuaRw = User::factory()->forRw($this->rt1->rw)->role(Role::Rw)->create();
        $this->operator = User::factory()->forVillage(Village::firstOrFail())->role(Role::Operator)->create();
    }

    /** UAT 03: dapat kode tiket, surat melewati RT → RW → desa, status terlihat di tiap tahap. */
    public function test_domicile_letter_goes_through_rt_rw_and_village(): void
    {
        $id = $this->submit('pengantar-domisili', [
            'nik' => '3204-1234-5678-0001',
            'form_data' => ['lama_tinggal' => '5 tahun', 'tidak_ada_di_skema' => 'dibuang'],
            'attachments' => [['file' => UploadedFile::fake()->image('ktp.jpg'), 'label' => 'KTP']],
        ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'SRT-'.now('Asia/Jakarta')->format('ym').'-0001')
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.current_level', 'rt')
            ->assertJsonPath('data.nik_masked', '3204********0001')
            ->assertJsonPath('data.form_data', [['key' => 'lama_tinggal', 'label' => 'Lama tinggal di alamat ini', 'value' => '5 tahun']])
            ->assertJsonPath('data.attachments.0.label', 'KTP')
            ->assertJsonMissingPath('data.nik')
            ->json('data.id');

        $letter = LetterRequest::findOrFail($id);
        $this->assertSame('3204123456780001', $letter->nik_encrypted);
        $this->assertNotSame('3204123456780001', $letter->getRawOriginal('nik_encrypted'));
        Storage::disk('local')->assertExists($letter->attachments()->value('path'));

        $steps = [
            [$this->ketuaRt1, 'verify', 'rt_verified', 'rw'],
            [$this->ketuaRw, 'approve', 'rw_approved', 'village'],
            [$this->operator, 'receive', 'village_received', 'village'],
            [$this->operator, 'ready', 'ready_for_pickup', 'village'],
            [$this->operator, 'pickup', 'completed', null],
        ];
        foreach ($steps as [$actor, $action, $status, $level]) {
            $this->actingAs($actor)->postJson("/api/letters/{$id}/{$action}")
                ->assertOk()
                ->assertJsonPath('data.status', $status)
                ->assertJsonPath('data.current_level', $level);

            $this->actingAs($this->warga)->getJson("/api/letters/{$id}")->assertJsonPath('data.status', $status);
        }

        $this->actingAs($this->warga)->getJson("/api/letters/{$id}")
            ->assertJsonCount(6, 'data.steps')
            ->assertJsonPath('data.steps.1.actor.name', $this->ketuaRt1->name)
            ->assertJsonPath('data.can.actions', []);
        $this->assertNotNull($letter->fresh()->completed_at);
        $this->assertSame(5, AuditLog::where('action', 'letter.status_changed')->where('subject_id', $id)->count());
    }

    public function test_rt_only_flow_completes_when_rt_verifies(): void
    {
        $id = $this->submit('pengantar-rt')->json('data.id');

        $this->actingAs($this->ketuaRt1)->postJson("/api/letters/{$id}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.current_level', null);
    }

    public function test_rt_rw_flow_completes_when_rw_approves(): void
    {
        $id = $this->submit('izin-keramaian', ['form_data' => [
            'nama_acara' => 'Hajatan', 'tanggal_acara' => '2026-11-01', 'tempat_acara' => 'Rumah', 'jumlah_tamu' => 100,
        ]])->assertCreated()->json('data.id');

        $this->actingAs($this->ketuaRt1)->postJson("/api/letters/{$id}/verify")->assertJsonPath('data.status', 'rt_verified');
        $this->actingAs($this->ketuaRw)->postJson("/api/letters/{$id}/approve")->assertJsonPath('data.status', 'completed');
        $this->actingAs($this->operator)->postJson("/api/letters/{$id}/receive")->assertStatus(409);
    }

    /** UAT 04: RT menolak dengan alasan, warga melihat status Ditolak beserta alasannya. */
    public function test_rt_rejects_with_reason_and_resident_sees_it(): void
    {
        $id = $this->submit('pengantar-rt')->json('data.id');

        $this->actingAs($this->ketuaRt1)->postJson("/api/letters/{$id}/reject")
            ->assertUnprocessable()
            ->assertJsonPath('errors.note.0', 'Tulis alasan penolakan supaya warga tahu apa yang perlu diperbaiki.');

        $this->actingAs($this->ketuaRt1)->postJson("/api/letters/{$id}/reject", ['note' => 'Foto KTP buram'])->assertOk();

        $this->actingAs($this->warga)->getJson("/api/letters/{$id}")
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.status_label', 'Ditolak')
            ->assertJsonPath('data.rejected_level_label', 'RT')
            ->assertJsonPath('data.rejection_reason', 'Foto KTP buram')
            ->assertJsonPath('data.can.print', false);

        $this->actingAs($this->ketuaRt1)->postJson("/api/letters/{$id}/verify")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Surat ini sudah berstatus Ditolak.');
    }

    public function test_submission_is_validated_against_service_schema(): void
    {
        $errors = $this->submit('pengantar-domisili', ['purpose' => ''])->assertUnprocessable()->json('errors');
        $this->assertSame('Keperluan wajib diisi.', $errors['purpose'][0]);
        $this->assertSame('NIK wajib diisi.', $errors['nik'][0]);
        $this->assertSame('Lama tinggal di alamat ini wajib diisi.', $errors['form_data.lama_tinggal'][0]);

        $this->submit('pengantar-domisili', ['nik' => '123', 'form_data' => ['lama_tinggal' => '1 tahun']])
            ->assertJsonPath('errors.nik.0', 'NIK harus 16 digit angka sesuai KTP.');

        $errors = $this->submit('pengantar-rt', ['attachments' => [['file' => UploadedFile::fake()->create('virus.exe', 10)]]])->json('errors');
        $this->assertSame('Lampiran harus berupa foto (JPG/PNG/WebP) atau PDF.', $errors['attachments.0.file'][0]);

        ServiceType::where('slug', 'pengantar-rt')->update(['is_active' => false]);
        $this->submit('pengantar-rt')->assertJsonPath('errors.service_type_id.0', 'Layanan ini tidak ditemukan atau sedang tidak dibuka.');

        $this->assertSame(0, LetterRequest::count());
    }

    public function test_double_submit_with_same_key_returns_same_ticket(): void
    {
        $first = $this->submit('pengantar-rt', ['idempotency_key' => 'abc-123'])->assertCreated()->json('data.code');
        $second = $this->submit('pengantar-rt', ['idempotency_key' => 'abc-123'])->assertOk()->json('data.code');

        $this->assertSame($first, $second);
        $this->assertSame(1, LetterRequest::count());
    }

    public function test_only_residents_submit_letters(): void
    {
        $this->actingAs($this->ketuaRt1)->postJson('/api/letters', [
            'service_type_id' => ServiceType::where('slug', 'pengantar-rt')->value('id'),
            'purpose' => 'Tes',
        ])->assertForbidden();
    }

    public function test_lists_are_limited_to_scope_with_inbox_counts(): void
    {
        $own = $this->submit('pengantar-rt')->json('data.id');
        $other = User::factory()->forRt($this->rt2)->create();
        $this->submit('pengantar-rt', [], $other);

        $this->actingAs($this->ketuaRt1)->getJson('/api/letters?inbox=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own)
            ->assertJsonPath('data.0.can.actions', ['verify', 'reject'])
            ->assertJsonPath('meta.inbox', 1);

        $this->actingAs($this->ketuaRw)->getJson('/api/letters')->assertJsonCount(2, 'data')->assertJsonPath('meta.inbox', 0);
        $this->actingAs($this->operator)->getJson('/api/letters?rt_id='.$this->rt2->id)->assertJsonCount(1, 'data');
        $this->actingAs($this->warga)->getJson('/api/letters')->assertJsonCount(1, 'data')->assertJsonPath('meta.counts.submitted', 1);
    }

    /** UAT 14: akses surat di luar wilayah ditolak backend dan dicatat. */
    public function test_cross_scope_access_is_denied_and_audited(): void
    {
        $id = $this->submit('pengantar-domisili', ['nik' => '3204123456780001', 'form_data' => ['lama_tinggal' => '2 tahun']])->json('data.id');
        $neighbour = User::factory()->forRt($this->rt1)->create();
        $rw4 = User::factory()->forRw(Rt::with('rw')->orderByDesc('id')->firstOrFail()->rw)->role(Role::Rw)->create();

        $this->actingAs($this->ketuaRt2)->getJson("/api/letters/{$id}")->assertForbidden();
        $this->actingAs($this->ketuaRt2)->postJson("/api/letters/{$id}/verify")->assertForbidden();
        $this->actingAs($neighbour)->getJson("/api/letters/{$id}")->assertForbidden();
        $this->actingAs($neighbour)->getJson("/api/letters/{$id}/pdf")->assertForbidden();

        // Tingkat yang salah tidak bisa melompati RT.
        $this->actingAs($this->ketuaRw)->postJson("/api/letters/{$id}/verify")->assertForbidden();
        $this->actingAs($this->operator)->postJson("/api/letters/{$id}/verify")->assertForbidden();

        $this->actingAs($this->ketuaRt1)->postJson("/api/letters/{$id}/verify")->assertOk();
        $this->actingAs($rw4)->postJson("/api/letters/{$id}/approve")->assertForbidden();
        $this->actingAs($this->ketuaRt1)->postJson("/api/letters/{$id}/approve")->assertForbidden();

        $this->assertSame(LetterStatus::RtVerified, LetterRequest::find($id)->status);
        $this->assertSame(2, AuditLog::where('action', 'auth.access_denied')->where('user_id', $neighbour->id)->count());
        $this->assertTrue(AuditLog::where('action', 'auth.access_denied')->where('user_id', $this->ketuaRt2->id)->exists());
    }

    public function test_attachments_are_private_to_the_letter_scope(): void
    {
        $letter = $this->submit('pengantar-rt', [
            'attachments' => [['file' => UploadedFile::fake()->create('ktp.pdf', 20, 'application/pdf')]],
        ])->json('data');
        $url = $letter['attachments'][0]['url'];

        $this->actingAs($this->ketuaRt1)->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->ketuaRt2)->getJson($url)->assertForbidden();

        // Lampiran surat lain tidak bisa dibuka lewat surat sendiri.
        $mine = $this->submit('pengantar-rt', [], User::factory()->forRt($this->rt2)->create())->json('data.id');
        $this->actingAs($this->ketuaRt2)->getJson("/api/letters/{$mine}/attachments/{$letter['attachments'][0]['id']}")->assertNotFound();
    }

    public function test_pdf_is_available_after_rt_verification(): void
    {
        $id = $this->submit('pengantar-domisili', ['nik' => '3204123456780001', 'form_data' => ['lama_tinggal' => '2 tahun']])->json('data.id');

        $this->actingAs($this->warga)->get("/api/letters/{$id}/pdf")->assertForbidden();

        $this->actingAs($this->ketuaRt1)->postJson("/api/letters/{$id}/verify");

        $this->actingAs($this->warga)->get("/api/letters/{$id}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertTrue(AuditLog::where('action', 'letter.printed')->where('subject_id', $id)->exists());
    }

    public function test_full_nik_is_only_revealed_to_operator_and_audited(): void
    {
        $id = $this->submit('pengantar-domisili', ['nik' => '3204123456780001', 'form_data' => ['lama_tinggal' => '2 tahun']])->json('data.id');

        $this->actingAs($this->ketuaRt1)->getJson("/api/letters/{$id}/nik")->assertForbidden();
        $this->actingAs($this->warga)->getJson("/api/letters/{$id}/nik")->assertForbidden();

        $this->actingAs($this->operator)->getJson("/api/letters/{$id}")->assertJsonPath('data.can.view_nik', true);
        $this->actingAs($this->operator)->getJson("/api/letters/{$id}/nik")
            ->assertOk()
            ->assertJsonPath('data.nik', '3204123456780001');
        $this->assertTrue(AuditLog::where('action', 'letter.nik_viewed')->where('user_id', $this->operator->id)->exists());
    }

    public function test_guest_download_gets_401_instead_of_login_redirect(): void
    {
        $id = $this->submit('pengantar-rt')->json('data.id');
        auth()->guard('web')->logout();
        $this->app['auth']->forgetGuards();

        // Tanpa header Accept JSON (mis. tautan dibuka langsung di tab baru).
        $this->get("/api/letters/{$id}/pdf")->assertUnauthorized();
    }

    private function submit(string $slug, array $data = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->warga)->post('/api/letters', [
            'service_type_id' => ServiceType::where('slug', $slug)->value('id'),
            'purpose' => 'Untuk keperluan administrasi',
            ...$data,
        ], ['Accept' => 'application/json']);
    }
}
