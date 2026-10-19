<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Rt;
use App\Models\Rw;
use App\Models\User;
use App\Models\Village;
use Database\Seeders\RegionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    private Village $village;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RegionSeeder::class);
        $this->withHeader('Referer', 'http://localhost:5173');

        $this->village = Village::firstOrFail();
        $this->operator = User::factory()->forVillage($this->village)->role(Role::Operator)->create();
    }

    public function test_regions_overview_counts_residents_and_officials(): void
    {
        $rt = Rt::orderBy('id')->firstOrFail();
        User::factory()->forRt($rt)->count(2)->create();
        User::factory()->forRt($rt)->pending()->create();
        $ketua = User::factory()->forRt($rt)->role(Role::Rt)->create();

        $this->actingAs($this->operator)->getJson('/api/admin/regions')
            ->assertOk()
            ->assertJsonPath('data.rws.0.rts.0.residents_active', 2)
            ->assertJsonPath('data.rws.0.rts.0.residents_pending', 1)
            ->assertJsonPath('data.rws.0.rts.0.officials.0.id', $ketua->id);
    }

    public function test_operator_adds_rw_and_rt(): void
    {
        $rwId = $this->actingAs($this->operator)->postJson('/api/admin/rws', ['number' => 5, 'name' => 'Dusun Cikadu'])
            ->assertCreated()
            ->assertJsonPath('data.label', 'RW 05')
            ->json('data.id');

        $this->postJson("/api/admin/rws/{$rwId}/rts", ['number' => 1])
            ->assertCreated()
            ->assertJsonPath('data.label', 'RT 01');

        $this->assertSame($this->village->id, Rt::where('rw_id', $rwId)->value('village_id'));
        $this->postJson('/api/admin/rws', ['number' => 5])->assertJsonValidationErrors('number');
        $this->postJson("/api/admin/rws/{$rwId}/rts", ['number' => 1])->assertJsonValidationErrors('number');
    }

    public function test_region_with_accounts_cannot_be_deleted(): void
    {
        $rt = Rt::orderBy('id')->firstOrFail();
        User::factory()->forRt($rt)->create();

        $this->actingAs($this->operator)->deleteJson("/api/admin/rts/{$rt->id}")->assertStatus(409);
        $this->deleteJson("/api/admin/rws/{$rt->rw_id}")->assertStatus(409);

        $empty = Rt::where('id', '!=', $rt->id)->firstOrFail();
        $this->deleteJson("/api/admin/rts/{$empty->id}")->assertOk();
        $this->assertModelMissing($empty);
    }

    public function test_operator_cannot_touch_regions_of_another_village(): void
    {
        $other = Village::create(['name' => 'Desa Lain']);
        $rw = Rw::create(['village_id' => $other->id, 'number' => 1]);

        $this->actingAs($this->operator)->putJson("/api/admin/rws/{$rw->id}", ['number' => 9])->assertForbidden();
        $this->postJson("/api/admin/rws/{$rw->id}/rts", ['number' => 1])->assertForbidden();
        $this->assertSame(1, $rw->fresh()->number);
    }

    public function test_kepala_desa_can_view_but_not_manage(): void
    {
        $kades = User::factory()->forVillage($this->village)->role(Role::KepalaDesa)->create();

        $this->actingAs($kades)->getJson('/api/admin/regions')->assertOk();
        $this->getJson('/api/admin/officials')->assertOk();
        $this->postJson('/api/admin/rws', ['number' => 7])->assertForbidden();
        $this->postJson('/api/admin/officials', [])->assertForbidden();
    }

    public function test_operator_creates_rt_official_with_temporary_password(): void
    {
        $rt = Rt::orderBy('id')->firstOrFail();

        $response = $this->actingAs($this->operator)->postJson('/api/admin/officials', [
            'name' => 'Pak Ujang',
            'phone' => '0813-1111-2222',
            'role' => 'rt',
            'rt_id' => $rt->id,
            'rw_id' => 999, // diabaikan untuk peran RT; RW ikut RT
        ])
            ->assertCreated()
            ->assertJsonPath('data.role', 'rt')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.must_change_password', true)
            ->assertJsonPath('data.rw.id', $rt->rw_id);

        $official = User::where('phone', '6281311112222')->firstOrFail();
        $this->assertTrue(Hash::check($response->json('meta.temporary_password'), $official->password));
        $this->assertSame($rt->id, $official->scope()->rtId);
    }

    public function test_official_role_requires_matching_region(): void
    {
        $this->actingAs($this->operator)->postJson('/api/admin/officials', [
            'name' => 'Bu Ratna', 'phone' => '081311113333', 'role' => 'rw',
        ])->assertJsonValidationErrors('rw_id');

        $this->postJson('/api/admin/officials', [
            'name' => 'Bu Ratna', 'phone' => '081311113333', 'role' => 'warga',
        ])->assertJsonValidationErrors('role');

        $foreignRw = Rw::create(['village_id' => Village::create(['name' => 'Desa Lain'])->id, 'number' => 1]);
        $this->postJson('/api/admin/officials', [
            'name' => 'Bu Ratna', 'phone' => '081311113333', 'role' => 'rw', 'rw_id' => $foreignRw->id,
        ])->assertJsonValidationErrors('rw_id');
    }

    public function test_operator_moves_official_to_another_rt(): void
    {
        [$rt1, $rt2] = Rt::orderBy('id')->take(2)->get();
        $ketua = User::factory()->forRt($rt1)->role(Role::Rt)->create();

        $this->actingAs($this->operator)->putJson("/api/admin/officials/{$ketua->id}", [
            'name' => $ketua->name, 'phone' => $ketua->phone, 'role' => 'rt', 'rt_id' => $rt2->id,
        ])->assertOk()->assertJsonPath('data.rt.id', $rt2->id);

        $this->assertSame($rt2->id, $ketua->fresh()->rt_id);
    }

    public function test_suspended_official_cannot_login(): void
    {
        $ketua = User::factory()->forRt(Rt::firstOrFail())->role(Role::Rt)->create(['phone' => '6281311114444']);

        $this->actingAs($this->operator)->postJson("/api/admin/officials/{$ketua->id}/suspend")
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');
        $this->assertTrue(AuditLog::where('action', 'user.suspended')->where('subject_id', $ketua->id)->exists());

        auth()->guard('web')->logout();
        $this->postJson('/api/auth/login', ['phone' => '081311114444', 'password' => 'password'])
            ->assertUnprocessable();

        $this->actingAs($this->operator)->postJson("/api/admin/officials/{$ketua->id}/activate")
            ->assertOk();
        $this->assertSame(UserStatus::Active, $ketua->fresh()->status);
    }

    public function test_operator_cannot_manage_own_account_or_residents_as_officials(): void
    {
        $warga = User::factory()->forRt(Rt::firstOrFail())->create();

        $this->actingAs($this->operator)->postJson("/api/admin/officials/{$this->operator->id}/suspend")->assertForbidden();
        $this->postJson("/api/admin/officials/{$warga->id}/suspend")->assertForbidden();
        $this->postJson("/api/users/{$this->operator->id}/reset-password")->assertForbidden();
    }

    public function test_operator_resets_official_password(): void
    {
        $ketua = User::factory()->forRt(Rt::firstOrFail())->role(Role::Rt)->create();

        $this->actingAs($this->operator)->postJson("/api/users/{$ketua->id}/reset-password")
            ->assertOk()
            ->assertJsonStructure(['data' => ['temporary_password']]);
        $this->assertTrue($ketua->fresh()->must_change_password);
    }

    public function test_rt_and_rw_cannot_open_admin_routes(): void
    {
        $rt = Rt::with('rw')->firstOrFail();
        $ketuaRt = User::factory()->forRt($rt)->role(Role::Rt)->create();
        $ketuaRw = User::factory()->forRw($rt->rw)->role(Role::Rw)->create();

        $this->actingAs($ketuaRt)->getJson('/api/admin/officials')->assertForbidden();
        $this->actingAs($ketuaRw)->getJson('/api/admin/regions')->assertForbidden();
    }

    public function test_operator_sets_village_map_center_used_by_maps(): void
    {
        $kades = User::factory()->forVillage($this->village)->role(Role::KepalaDesa)->create();
        $rw = User::factory()->forRw(Rw::firstOrFail())->role(Role::Rw)->create();

        $this->actingAs($kades)->getJson('/api/admin/village')
            ->assertOk()
            ->assertJsonPath('data.map_center.configured', false)
            ->assertJsonPath('data.can_update', false);
        $this->actingAs($kades)->putJson('/api/admin/village', ['map_latitude' => -7, 'map_longitude' => 107, 'map_zoom' => 16])->assertForbidden();
        $this->actingAs($rw)->getJson('/api/admin/village')->assertForbidden();

        $this->actingAs($this->operator)->putJson('/api/admin/village', ['map_latitude' => 91, 'map_longitude' => 107.5, 'map_zoom' => 25])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['map_latitude', 'map_zoom']);

        $this->actingAs($this->operator)->putJson('/api/admin/village', ['map_latitude' => -7.0123456, 'map_longitude' => 107.6543210, 'map_zoom' => 16])
            ->assertOk()
            ->assertJsonPath('data.map_center', ['latitude' => -7.0123456, 'longitude' => 107.654321, 'zoom' => 16, 'configured' => true]);
        $this->assertSame(1, AuditLog::where('action', 'village.settings_updated')->count());

        // Dipakai peta sebaran (pengurus) dan form laporan (lewat profil akun).
        $this->actingAs($rw)->getJson('/api/complaint-map')->assertJsonPath('data.center.latitude', -7.0123456);
        $this->actingAs($rw->fresh())->getJson('/api/auth/me')->assertJsonPath('data.village.map_center.zoom', 16);
    }
}
