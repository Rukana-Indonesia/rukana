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

class RatingTest extends TestCase
{
    use RefreshDatabase;

    private Rt $rt1;

    private User $warga;

    private User $wargaLain;

    private User $ketuaRt1;

    private User $ketuaRt2;

    private User $ketuaRw;

    private User $ketuaRwLain;

    private User $operator;

    private User $kepalaDesa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RegionSeeder::class, ServiceTypeSeeder::class, ComplaintCategorySeeder::class]);
        $this->withHeader('Referer', 'http://localhost:5173');
        Storage::fake('local');

        [$this->rt1, $rt2, $rtRwLain] = Rt::with('rw')->orderBy('id')->take(3)->get();
        $village = Village::firstOrFail();
        $this->warga = User::factory()->forRt($this->rt1)->create();
        $this->wargaLain = User::factory()->forRt($this->rt1)->create();
        $this->ketuaRt1 = User::factory()->forRt($this->rt1)->role(Role::Rt)->create();
        $this->ketuaRt2 = User::factory()->forRt($rt2)->role(Role::Rt)->create();
        $this->ketuaRw = User::factory()->forRw($this->rt1->rw)->role(Role::Rw)->create();
        $this->ketuaRwLain = User::factory()->forRw($rtRwLain->rw)->role(Role::Rw)->create();
        $this->operator = User::factory()->forVillage($village)->role(Role::Operator)->create();
        $this->kepalaDesa = User::factory()->forVillage($village)->role(Role::KepalaDesa)->create();
    }

    /** UAT 10: laporan selesai, warga memberi rating 4, rating tampil di dasbor RT/RW/desa. */
    public function test_resolved_complaint_rating_shows_on_rt_rw_and_village_dashboards(): void
    {
        $id = $this->resolvedComplaint();

        $this->actingAs($this->warga)->getJson("/api/complaints/{$id}")
            ->assertJsonPath('data.can.rate', true)
            ->assertJsonPath('data.rating', null);

        $this->actingAs($this->warga)->postJson("/api/complaints/{$id}/rating", ['score' => 4, 'comment' => 'Cepat ditangani, terima kasih.'])
            ->assertCreated()
            ->assertJsonPath('data.score', 4);

        $this->actingAs($this->warga)->getJson("/api/complaints/{$id}")
            ->assertJsonPath('data.can.rate', false)
            ->assertJsonPath('data.rating.score', 4)
            ->assertJsonPath('data.rating.comment', 'Cepat ditangani, terima kasih.');
        $this->actingAs($this->ketuaRt1)->getJson("/api/complaints/{$id}")->assertJsonPath('data.rating.score', 4);

        $this->actingAs($this->ketuaRt1)->getJson('/api/ratings/summary')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.average', 4)
            ->assertJsonPath('data.by_type.complaint.total', 1)
            ->assertJsonPath('data.distribution.1', ['score' => 4, 'total' => 1])
            ->assertJsonPath('data.by_category.0.name', 'Lampu Jalan Mati')
            ->assertJsonPath('data.recent.0.comment', 'Cepat ditangani, terima kasih.')
            ->assertJsonPath('data.recent.0.region', 'RT 01/RW 03')
            ->assertJsonMissingPath('data.recent.0.user');
        $this->actingAs($this->ketuaRw)->getJson('/api/ratings/summary')
            ->assertJsonPath('data.average', 4)
            ->assertJsonPath('data.by_region.0', ['id' => $this->rt1->id, 'label' => 'RT 01', 'total' => 1, 'average' => 4]);
        $this->actingAs($this->operator)->getJson('/api/ratings/summary')
            ->assertJsonPath('data.by_region.0.label', 'RW 03');
        $this->actingAs($this->kepalaDesa)->getJson('/api/ratings/summary')->assertJsonPath('data.total', 1);

        // Wilayah lain tidak ikut menghitung rating ini.
        $this->actingAs($this->ketuaRt2)->getJson('/api/ratings/summary')
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.average', null);
        $this->actingAs($this->ketuaRwLain)->getJson('/api/ratings/summary')->assertJsonPath('data.total', 0);
    }

    public function test_rating_is_only_for_the_owner_once_after_completion(): void
    {
        $category = ComplaintCategory::where('slug', 'sampah-menumpuk')->value('id');
        $open = $this->actingAs($this->warga)->postJson('/api/complaints', [
            'title' => 'Sampah', 'description' => 'Menumpuk.', 'category_id' => $category, 'location_text' => 'Gang Mawar',
        ])->json('data.id');
        $this->actingAs($this->warga)->postJson("/api/complaints/{$open}/rating", ['score' => 5])->assertForbidden();

        $id = $this->resolvedComplaint();
        $this->actingAs($this->wargaLain)->postJson("/api/complaints/{$id}/rating", ['score' => 1])->assertForbidden();
        $this->actingAs($this->ketuaRt1)->postJson("/api/complaints/{$id}/rating", ['score' => 5])->assertForbidden();
        $this->actingAs($this->warga)->postJson("/api/complaints/{$id}/rating", ['score' => 6])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('score');
        $this->actingAs($this->warga)->postJson("/api/complaints/{$id}/rating", ['score' => 3])->assertCreated();
        $this->actingAs($this->warga)->postJson("/api/complaints/{$id}/rating", ['score' => 5])->assertForbidden();

        $this->actingAs($this->warga)->getJson('/api/ratings/summary')->assertForbidden();
    }

    public function test_completed_letter_can_be_rated_and_counts_per_service(): void
    {
        $id = $this->actingAs($this->warga)->postJson('/api/letters', [
            'service_type_id' => ServiceType::where('slug', 'pengantar-rt')->value('id'),
            'purpose' => 'Mengurus BPJS',
        ])->assertCreated()->json('data.id');
        $this->actingAs($this->warga)->getJson("/api/letters/{$id}")->assertJsonPath('data.can.rate', false);

        // Alur RT saja: verifikasi RT langsung menyelesaikan surat.
        $this->actingAs($this->ketuaRt1)->postJson("/api/letters/{$id}/verify")->assertJsonPath('data.status', 'completed');
        $this->actingAs($this->warga)->getJson('/api/letters')->assertJsonPath('data.0.can.rate', true);

        $this->actingAs($this->warga)->postJson("/api/letters/{$id}/rating", ['score' => 5])->assertCreated();
        $this->actingAs($this->warga)->getJson('/api/letters')->assertJsonPath('data.0.rating.score', 5);

        $this->actingAs($this->operator)->getJson('/api/ratings/summary?type=letter_request')
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.by_service.0', ['name' => 'Surat Pengantar RT (Umum)', 'total' => 1, 'average' => 5])
            ->assertJsonPath('data.recent', []);
        $this->actingAs($this->operator)->getJson('/api/ratings/summary?type=complaint')->assertJsonPath('data.total', 0);
    }

    private function resolvedComplaint(): int
    {
        $id = $this->actingAs($this->warga)->postJson('/api/complaints', [
            'title' => 'Lampu jalan mati',
            'description' => 'Gelap sejak kemarin.',
            'category_id' => ComplaintCategory::where('slug', 'lampu-jalan-mati')->value('id'),
            'location_text' => 'Depan masjid',
        ])->assertCreated()->json('data.id');

        foreach (['verify' => [], 'start' => [], 'resolve' => ['note' => 'Lampu sudah diganti.']] as $action => $data) {
            $this->actingAs($this->ketuaRt1)->postJson("/api/complaints/{$id}/{$action}", $data)->assertOk();
        }

        return $id;
    }
}
