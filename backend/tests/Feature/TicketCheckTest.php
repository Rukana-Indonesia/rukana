<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Rt;
use App\Models\ServiceType;
use App\Models\User;
use Database\Seeders\RegionSeeder;
use Database\Seeders\ServiceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketCheckTest extends TestCase
{
    use RefreshDatabase;

    /** UAT 08: status tampil tanpa login dan tanpa data pribadi. */
    public function test_guest_checks_letter_status_without_personal_data(): void
    {
        $this->seed([RegionSeeder::class, ServiceTypeSeeder::class]);
        $this->withHeader('Referer', 'http://localhost:5173');
        $rt = Rt::firstOrFail();
        $warga = User::factory()->forRt($rt)->create(['name' => 'Siti Rahasia', 'phone' => '6281299990000']);
        $ketua = User::factory()->forRt($rt)->role(Role::Rt)->create(['name' => 'Pak Ketua']);

        $letter = $this->actingAs($warga)->postJson('/api/letters', [
            'service_type_id' => ServiceType::where('slug', 'pengantar-domisili')->value('id'),
            'purpose' => 'Daftar sekolah anak',
            'nik' => '3204123456780001',
            'form_data' => ['lama_tinggal' => '3 tahun'],
        ])->json('data');
        $this->actingAs($ketua)->postJson("/api/letters/{$letter['id']}/reject", ['note' => 'KTP Siti tidak terbaca']);
        auth()->guard('web')->logout();

        $response = $this->getJson('/api/tickets/'.strtolower($letter['code']))
            ->assertOk()
            ->assertJsonPath('data.code', $letter['code'])
            ->assertJsonPath('data.title', 'Surat Pengantar Keterangan Domisili')
            ->assertJsonPath('data.status_label', 'Ditolak')
            ->assertJsonPath('data.rejected_level_label', 'RT')
            ->assertJsonCount(2, 'data.steps');

        foreach (['Siti', '6281299990000', '3204', 'Daftar sekolah', 'Pak Ketua', 'KTP', '3 tahun'] as $private) {
            $this->assertStringNotContainsString($private, (string) $response->getContent());
        }

        $this->getJson('/api/tickets/SRT-0000-9999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Kode tiket tidak ditemukan. Periksa kembali penulisannya.');
    }

    public function test_ticket_check_is_rate_limited(): void
    {
        foreach (range(1, 20) as $_) {
            $this->getJson('/api/tickets/SRT-0000-0001')->assertNotFound();
        }

        $this->getJson('/api/tickets/SRT-0000-0001')->assertTooManyRequests();
    }
}
