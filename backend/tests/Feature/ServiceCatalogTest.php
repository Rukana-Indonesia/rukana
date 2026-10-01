<?php

namespace Tests\Feature;

use App\Enums\LetterStatus;
use App\Enums\Role;
use App\Models\LetterRequest;
use App\Models\Rt;
use App\Models\ServiceType;
use App\Models\User;
use App\Models\Village;
use Database\Seeders\RegionSeeder;
use Database\Seeders\ServiceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceCatalogTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RegionSeeder::class, ServiceTypeSeeder::class]);
        $this->withHeader('Referer', 'http://localhost:5173');

        $this->operator = User::factory()->forVillage(Village::firstOrFail())->role(Role::Operator)->create();
    }

    /** UAT 02: pengunjung tanpa login melihat syarat dan alur tiap layanan. */
    public function test_guest_sees_active_services_with_requirements_and_flow(): void
    {
        ServiceType::where('slug', 'izin-keramaian')->update(['is_active' => false]);

        $this->getJson('/api/services')
            ->assertOk()
            ->assertJsonCount(6, 'data')
            ->assertJsonMissing(['slug' => 'izin-keramaian'])
            ->assertJsonPath('data.0.slug', 'pengantar-domisili')
            ->assertJsonPath('data.0.requirements', ['KTP', 'Kartu Keluarga'])
            ->assertJsonPath('data.0.flow_levels', ['RT', 'RW', 'Desa']);

        $this->getJson('/api/services/pengantar-rt')->assertOk()->assertJsonPath('data.flow_label', 'Sampai RT');
        $this->getJson('/api/services/izin-keramaian')->assertNotFound()->assertJsonPath('message', 'Data tidak ditemukan.');
    }

    public function test_operator_creates_service_with_dynamic_fields(): void
    {
        $this->actingAs($this->operator)->postJson('/api/admin/services', [
            'name' => 'Surat Pengantar Nikah',
            'purpose' => 'Pengantar untuk mengurus pernikahan di KUA.',
            'requirements' => ['KTP', ' ', 'Kartu Keluarga'],
            'flow' => 'rt_rw_village',
            'estimated_days' => 3,
            'requires_nik' => true,
            'is_active' => true,
            'form_schema' => [
                ['label' => 'Nama calon pasangan', 'type' => 'text', 'required' => true],
                ['label' => 'Status', 'type' => 'select', 'required' => true, 'options' => ['Jejaka', 'Duda']],
                ['label' => 'Catatan', 'type' => 'text', 'options' => ['dibuang']],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'surat-pengantar-nikah')
            ->assertJsonPath('data.requirements', ['KTP', 'Kartu Keluarga'])
            ->assertJsonPath('data.form_schema.0.key', 'nama_calon_pasangan')
            ->assertJsonPath('data.form_schema.1.options', ['Jejaka', 'Duda'])
            ->assertJsonMissingPath('data.form_schema.2.options')
            ->assertJsonPath('data.sort_order', 8);
    }

    public function test_existing_field_keys_survive_label_edits(): void
    {
        $service = ServiceType::where('slug', 'pengantar-domisili')->firstOrFail();

        $this->actingAs($this->operator)->putJson("/api/admin/services/{$service->id}", [
            'name' => $service->name,
            'purpose' => $service->purpose,
            'requirements' => $service->requirements,
            'flow' => 'rt_rw',
            'form_schema' => [
                ['key' => 'lama_tinggal', 'label' => 'Sudah berapa lama tinggal', 'type' => 'text', 'required' => true],
                ['label' => 'Alamat sebelumnya', 'type' => 'textarea'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.slug', 'pengantar-domisili')
            ->assertJsonPath('data.flow', 'rt_rw')
            ->assertJsonPath('data.form_schema.0.key', 'lama_tinggal')
            ->assertJsonPath('data.form_schema.1.key', 'alamat_sebelumnya');
    }

    public function test_service_validation_messages_are_indonesian(): void
    {
        $errors = $this->actingAs($this->operator)->postJson('/api/admin/services', [
            'name' => 'Surat Pengantar Keterangan Domisili',
            'requirements' => [],
            'flow' => 'rt',
            'form_schema' => [['label' => 'Pilihan', 'type' => 'select', 'options' => ['Satu']]],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'Layanan dengan nama ini sudah ada.')
            ->assertJsonPath('errors.purpose.0', 'Keperluan wajib diisi.')
            ->json('errors');

        $this->assertSame('Isian pilihan butuh minimal dua pilihan.', $errors['form_schema.0.options'][0]);
    }

    public function test_service_with_letters_cannot_be_deleted_only_deactivated(): void
    {
        $service = ServiceType::where('slug', 'pengantar-rt')->firstOrFail();
        $warga = User::factory()->forRt(Rt::firstOrFail())->create();
        LetterRequest::create([
            'code' => 'SRT-2610-0001', 'user_id' => $warga->id, 'service_type_id' => $service->id, 'flow' => $service->flow,
            'village_id' => $warga->village_id, 'rw_id' => $warga->rw_id, 'rt_id' => $warga->rt_id,
            'purpose' => 'Tes', 'status' => LetterStatus::Submitted, 'submitted_at' => now(),
        ]);

        $this->actingAs($this->operator)->deleteJson("/api/admin/services/{$service->id}")->assertStatus(409);

        $unused = ServiceType::where('slug', 'izin-keramaian')->firstOrFail();
        $this->actingAs($this->operator)->deleteJson("/api/admin/services/{$unused->id}")->assertOk();
        $this->assertSoftDeleted($unused);
    }

    public function test_only_operator_manages_catalog(): void
    {
        $service = ServiceType::firstOrFail();
        $kades = User::factory()->forVillage(Village::firstOrFail())->role(Role::KepalaDesa)->create();

        $this->actingAs($kades)->getJson('/api/admin/services')->assertOk()->assertJsonPath('data.0.letters_count', 0);
        $this->actingAs($kades)->putJson("/api/admin/services/{$service->id}", ['name' => 'X'])->assertForbidden();
    }

    public function test_operator_cannot_edit_service_of_another_village(): void
    {
        $other = Village::create(['name' => 'Lain', 'district' => 'X', 'regency' => 'Y', 'province' => 'Z']);
        $service = $other->serviceTypes()->create([
            'slug' => 'lain', 'name' => 'Lain', 'purpose' => 'x', 'requirements' => [], 'flow' => 'rt', 'form_schema' => [],
        ]);

        $this->actingAs($this->operator)->putJson("/api/admin/services/{$service->id}", [
            'name' => 'Diambil alih', 'purpose' => 'x', 'requirements' => [], 'flow' => 'rt', 'form_schema' => [],
        ])->assertForbidden();
    }
}
