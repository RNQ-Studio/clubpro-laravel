<?php

namespace Tests\Feature\Api;

use App\Models\Asset;
use App\Models\Community;
use App\Models\User;
use App\Support\Enums\AssetStatus;
use App\Support\Enums\CommunityStatus;
use App\Support\Enums\CommunityType;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CommunityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed(RolePermissionSeeder::class);

        $this->user = User::factory()->create();
    }

    /** @return array<string, array{string, string}> */
    public static function endpoints(): array
    {
        return [
            'daftar' => ['GET', '/api/v1/communities'],
            'milik saya' => ['GET', '/api/v1/communities/mine'],
            'detail' => ['GET', '/api/v1/communities/1'],
            'kirim' => ['POST', '/api/v1/communities'],
            'jenis' => ['GET', '/api/v1/community-types'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_tanpa_login_ditolak(string $method, string $path): void
    {
        $this->json($method, $path)->assertUnauthorized();
    }

    public function test_daftar_hanya_memuat_yang_sudah_disetujui(): void
    {
        Community::factory()->approved()->create(['name' => 'Komunitas Disetujui']);
        Community::factory()->create(['name' => 'Komunitas Menunggu']);
        Community::factory()->rejected()->create(['name' => 'Komunitas Ditolak']);
        Passport::actingAs($this->user);

        $this->getJson('/api/v1/communities')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Komunitas Disetujui')
            ->assertJsonPath('data.0.status', 'approved')
            ->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_pengajuan_sendiri_tetap_tidak_tampil_di_daftar_umum(): void
    {
        Community::factory()->for($this->user, 'owner')->create();
        Passport::actingAs($this->user);

        $this->getJson('/api/v1/communities')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_daftar_memuat_ringkasan_tanpa_kontak(): void
    {
        Community::factory()->approved()->create([
            'description' => str_repeat('Deskripsi panjang. ', 30),
            'type' => CommunityType::Lainnya,
            'type_other' => 'Burung Kicau',
        ]);
        Passport::actingAs($this->user);

        $row = $this->getJson('/api/v1/communities')->assertOk()->json('data.0');

        $this->assertSame('lainnya', $row['type']);
        $this->assertSame('Burung Kicau', $row['type_label']);
        $this->assertSame('Kota Bandung', $row['city_name']);
        $this->assertLessThanOrEqual(163, mb_strlen($row['description']));
        $this->assertArrayNotHasKey('contact_phone', $row);
        $this->assertArrayNotHasKey('address', $row);
    }

    public function test_cari_dan_saring(): void
    {
        Community::factory()->approved()->create(['name' => 'Pemanah Bandung', 'short_name' => 'PB', 'type' => CommunityType::Panahan]);
        Community::factory()->approved()->create([
            'name' => 'Sepak Bola Solo',
            'short_name' => 'SBS',
            'type' => CommunityType::SepakBola,
            'province_code' => '33',
            'province_name' => 'Jawa Tengah',
            'city_code' => '33.72',
            'city_name' => 'Kota Surakarta',
        ]);
        Passport::actingAs($this->user);

        foreach (['PEMANAH' => 'Pemanah Bandung', 'sbs' => 'Sepak Bola Solo', 'surakarta' => 'Sepak Bola Solo'] as $search => $name) {
            $this->getJson('/api/v1/communities?search='.$search)
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', $name);
        }

        $this->getJson('/api/v1/communities?type=sepak_bola')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.short_name', 'SBS');
        $this->getJson('/api/v1/communities?province_code=32')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.short_name', 'PB');
        $this->getJson('/api/v1/communities?search='.urlencode('%'))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/communities?type=tidak_ada')->assertUnprocessable();
    }

    public function test_daftar_diurutkan_nama_dan_berhalaman(): void
    {
        foreach (['Cendana', 'Anggrek', 'Bakung'] as $name) {
            Community::factory()->approved()->create(['name' => $name]);
        }
        Passport::actingAs($this->user);

        $this->getJson('/api/v1/communities?per_page=2')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Anggrek')
            ->assertJsonPath('data.1.name', 'Bakung')
            ->assertJsonPath('meta.pagination.last_page', 2);
    }

    public function test_milik_saya_memuat_semua_status_dan_hanya_milik_sendiri(): void
    {
        Community::factory()->for($this->user, 'owner')->create(['name' => 'Menunggu']);
        Community::factory()->for($this->user, 'owner')->rejected()->create(['name' => 'Ditolak']);
        Community::factory()->for($this->user, 'owner')->approved()->create(['name' => 'Disetujui']);
        Community::factory()->create(['name' => 'Milik Orang Lain']);
        Passport::actingAs($this->user);

        $rows = collect($this->getJson('/api/v1/communities/mine')->assertOk()->json('data'));

        $this->assertEqualsCanonicalizing(['Menunggu', 'Ditolak', 'Disetujui'], $rows->pluck('name')->all());
    }

    public function test_detail_disetujui_terbuka_untuk_anggota_lain_dengan_kontak(): void
    {
        $community = Community::factory()->approved()->create(['contact_phone' => '081234567890']);
        $community->photos()->attach($this->image(User::factory()->create())->id, ['sort_order' => 0]);
        Passport::actingAs($this->user);

        $this->getJson("/api/v1/communities/{$community->id}")
            ->assertOk()
            ->assertJsonPath('data.contact_phone', '081234567890')
            ->assertJsonCount(1, 'data.photo_urls');
    }

    public function test_pengajuan_orang_lain_seolah_tidak_ada(): void
    {
        $community = Community::factory()->create();
        Passport::actingAs($this->user);

        $this->getJson("/api/v1/communities/{$community->id}")->assertNotFound();
    }

    public function test_pengaju_bisa_melihat_pengajuannya(): void
    {
        $community = Community::factory()->for($this->user, 'owner')->create();
        Passport::actingAs($this->user);

        $this->getJson("/api/v1/communities/{$community->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_super_admin_bisa_melihat_pengajuan(): void
    {
        $community = Community::factory()->create();
        $this->user->assignRole('super-admin');
        Passport::actingAs($this->user);

        $this->getJson("/api/v1/communities/{$community->id}")->assertOk();
    }

    public function test_komunitas_tidak_ada_404(): void
    {
        Passport::actingAs($this->user);

        $this->getJson('/api/v1/communities/999999')->assertNotFound();
        $this->getJson('/api/v1/communities/abc')->assertNotFound();
    }

    public function test_pilihan_jenis_memuat_contoh_dari_permintaan_dan_lainnya(): void
    {
        Passport::actingAs($this->user);

        $types = collect($this->getJson('/api/v1/community-types')->assertOk()->json('data'));

        foreach (['Panahan', 'Fashion', 'Sepak Bola', 'Lainnya'] as $label) {
            $this->assertTrue($types->contains('label', $label), $label);
        }
        $this->assertSame('lainnya', $types->last()['value']);
    }

    public function test_pengajuan_selalu_menunggu_dan_tidak_tampil_di_publik(): void
    {
        Passport::actingAs($this->user);

        $response = $this->postJson('/api/v1/communities', $this->payload())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.name', 'Pemanah Nusantara')
            ->assertJsonPath('data.short_name', 'PN')
            ->assertJsonPath('data.province_name', 'Jawa Barat')
            ->assertJsonPath('data.city_name', 'Kota Bandung')
            ->assertJsonPath('data.contact_phone', '081234567890')
            ->assertJsonCount(2, 'data.photo_urls');

        $community = Community::query()->findOrFail($response->json('data.id'));
        $this->assertSame(CommunityStatus::Pending, $community->status);
        $this->assertTrue($this->user->is($community->owner));

        Passport::actingAs(User::factory()->create());
        $this->getJson('/api/v1/communities')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/communities/{$community->id}")->assertNotFound();
    }

    public function test_klien_tidak_bisa_menentukan_status_atau_pemilik_sendiri(): void
    {
        $other = User::factory()->create();
        Passport::actingAs($this->user);

        $response = $this->postJson('/api/v1/communities', [
            ...$this->payload(),
            'status' => 'approved',
            'user_id' => $other->id,
        ])->assertCreated();

        $community = Community::query()->findOrFail($response->json('data.id'));
        $this->assertSame(CommunityStatus::Pending, $community->status);
        $this->assertSame($this->user->id, $community->user_id);
    }

    public function test_nama_wilayah_diambil_server_bukan_dari_klien(): void
    {
        Passport::actingAs($this->user);

        $this->postJson('/api/v1/communities', [
            ...$this->payload(),
            'province_name' => 'Karangan',
            'city_name' => 'Karangan',
        ])->assertCreated()->assertJsonPath('data.city_name', 'Kota Bandung');
    }

    public function test_foto_terpakai_menjadi_permanen_dan_urutannya_terjaga(): void
    {
        Passport::actingAs($this->user);
        $first = $this->image($this->user, ['retain_until' => now()->addDay()]);
        $second = $this->image($this->user, ['retain_until' => now()->addDay()]);

        $response = $this->postJson('/api/v1/communities', [
            ...$this->payload(),
            'photo_asset_ids' => [$second->id, $first->id],
        ])->assertCreated();

        $this->assertNull($first->refresh()->retain_until);
        $this->assertSame(
            [$second->id, $first->id],
            Community::query()->findOrFail($response->json('data.id'))->photos->pluck('id')->all(),
        );
    }

    public function test_foto_yang_ditinggalkan_tetap_akan_dibersihkan(): void
    {
        $abandoned = $this->image($this->user, ['retain_until' => now()->subMinute()]);

        $this->artisan('assets:soft-delete-expired')->assertSuccessful();

        $this->assertSame(AssetStatus::SoftDeleted, $abandoned->refresh()->status);
    }

    public function test_jenis_lainnya_wajib_menyebut_jenisnya(): void
    {
        Passport::actingAs($this->user);

        $this->postJson('/api/v1/communities', [...$this->payload(), 'type' => 'lainnya'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type_other']);

        $this->postJson('/api/v1/communities', [...$this->payload(), 'type' => 'lainnya', 'type_other' => 'Burung Kicau'])
            ->assertCreated()
            ->assertJsonPath('data.type_label', 'Burung Kicau');
    }

    public function test_jenis_lain_mengabaikan_isian_lainnya(): void
    {
        Passport::actingAs($this->user);

        $response = $this->postJson('/api/v1/communities', [...$this->payload(), 'type_other' => 'Sisa isian'])->assertCreated();

        $this->assertNull(Community::query()->findOrFail($response->json('data.id'))->type_other);
    }

    public function test_kota_harus_berada_di_provinsinya(): void
    {
        Passport::actingAs($this->user);

        $this->postJson('/api/v1/communities', [...$this->payload(), 'province_code' => '31', 'city_code' => '32.73'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['city_code']);
        $this->postJson('/api/v1/communities', [...$this->payload(), 'province_code' => '99'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['province_code']);
    }

    public function test_semua_isian_wajib(): void
    {
        Passport::actingAs($this->user);

        $this->postJson('/api/v1/communities', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name', 'short_name', 'type', 'description', 'address', 'province_code', 'city_code',
                'contact_name', 'contact_phone', 'logo_asset_id', 'photo_asset_ids',
            ])
            ->assertJsonMissingValidationErrors(['contact_email']);
    }

    public function test_pesan_validasi_berbahasa_indonesia(): void
    {
        Passport::actingAs($this->user);

        $this->postJson('/api/v1/communities', [...$this->payload(), 'name' => 'A', 'contact_phone' => 'abc'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'Nama komunitas minimal 3 karakter.')
            ->assertJsonPath('errors.contact_phone.0', 'Nomor kontak tidak valid.');
    }

    public function test_nomor_kontak_dibersihkan(): void
    {
        Passport::actingAs($this->user);

        $this->postJson('/api/v1/communities', [...$this->payload(), 'contact_phone' => '0812-3456 7890'])
            ->assertCreated()
            ->assertJsonPath('data.contact_phone', '081234567890');
    }

    public function test_lampiran_minimal_satu_maksimal_enam(): void
    {
        Passport::actingAs($this->user);

        $this->postJson('/api/v1/communities', [...$this->payload(), 'photo_asset_ids' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['photo_asset_ids']);

        $tooMany = collect(range(1, 7))->map(fn (): string => $this->image($this->user)->id)->all();
        $this->postJson('/api/v1/communities', [...$this->payload(), 'photo_asset_ids' => $tooMany])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['photo_asset_ids']);
    }

    public function test_foto_unggahan_orang_lain_ditolak(): void
    {
        $stranger = $this->image(User::factory()->create());
        Passport::actingAs($this->user);

        $this->postJson('/api/v1/communities', [...$this->payload(), 'logo_asset_id' => $stranger->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['logo_asset_id']);
        $this->postJson('/api/v1/communities', [...$this->payload(), 'photo_asset_ids' => [$stranger->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['photo_asset_ids']);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function badImages(): array
    {
        return [
            'pdf' => [['mime_type' => 'application/pdf']],
            'svg' => [['mime_type' => 'image/svg+xml']],
            'terlalu besar' => [['size' => 6 * 1024 * 1024]],
        ];
    }

    /** @param  array<string, mixed>  $attributes */
    #[DataProvider('badImages')]
    public function test_berkas_selain_foto_wajar_ditolak(array $attributes): void
    {
        $bad = $this->image($this->user, $attributes);
        Passport::actingAs($this->user);

        $this->postJson('/api/v1/communities', [...$this->payload(), 'logo_asset_id' => $bad->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['logo_asset_id']);
    }

    public function test_foto_terhapus_ditolak(): void
    {
        $deleted = $this->image($this->user);
        $deleted->status = AssetStatus::SoftDeleted;
        $deleted->save();
        Passport::actingAs($this->user);

        $this->postJson('/api/v1/communities', [...$this->payload(), 'logo_asset_id' => $deleted->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['logo_asset_id']);
    }

    public function test_foto_profil_tidak_boleh_dipakai_ulang_sebagai_lampiran(): void
    {
        $image = $this->image($this->user);
        Passport::actingAs($this->user);

        $this->postJson('/api/v1/communities', [
            ...$this->payload(),
            'logo_asset_id' => $image->id,
            'photo_asset_ids' => [$image->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['photo_asset_ids']);
    }

    public function test_foto_yang_sudah_dipakai_komunitas_lain_ditolak(): void
    {
        Passport::actingAs($this->user);
        $payload = $this->payload();
        $this->postJson('/api/v1/communities', $payload)->assertCreated();

        $this->postJson('/api/v1/communities', [
            ...$this->payload(),
            'name' => 'Komunitas Kedua',
            'logo_asset_id' => $payload['logo_asset_id'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['logo_asset_id']);
    }

    public function test_kirim_ulang_nama_sama_saat_masih_menunggu_ditolak(): void
    {
        Passport::actingAs($this->user);
        $this->postJson('/api/v1/communities', $this->payload())->assertCreated();

        $this->postJson('/api/v1/communities', [...$this->payload(), 'name' => 'PEMANAH NUSANTARA'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->assertSame(1, Community::query()->count());
    }

    public function test_gagal_menyimpan_tidak_meninggalkan_data_setengah_jadi(): void
    {
        $taken = $this->image($this->user);
        $community = Community::factory()->for($this->user, 'owner')->create();
        DB::table('community_photos')->insert(['community_id' => $community->id, 'asset_id' => $taken->id]);
        Passport::actingAs($this->user);

        $this->postJson('/api/v1/communities', [...$this->payload(), 'photo_asset_ids' => [$taken->id]])
            ->assertUnprocessable();

        $this->assertSame(1, Community::query()->count());
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'name' => 'Pemanah Nusantara',
            'short_name' => 'PN',
            'type' => 'panahan',
            'description' => 'Komunitas pecinta panahan tradisional yang rutin berlatih setiap akhir pekan.',
            'address' => 'Jl. Merdeka No. 10, Sumur Bandung',
            'province_code' => '32',
            'city_code' => '32.73',
            'contact_name' => 'Rina Ayu',
            'contact_phone' => '081234567890',
            'contact_email' => 'rina.ayu@gmail.com',
            'logo_asset_id' => $this->image($this->user)->id,
            'photo_asset_ids' => [$this->image($this->user)->id, $this->image($this->user)->id],
        ];
    }

    /** @param  array<string, mixed>  $attributes */
    private function image(User $owner, array $attributes = []): Asset
    {
        return Asset::factory()->create([
            'user_id' => $owner->getKey(),
            'mime_type' => 'image/jpeg',
            'size' => 200_000,
            ...$attributes,
        ]);
    }
}
