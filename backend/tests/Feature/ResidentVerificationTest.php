<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Rt;
use App\Models\User;
use App\Models\Village;
use Database\Seeders\RegionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ResidentVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Rt $rt1;

    private Rt $rt2;

    private User $ketuaRt1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RegionSeeder::class);
        $this->withHeader('Referer', 'http://localhost:5173');

        [$this->rt1, $this->rt2] = Rt::with('rw')->orderBy('id')->take(2)->get();
        $this->ketuaRt1 = User::factory()->forRt($this->rt1)->role(Role::Rt)->create();
    }

    public function test_rt_lists_only_residents_of_own_rt(): void
    {
        $own = User::factory()->forRt($this->rt1)->pending()->create();
        User::factory()->forRt($this->rt1)->create();
        User::factory()->forRt($this->rt2)->pending()->create();

        $this->actingAs($this->ketuaRt1)->getJson('/api/residents?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonPath('meta.counts.pending', 1)
            ->assertJsonPath('meta.counts.active', 1);
    }

    public function test_operator_lists_residents_of_whole_village(): void
    {
        User::factory()->forRt($this->rt1)->pending()->create();
        User::factory()->forRt($this->rt2)->pending()->create();
        $operator = User::factory()->forVillage(Village::firstOrFail())->role(Role::Operator)->create();

        $this->actingAs($operator)->getJson('/api/residents?status=pending')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_search_matches_local_phone_format(): void
    {
        User::factory()->forRt($this->rt1)->create(['phone' => '6281299998888']);
        User::factory()->forRt($this->rt1)->create(['phone' => '6281277776666']);

        $this->actingAs($this->ketuaRt1)->getJson('/api/residents?search=0812-9999')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.phone', '6281299998888');
    }

    /** UAT 01: setelah ketua RT memverifikasi, akun aktif. */
    public function test_rt_verifies_pending_resident_of_own_rt(): void
    {
        $warga = User::factory()->forRt($this->rt1)->pending()->create();

        $this->actingAs($this->ketuaRt1)->postJson("/api/residents/{$warga->id}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.verifier.id', $this->ketuaRt1->id);

        $warga->refresh();
        $this->assertSame(UserStatus::Active, $warga->status);
        $this->assertNotNull($warga->verified_at);
        $this->assertTrue(AuditLog::where('action', 'user.verified')->where('subject_id', $warga->id)->exists());
    }

    /** UAT 14: akses lintas wilayah ditolak backend dan tercatat di audit log. */
    public function test_rt_cannot_verify_resident_of_another_rt(): void
    {
        $warga = User::factory()->forRt($this->rt2)->pending()->create();

        $this->actingAs($this->ketuaRt1)->postJson("/api/residents/{$warga->id}/verify")
            ->assertForbidden()
            ->assertExactJson(['message' => 'Anda tidak punya akses ke data ini.']);
        $this->actingAs($this->ketuaRt1)->getJson("/api/residents/{$warga->id}")->assertForbidden();

        $this->assertSame(UserStatus::Pending, $warga->fresh()->status);
        $this->assertSame(2, AuditLog::where('action', 'auth.access_denied')->where('user_id', $this->ketuaRt1->id)->count());
    }

    public function test_warga_and_rw_cannot_open_resident_data(): void
    {
        $other = User::factory()->forRt($this->rt1)->create();
        $warga = User::factory()->forRt($this->rt1)->create();
        $rw = User::factory()->forRw($this->rt1->rw)->role(Role::Rw)->create();

        $this->actingAs($warga)->getJson("/api/residents/{$other->id}")->assertForbidden();
        $this->actingAs($rw)->postJson("/api/residents/{$other->id}/verify")->assertForbidden();

        $this->assertTrue(AuditLog::where('action', 'auth.access_denied')->where('user_id', $warga->id)->exists());
    }

    public function test_rt_cannot_manage_staff_through_resident_routes(): void
    {
        $otherRt = User::factory()->forRt($this->rt1)->role(Role::Rt)->create();

        $this->actingAs($this->ketuaRt1)->getJson("/api/residents/{$otherRt->id}")->assertForbidden();
        $this->actingAs($this->ketuaRt1)->postJson("/api/users/{$otherRt->id}/reset-password")->assertForbidden();
    }

    public function test_reject_requires_reason_and_is_shown_at_login(): void
    {
        $warga = User::factory()->forRt($this->rt1)->pending()->create(['phone' => '6281299998888']);

        $this->actingAs($this->ketuaRt1)->postJson("/api/residents/{$warga->id}/reject")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->actingAs($this->ketuaRt1)->postJson("/api/residents/{$warga->id}/reject", ['reason' => 'Bukan warga RT 01'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Bukan warga RT 01');

        auth()->guard('web')->logout();
        $this->postJson('/api/auth/login', ['phone' => '081299998888', 'password' => 'password'])
            ->assertJsonPath('errors.phone.0', 'Pendaftaran akun ditolak: Bukan warga RT 01');
    }

    public function test_only_pending_accounts_can_be_verified(): void
    {
        $warga = User::factory()->forRt($this->rt1)->create();

        $this->actingAs($this->ketuaRt1)->postJson("/api/residents/{$warga->id}/verify")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Akun ini sudah berstatus Aktif.');
    }

    public function test_rt_resets_resident_password(): void
    {
        $warga = User::factory()->forRt($this->rt1)->create(['phone' => '6281299998888']);

        $password = $this->actingAs($this->ketuaRt1)->postJson("/api/users/{$warga->id}/reset-password")
            ->assertOk()
            ->json('data.temporary_password');

        $warga->refresh();
        $this->assertTrue($warga->must_change_password);
        $this->assertTrue(Hash::check($password, $warga->password));
        $this->assertTrue(AuditLog::where('action', 'user.password_reset')->where('subject_id', $warga->id)->exists());

        auth()->guard('web')->logout();
        $this->postJson('/api/auth/login', ['phone' => '081299998888', 'password' => $password])
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true);
    }

    public function test_rt_cannot_reset_password_of_another_rt_resident(): void
    {
        $warga = User::factory()->forRt($this->rt2)->create();
        $hash = $warga->password;

        $this->actingAs($this->ketuaRt1)->postJson("/api/users/{$warga->id}/reset-password")->assertForbidden();
        $this->assertSame($hash, $warga->fresh()->password);
    }
}
