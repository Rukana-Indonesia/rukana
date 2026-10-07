<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ComplaintCategory;
use App\Models\Rt;
use App\Models\ServiceType;
use App\Models\User;
use App\Models\Village;
use Database\Seeders\ComplaintCategorySeeder;
use Database\Seeders\RegionSeeder;
use Database\Seeders\ServiceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private Rt $rt1;

    private User $warga;

    private User $ketuaRt1;

    private User $ketuaRt2;

    private User $ketuaRw;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RegionSeeder::class, ServiceTypeSeeder::class, ComplaintCategorySeeder::class]);
        $this->withHeader('Referer', 'http://localhost:5173');
        Storage::fake('local');

        [$this->rt1, $rt2] = Rt::with('rw')->orderBy('id')->take(2)->get();
        $this->warga = User::factory()->forRt($this->rt1)->create();
        $this->ketuaRt1 = User::factory()->forRt($this->rt1)->role(Role::Rt)->create();
        $this->ketuaRt2 = User::factory()->forRt($rt2)->role(Role::Rt)->create();
        $this->ketuaRw = User::factory()->forRw($this->rt1->rw)->role(Role::Rw)->create();
        $this->operator = User::factory()->forVillage(Village::firstOrFail())->role(Role::Operator)->create();
    }

    public function test_letter_applicant_hears_every_step_and_each_level_hears_new_work(): void
    {
        $id = $this->actingAs($this->warga)->post('/api/letters', [
            'service_type_id' => ServiceType::where('slug', 'pengantar-domisili')->value('id'),
            'purpose' => 'Mengurus rekening bank',
            'nik' => '3204123456780001',
            'form_data' => ['lama_tinggal' => '5 tahun'],
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $this->assertSame(['letter.queue'], $this->kinds($this->ketuaRt1));
        $this->assertSame([], $this->kinds($this->ketuaRt2));

        foreach ([[$this->ketuaRt1, 'verify'], [$this->ketuaRw, 'approve'], [$this->operator, 'receive'], [$this->operator, 'ready'], [$this->operator, 'pickup']] as [$actor, $action]) {
            $this->actingAs($actor)->postJson("/api/letters/{$id}/{$action}")->assertOk();
        }

        $this->assertEqualsCanonicalizing(
            ['letter.rt_verified', 'letter.rw_approved', 'letter.village_received', 'letter.ready_for_pickup', 'letter.completed'],
            $this->kinds($this->warga),
        );
        $this->assertSame(['letter.queue'], $this->kinds($this->ketuaRw));
        // Operator diberi tahu sekali saat surat masuk desa, bukan di setiap langkahnya sendiri.
        $this->assertSame(['letter.queue'], $this->kinds($this->operator));

        $ready = $this->warga->notifications()->where('type', 'letter.ready_for_pickup')->firstOrFail();
        $this->assertSame('Surat siap diambil', $ready->data['title']);
        $this->assertSame("/app/surat/{$id}", $ready->data['url']);
        $this->assertStringNotContainsString('3204', json_encode($this->warga->notifications->pluck('data')));
    }

    /** UAT 06: RT meneruskan laporan ke desa; pelapor menerima notifikasi, desa mendapat pekerjaan baru. */
    public function test_forwarding_a_complaint_notifies_the_reporter_and_the_village(): void
    {
        $id = $this->actingAs($this->warga)->post('/api/complaints', [
            'title' => 'Jalan berlubang dekat jembatan',
            'description' => 'Sudah dua motor jatuh.',
            'category_id' => ComplaintCategory::where('slug', 'jalan-rusak')->value('id'),
            'location_text' => 'Jembatan Cikapundung',
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $this->assertSame(['complaint.queue'], $this->kinds($this->ketuaRt1));

        $this->actingAs($this->ketuaRt1)->postJson("/api/complaints/{$id}/verify")->assertOk();
        $this->actingAs($this->ketuaRt1)->postJson("/api/complaints/{$id}/forward", ['target' => 'village'])->assertOk();

        $this->assertEqualsCanonicalizing(['complaint.verify', 'complaint.forward'], $this->kinds($this->warga));
        $forwarded = $this->warga->notifications()->where('type', 'complaint.forward')->firstOrFail();
        $this->assertSame('Laporan diteruskan ke Desa', $forwarded->data['title']);
        $this->assertSame(['complaint.queue'], $this->kinds($this->operator));
        $this->assertSame([], $this->kinds($this->ketuaRw));
        // Notifikasi ke pengurus tidak membawa nama pelapor.
        $this->assertStringNotContainsString($this->warga->name, json_encode($this->operator->notifications->pluck('data')));
    }

    public function test_rt_hears_of_new_registrants_and_the_resident_hears_of_verification(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Warga Baru',
            'phone' => '081299990001',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
            'rw_id' => $this->rt1->rw_id,
            'rt_id' => $this->rt1->id,
            'residency_status' => 'tetap',
        ])->assertCreated();
        // Sesi pendaftaran masih menyimpan login warga baru; mulai sesi bersih untuk akun RT.
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->assertSame(['resident.registered'], $this->kinds($this->ketuaRt1));
        $this->assertSame([], $this->kinds($this->ketuaRt2));

        $resident = User::where('phone', '6281299990001')->firstOrFail();
        $this->actingAs($this->ketuaRt1)->postJson("/api/residents/{$resident->id}/verify")->assertOk();
        $this->assertSame(['account.verified'], $this->kinds($resident));
    }

    public function test_bell_lists_and_marks_only_the_users_own_notifications(): void
    {
        $this->actingAs($this->warga)->post('/api/complaints', [
            'title' => 'Lampu mati',
            'description' => 'Gelap sekali.',
            'category_id' => ComplaintCategory::where('slug', 'lampu-jalan-mati')->value('id'),
            'location_text' => 'Gang Mawar',
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->actingAs($this->warga)->post('/api/complaints', [
            'title' => 'Sampah',
            'description' => 'Menumpuk.',
            'category_id' => ComplaintCategory::where('slug', 'sampah-menumpuk')->value('id'),
            'location_text' => 'Gang Mawar',
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->actingAs($this->ketuaRt1)->getJson('/api/notifications/unread-count')->assertOk()->assertJsonPath('data.unread', 2);
        $list = $this->actingAs($this->ketuaRt1)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.unread', 2)
            ->assertJsonPath('data.0.title', 'Laporan baru dari warga');
        // Terbaru di atas, walau keduanya dibuat dalam detik yang sama.
        $this->assertStringContainsString('"Sampah"', $list->json('data.0.body'));
        $first = $list->json('data.0.id');

        // Notifikasi milik RT 01 tidak bisa ditandai oleh akun lain.
        $this->actingAs($this->ketuaRt2)->postJson("/api/notifications/{$first}/read")->assertNotFound();
        $this->actingAs($this->ketuaRt1)->getJson('/api/notifications/unread-count')->assertJsonPath('data.unread', 2);

        $this->actingAs($this->ketuaRt1)->postJson("/api/notifications/{$first}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $first);
        $this->actingAs($this->ketuaRt1)->getJson('/api/notifications?unread=1')->assertJsonCount(1, 'data');
        $this->actingAs($this->ketuaRt1)->postJson('/api/notifications/read-all')->assertOk();
        $this->actingAs($this->ketuaRt1)->getJson('/api/notifications/unread-count')->assertJsonPath('data.unread', 0);
    }

    /** Jenis notifikasi akun ini. */
    private function kinds(User $user): array
    {
        return $user->notifications()->pluck('type')->all();
    }
}
