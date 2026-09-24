<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Rt;
use App\Models\User;
use Database\Seeders\RegionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RegionSeeder::class);
        // Request dari SPA (origin stateful) supaya Sanctum memakai sesi.
        $this->withHeader('Referer', 'http://localhost:5173');
    }

    public function test_regions_are_public(): void
    {
        $this->getJson('/api/regions')
            ->assertOk()
            ->assertJsonCount(2, 'data.rws')
            ->assertJsonPath('data.rws.0.label', 'RW 03')
            ->assertJsonCount(2, 'data.rws.0.rts');
    }

    public function test_warga_registers_as_pending_in_chosen_rt(): void
    {
        $rt = Rt::where('number', 2)->whereRelation('rw', 'number', 3)->firstOrFail();

        $this->postJson('/api/auth/register', [
            'name' => 'Siti Aminah',
            'phone' => '0812-3456-7890',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
            'rw_id' => $rt->rw_id,
            'rt_id' => $rt->id,
            'residency_status' => 'kontrak',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.role', 'warga')
            ->assertJsonPath('data.rt.label', 'RT 02');

        $user = User::where('phone', '6281234567890')->firstOrFail();
        $this->assertSame($rt->village_id, $user->village_id);
        $this->assertSame($rt->rw_id, $user->rw_id);
        $this->assertAuthenticatedAs($user);
    }

    public function test_register_rejects_rt_outside_chosen_rw(): void
    {
        [$rw3, $rw4] = Rt::with('rw')->get()->groupBy('rw.number')->values();

        $this->postJson('/api/auth/register', [
            'name' => 'Budi',
            'phone' => '081200000001',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
            'rw_id' => $rw3->first()->rw_id,
            'rt_id' => $rw4->first()->id,
            'residency_status' => 'tetap',
        ])->assertUnprocessable()->assertJsonValidationErrors('rt_id');
    }

    public function test_login_with_local_phone_format(): void
    {
        $user = User::factory()->forRt(Rt::first())->create(['phone' => '6281299998888']);

        $this->postJson('/api/auth/login', ['phone' => '081299998888', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.scope.level', 'self');

        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->assertTrue(AuditLog::where('action', 'auth.login')->where('user_id', $user->id)->exists());
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->forRt(Rt::first())->create(['phone' => '6281299998888']);

        $this->postJson('/api/auth/login', ['phone' => '081299998888', 'password' => 'salah'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
        $this->assertGuest('web');
    }

    public function test_rejected_account_cannot_login(): void
    {
        User::factory()->forRt(Rt::first())->create([
            'phone' => '6281299998888',
            'status' => UserStatus::Rejected,
            'rejection_reason' => 'Bukan warga RT ini',
        ]);

        $this->postJson('/api/auth/login', ['phone' => '081299998888', 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.phone.0', 'Pendaftaran akun ditolak: Bukan warga RT ini');
        $this->assertGuest('web');
    }

    public function test_pending_account_is_blocked_from_active_routes(): void
    {
        $pending = User::factory()->forRt(Rt::first())->pending()->create();

        Route::middleware(['api', 'auth:sanctum', 'active'])
            ->get('/api/_test/active-only', fn () => ['ok' => true]);

        $this->actingAs($pending)->getJson('/api/_test/active-only')
            ->assertForbidden()
            ->assertJsonPath('code', 'account_not_active');

        $this->actingAs(User::factory()->forRt(Rt::first())->create())
            ->getJson('/api/_test/active-only')->assertOk();
    }

    public function test_logout(): void
    {
        $user = User::factory()->forRt(Rt::first())->create();

        $this->actingAs($user)->postJson('/api/auth/logout')->assertOk();
        $this->assertGuest('web');
    }
}
