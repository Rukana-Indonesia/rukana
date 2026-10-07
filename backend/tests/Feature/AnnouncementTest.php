<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Rt;
use App\Models\User;
use App\Models\Village;
use Database\Seeders\RegionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    /** RT 01 & RT 02 di RW 03, RT 01 di RW 04. */
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RegionSeeder::class);
        $this->withHeader('Referer', 'http://localhost:5173');
        Storage::fake('local');

        [$this->rt1, $this->rt2, $this->rtRw4] = Rt::with('rw')->orderBy('id')->take(3)->get();
        $village = Village::firstOrFail();
        $this->wargaRt1 = User::factory()->forRt($this->rt1)->create();
        $this->wargaRt2 = User::factory()->forRt($this->rt2)->create();
        $this->wargaRw4 = User::factory()->forRt($this->rtRw4)->create();
        $this->ketuaRt1 = User::factory()->forRt($this->rt1)->role(Role::Rt)->create();
        $this->ketuaRw3 = User::factory()->forRw($this->rt1->rw)->role(Role::Rw)->create();
        $this->ketuaRw4 = User::factory()->forRw($this->rtRw4->rw)->role(Role::Rw)->create();
        $this->operator = User::factory()->forVillage($village)->role(Role::Operator)->create();
        $this->kepalaDesa = User::factory()->forVillage($village)->role(Role::KepalaDesa)->create();
    }

    /** UAT 09: pengumuman operator untuk RW 04 hanya terlihat (dan dikirim) ke warga RW 04. */
    public function test_operator_announcement_for_rw_04_reaches_only_rw_04(): void
    {
        User::factory()->forRt($this->rtRw4)->pending()->create();

        $id = $this->announce($this->operator, [
            'title' => 'Posyandu Balita RW 04',
            'body' => 'Sabtu 3 Oktober pukul 08.00 di Balai RW 04.',
            'target_level' => 'rw',
            'target_rw_id' => $this->rtRw4->rw_id,
            'attachments' => [UploadedFile::fake()->image('poster.jpg')],
        ])
            ->assertCreated()
            ->assertJsonPath('data.target.label', 'RW 04')
            ->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.attachments.0.is_image', true)
            ->assertJsonPath('message', 'Pengumuman diterbitkan dan dikirim ke 2 akun.')
            ->json('data.id');

        // Warga dan ketua RW 04 menerima notifikasi; akun yang belum aktif dan RW lain tidak.
        $this->assertSame(1, $this->wargaRw4->notifications()->where('type', 'announcement.published')->count());
        $this->assertSame(1, $this->ketuaRw4->notifications()->count());
        $this->assertSame(0, $this->wargaRt1->notifications()->count());
        $this->assertSame(0, $this->ketuaRw3->notifications()->count());

        $this->actingAs($this->wargaRw4)->getJson('/api/announcements')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Posyandu Balita RW 04')
            ->assertJsonPath('data.0.can.manage', false);
        $this->actingAs($this->wargaRw4)->getJson("/api/announcements/{$id}")->assertOk();
        $path = $this->actingAs($this->wargaRw4)->getJson("/api/announcements/{$id}")->json('data.attachments.0.url');
        $this->actingAs($this->wargaRw4)->get($path)->assertOk();

        // Warga RW 03 tidak melihatnya di daftar, dan ditolak bila membuka lewat URL.
        $this->actingAs($this->wargaRt1)->getJson('/api/announcements')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->wargaRt1)->getJson("/api/announcements/{$id}")->assertForbidden();
        $this->actingAs($this->wargaRt1)->get($path, ['Accept' => 'application/json'])->assertForbidden();
        $this->assertSame(2, AuditLog::where('action', 'auth.access_denied')->where('user_id', $this->wargaRt1->id)->count());

        // Kepala desa melihat semua pengumuman desa, tapi tidak bisa mengubah.
        $this->actingAs($this->kepalaDesa)->getJson("/api/announcements/{$id}")
            ->assertOk()
            ->assertJsonPath('data.can.manage', false);
    }

    public function test_rt_and_rw_targets_come_from_the_account_not_the_request(): void
    {
        $this->announce($this->ketuaRt1, [
            'title' => 'Kerja bakti',
            'body' => 'Minggu pagi kerja bakti membersihkan selokan.',
            // Diabaikan: RT hanya boleh mengumumkan untuk RT-nya sendiri.
            'target_level' => 'village',
        ])
            ->assertCreated()
            ->assertJsonPath('data.target.level', 'rt')
            ->assertJsonPath('data.target.label', 'RT 01/RW 03')
            ->assertJsonPath('data.can.manage', true);

        $this->announce($this->ketuaRw4, [
            'title' => 'Ronda malam',
            'body' => 'Jadwal ronda bulan ini sudah dibagikan.',
            'target_level' => 'rt',
            'target_rt_id' => $this->rt1->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.target.level', 'rw')
            ->assertJsonPath('data.target.label', 'RW 04');

        // Pengumuman RT 01: warga RT 01 dan ketua RW 03 melihatnya, warga RT 02 tidak.
        $this->actingAs($this->wargaRt1)->getJson('/api/announcements')->assertJsonCount(1, 'data');
        $this->actingAs($this->ketuaRw3)->getJson('/api/announcements')->assertJsonCount(1, 'data');
        $this->actingAs($this->wargaRt2)->getJson('/api/announcements')->assertJsonCount(0, 'data');
        $this->actingAs($this->operator)->getJson('/api/announcements')->assertJsonCount(2, 'data');
    }

    public function test_only_officials_at_the_target_level_or_the_operator_can_change_an_announcement(): void
    {
        $id = $this->announce($this->ketuaRt1, ['title' => 'Arisan', 'body' => 'Arisan RT di rumah Bu RT.'])->json('data.id');

        // RW induk bisa melihat, tapi tidak mengubah pengumuman RT.
        $this->actingAs($this->ketuaRw3)->getJson("/api/announcements/{$id}")->assertOk()->assertJsonPath('data.can.manage', false);
        $this->actingAs($this->ketuaRw3)->putJson("/api/announcements/{$id}", ['title' => 'Diubah', 'body' => 'x'])->assertForbidden();
        $this->actingAs($this->kepalaDesa)->postJson('/api/announcements', ['title' => 'x', 'body' => 'y'])->assertForbidden();
        $this->actingAs($this->wargaRt1)->deleteJson("/api/announcements/{$id}")->assertForbidden();

        $this->actingAs($this->ketuaRt1)->putJson("/api/announcements/{$id}", ['title' => 'Arisan bulan ini', 'body' => 'Pindah ke balai RT.'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Arisan bulan ini');

        // Operator memoderasi: boleh menghapus. Notifikasinya ikut dihapus.
        $this->assertSame(1, $this->wargaRt1->notifications()->count());
        $this->actingAs($this->operator)->deleteJson("/api/announcements/{$id}")->assertOk();
        $this->assertSoftDeleted('announcements', ['id' => $id]);
        $this->assertSame(0, $this->wargaRt1->notifications()->count());
        $this->actingAs($this->wargaRt1)->getJson("/api/announcements/{$id}")->assertNotFound();
    }

    public function test_scheduled_announcement_is_hidden_until_published_then_notified_once(): void
    {
        $id = $this->announce($this->operator, [
            'title' => 'Pemadaman listrik',
            'body' => 'Pemadaman bergilir hari Kamis.',
            'target_level' => 'village',
            'published_at' => now()->addHours(2)->toIso8601String(),
            'expires_at' => now()->addDays(3)->toIso8601String(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.state', 'scheduled')
            ->assertJsonPath('message', 'Pengumuman dijadwalkan. Warga diberi tahu saat pengumuman terbit.')
            ->json('data.id');

        $this->actingAs($this->wargaRt1)->getJson('/api/announcements')->assertJsonCount(0, 'data');
        $this->actingAs($this->wargaRt1)->getJson("/api/announcements/{$id}")->assertForbidden();
        $this->actingAs($this->operator)->getJson('/api/announcements?manage=1&state=scheduled')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.counts.scheduled', 1);
        $this->artisan('announcements:notify')->assertSuccessful();
        $this->assertSame(0, $this->wargaRt1->notifications()->count());

        $this->travel(3)->hours();
        $this->artisan('announcements:notify')->assertSuccessful();
        $this->artisan('announcements:notify')->assertSuccessful();

        $this->assertSame(1, $this->wargaRt1->notifications()->count());
        $this->assertSame(1, $this->wargaRw4->notifications()->count());
        $this->assertSame(0, $this->operator->notifications()->count());
        $this->actingAs($this->wargaRt1)->getJson('/api/announcements')->assertJsonCount(1, 'data');

        // Setelah kedaluwarsa tidak tampil lagi bagi warga.
        $this->travel(4)->days();
        $this->actingAs($this->wargaRt1)->getJson('/api/announcements')->assertJsonCount(0, 'data');
        $this->actingAs($this->operator)->getJson('/api/announcements?manage=1&state=expired')->assertJsonCount(1, 'data');
    }

    public function test_validation_and_attachment_limits(): void
    {
        $this->announce($this->operator, [
            'title' => '',
            'body' => '',
            'target_level' => 'rt',
            'expires_at' => now()->subDay()->toIso8601String(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'body', 'target_rt_id', 'expires_at'])
            ->assertJsonPath('errors.body.0', 'Isi pengumuman wajib diisi.');

        $id = $this->announce($this->ketuaRt1, [
            'title' => 'Lomba 17-an',
            'body' => 'Pendaftaran lomba dibuka.',
            'attachments' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->create('jadwal.pdf', 100, 'application/pdf')],
        ])->assertCreated()->json('data.id');

        $attachments = Announcement::findOrFail($id)->attachments;
        $this->actingAs($this->ketuaRt1)->post("/api/announcements/{$id}", [
            '_method' => 'PUT',
            'title' => 'Lomba 17-an',
            'body' => 'Pendaftaran lomba dibuka.',
            'attachments' => [UploadedFile::fake()->image('b.jpg'), UploadedFile::fake()->image('c.jpg')],
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.attachments.0', 'Maksimal 3 lampiran per pengumuman.');

        $this->actingAs($this->ketuaRt1)->post("/api/announcements/{$id}", [
            '_method' => 'PUT',
            'title' => 'Lomba 17-an',
            'body' => 'Pendaftaran lomba dibuka.',
            'remove_attachments' => [$attachments[0]->id],
            'attachments' => [UploadedFile::fake()->image('b.jpg'), UploadedFile::fake()->image('c.jpg')],
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonCount(3, 'data.attachments');
        Storage::disk('local')->assertMissing($attachments[0]->path);
    }

    private function announce(User $as, array $data): TestResponse
    {
        return $this->actingAs($as)->post('/api/announcements', $data, ['Accept' => 'application/json']);
    }
}
