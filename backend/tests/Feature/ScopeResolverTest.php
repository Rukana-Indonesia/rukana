<?php

namespace Tests\Feature;

use App\Enums\Level;
use App\Enums\Role;
use App\Models\Rt;
use App\Models\User;
use App\Models\Village;
use Database\Seeders\RegionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScopeResolverTest extends TestCase
{
    use RefreshDatabase;

    private Rt $rt1;

    private Rt $rt2;

    private Rt $otherRwRt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RegionSeeder::class);

        $rts = Rt::with('rw')->orderBy('id')->get();
        [$this->rt1, $this->rt2, $this->otherRwRt] = [$rts[0], $rts[1], $rts[2]];
    }

    public function test_scope_follows_role(): void
    {
        $warga = User::factory()->forRt($this->rt1)->create();
        $rt = User::factory()->forRt($this->rt1)->role(Role::Rt)->create();
        $rw = User::factory()->forRw($this->rt1->rw)->role(Role::Rw)->create();
        $operator = User::factory()->forVillage(Village::first())->role(Role::Operator)->create();

        $this->assertNull($warga->scope()->level);
        $this->assertSame($warga->id, $warga->scope()->userId);
        $this->assertSame(Level::Rt, $rt->scope()->level);
        $this->assertSame($this->rt1->id, $rt->scope()->regionId());
        $this->assertSame(Level::Rw, $rw->scope()->level);
        $this->assertSame($this->rt1->rw_id, $rw->scope()->regionId());
        $this->assertSame(Level::Village, $operator->scope()->level);
    }

    public function test_covers_checks_region_boundaries(): void
    {
        $v = $this->rt1->village_id;
        $rtScope = User::factory()->forRt($this->rt1)->role(Role::Rt)->create()->scope();
        $rwScope = User::factory()->forRw($this->rt1->rw)->role(Role::Rw)->create()->scope();

        $this->assertTrue($rtScope->covers($v, $this->rt1->rw_id, $this->rt1->id));
        $this->assertFalse($rtScope->covers($v, $this->rt2->rw_id, $this->rt2->id));
        $this->assertTrue($rwScope->covers($v, $this->rt2->rw_id, $this->rt2->id));
        $this->assertFalse($rwScope->covers($v, $this->otherRwRt->rw_id, $this->otherRwRt->id));
        $this->assertFalse($rwScope->covers($v + 1, $this->rt1->rw_id, $this->rt1->id));
    }

    public function test_apply_filters_query_by_snapshot_columns(): void
    {
        $owner = User::factory()->forRt($this->rt1)->create();
        $rows = collect([$this->rt1, $this->rt1, $this->rt2, $this->otherRwRt])
            ->map(fn (Rt $rt, $i) => ['village_id' => $rt->village_id, 'rw_id' => $rt->rw_id, 'rt_id' => $rt->id, 'user_id' => $i === 0 ? $owner->id : null]);

        $query = fn () => DB::query()->fromSub(
            $rows->map(fn ($r) => DB::query()->selectRaw('? as village_id, ? as rw_id, ? as rt_id, ? as user_id', array_values($r)))
                ->reduce(fn ($carry, $q) => $carry ? $carry->unionAll($q) : $q),
            't'
        );

        $count = fn (User $u) => $u->scope()->apply($query())->count();

        $this->assertSame(1, $count($owner));
        $this->assertSame(2, $count(User::factory()->forRt($this->rt1)->role(Role::Rt)->create()));
        $this->assertSame(3, $count(User::factory()->forRw($this->rt1->rw)->role(Role::Rw)->create()));
        $this->assertSame(4, $count(User::factory()->forVillage(Village::first())->role(Role::KepalaDesa)->create()));
    }
}
