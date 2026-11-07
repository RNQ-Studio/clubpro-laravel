<?php

namespace Tests\Feature\Api;

use App\Filament\Resources\Banners\Pages\EditBanner;
use App\Models\Asset;
use App\Models\Banner;
use App\Models\Community;
use App\Models\User;
use App\Support\Enums\AssetStatus;
use App\Support\Enums\StorageType;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AdminBannerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create(['name' => 'Pemilik']);
        $this->admin->assignRole('super-admin');
    }

    /** Foto yang baru diunggah: masih bertanda sementara. */
    private function photo(?User $owner = null, array $attributes = []): Asset
    {
        return Asset::factory()->create([
            'user_id' => ($owner ?? $this->admin)->id,
            'size' => 200_000,
            'retain_until' => now()->addDay(),
            ...$attributes,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return ['title' => null, 'subtitle' => null, 'link_url' => null, ...$overrides];
    }

    /** @return array<string, array{string, string}> */
    public static function endpoints(): array
    {
        return [
            'daftar' => ['GET', '/api/v1/admin/banners'],
            'tambah' => ['POST', '/api/v1/admin/banners'],
            'urutan' => ['PUT', '/api/v1/admin/banners/order'],
            'ubah' => ['PUT', '/api/v1/admin/banners/{id}'],
            'hapus' => ['DELETE', '/api/v1/admin/banners/{id}'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_tanpa_login_ditolak(string $method, string $path): void
    {
        $banner = Banner::factory()->create();

        $this->json($method, str_replace('{id}', (string) $banner->id, $path))->assertUnauthorized();
    }

    #[DataProvider('endpoints')]
    public function test_selain_super_admin_ditolak(string $method, string $path): void
    {
        $banner = Banner::factory()->create(['title' => 'Asli']);

        foreach (['admin', 'staff'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);
            Passport::actingAs($user);

            $this->json($method, str_replace('{id}', (string) $banner->id, $path), $this->payload([
                'image_asset_id' => $this->photo($user)->id,
                'ids' => [$banner->id],
            ]))->assertForbidden();
        }

        $this->assertSame('Asli', $banner->refresh()->title);
        $this->assertSame(1, Banner::query()->count());
    }

    public function test_daftar_memuat_semua_status_sesuai_urutan_dan_ringkasan(): void
    {
        Carbon::setTestNow('2026-11-07 10:00:00');
        $community = Community::factory()->approved()->create(['name' => 'Panahan Nusantara']);

        Banner::factory()->create(['title' => 'Kedua', 'sort_order' => 2, 'is_active' => false]);
        Banner::factory()->create(['title' => 'Pertama', 'sort_order' => 1, 'link_url' => "/communities/{$community->id}"]);
        Banner::factory()->create(['title' => 'Terjadwal', 'sort_order' => 3, 'starts_at' => '2026-11-08 00:00:00']);
        Banner::factory()->create(['title' => 'Berakhir', 'sort_order' => 4, 'ends_at' => '2026-11-06 00:00:00']);
        Banner::factory()->create(['title' => 'Komunitas hilang', 'sort_order' => 5, 'link_url' => '/communities/999999']);

        Passport::actingAs($this->admin);

        $response = $this->getJson('/api/v1/admin/banners')
            ->assertOk()
            ->assertJsonPath('meta.summary', ['total' => 5, 'live' => 2, 'max_visible' => 10])
            ->assertJsonPath('data.0.link_community', ['id' => $community->id, 'name' => 'Panahan Nusantara'])
            ->assertJsonPath('data.1.link_community', null)
            ->assertJsonPath('data.4.link_community', null);

        $this->assertSame(
            ['Pertama', 'Kedua', 'Terjadwal', 'Berakhir', 'Komunitas hilang'],
            collect($response->json('data'))->pluck('title')->all(),
        );
        $this->assertSame(
            ['live', 'inactive', 'scheduled', 'ended', 'live'],
            collect($response->json('data'))->pluck('status')->all(),
        );
    }

    public function test_tambah_banner_tampil_paling_akhir_di_beranda_dan_fotonya_permanen(): void
    {
        Banner::factory()->create(['title' => 'Lama', 'sort_order' => 7]);
        $photo = $this->photo();

        Passport::actingAs($this->admin);

        $this->postJson('/api/v1/admin/banners', $this->payload([
            'title' => 'Latihan Bersama',
            'subtitle' => 'Setiap akhir pekan',
            'link_url' => '/communities',
            'image_asset_id' => $photo->id,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.title', 'Latihan Bersama')
            ->assertJsonPath('data.image_url', $photo->url)
            ->assertJsonPath('data.status', 'live')
            ->assertJsonPath('data.sort_order', 8);

        $this->assertNull($photo->refresh()->retain_until);

        $public = $this->getJson('/api/v1/banners')->assertOk();
        $this->assertSame(['Lama', 'Latihan Bersama'], collect($public->json('data'))->pluck('title')->all());
        $this->assertSame('/communities', $public->json('data.1.link_url'));

        $log = Activity::query()->where('subject_type', (new Banner)->getMorphClass())->where('event', 'created')->latest('id')->first();
        $this->assertSame($this->admin->id, $log?->causer_id);
    }

    public function test_foto_bertulisan_boleh_tanpa_judul(): void
    {
        Passport::actingAs($this->admin);

        $this->postJson('/api/v1/admin/banners', $this->payload(['image_asset_id' => $this->photo()->id]))
            ->assertCreated()
            ->assertJsonPath('data.title', '');

        // App lama membaca `title` sebagai teks wajib.
        $this->getJson('/api/v1/banners')->assertOk()->assertJsonPath('data.0.title', '');
    }

    public function test_banner_baru_wajib_berfoto(): void
    {
        Passport::actingAs($this->admin);

        $this->postJson('/api/v1/admin/banners', $this->payload(['title' => 'Tanpa foto']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image_asset_id');

        $this->assertSame(0, Banner::query()->count());
    }

    /** @return array<string, array{\Closure(User): array<string, mixed>}> */
    public static function badPhotos(): array
    {
        return [
            'bukan gambar' => [fn (): array => ['mime_type' => 'application/pdf']],
            'terlalu besar' => [fn (): array => ['size' => 6 * 1024 * 1024]],
            'sudah dihapus' => [fn (): array => ['status' => AssetStatus::SoftDeleted]],
            'milik orang lain' => [fn (): array => ['user_id' => User::factory()->create()->id]],
        ];
    }

    #[DataProvider('badPhotos')]
    public function test_foto_yang_tidak_memenuhi_syarat_ditolak(\Closure $attributes): void
    {
        $photo = $this->photo(attributes: $attributes());

        Passport::actingAs($this->admin);

        $this->postJson('/api/v1/admin/banners', $this->payload(['image_asset_id' => $photo->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image_asset_id');
    }

    public function test_satu_foto_tidak_bisa_dipakai_dua_banner_atau_komunitas(): void
    {
        $bannerPhoto = $this->photo();
        Banner::factory()->create(['image' => $bannerPhoto->id]);
        $logo = $this->photo();
        Community::factory()->create(['logo_asset_id' => $logo->id]);

        Passport::actingAs($this->admin);

        foreach ([$bannerPhoto, $logo] as $photo) {
            $this->postJson('/api/v1/admin/banners', $this->payload(['image_asset_id' => $photo->id]))
                ->assertUnprocessable()
                ->assertJsonPath('errors.image_asset_id.0', 'Foto ini sudah dipakai. Unggah ulang fotonya.');
        }

        // Banner yang dihapus menghapus fotonya: komunitas tidak boleh memakainya.
        $this->postJson("/api/v1/communities/{$this->community()->id}/photos", ['asset_id' => $bannerPhoto->id])
            ->assertUnprocessable();
    }

    private function community(): Community
    {
        return Community::factory()->approved()->for($this->admin, 'owner')->create();
    }

    /** @return array<string, array{string}> */
    public static function validLinks(): array
    {
        return [
            'profil' => ['/profile'],
            'daftar komunitas' => ['/communities'],
            'daftarkan komunitas' => ['/communities/new'],
            'https' => ['https://anaklangit.manahpro.web.id/info?a=1'],
            'http' => ['http://anaklangit.manahpro.web.id'],
        ];
    }

    #[DataProvider('validLinks')]
    public function test_tujuan_yang_sah_diterima(string $link): void
    {
        Passport::actingAs($this->admin);

        $this->postJson('/api/v1/admin/banners', $this->payload(['link_url' => $link, 'image_asset_id' => $this->photo()->id]))
            ->assertCreated()
            ->assertJsonPath('data.link_url', $link);
    }

    /** @return array<string, array{string}> */
    public static function invalidLinks(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'data' => ['data:text/html,x'],
            'ftp' => ['ftp://anaklangit.manahpro.web.id/a'],
            'tanpa skema' => ['anaklangit.manahpro.web.id'],
            'skema saja' => ['https://'],
            'domain tersamar' => ['//anaklangit.manahpro.web.id'],
            'halaman admin' => ['/admin'],
            'halaman tak dikenal' => ['/tidak-ada'],
            'id bukan angka' => ['/communities/abc'],
            'id nol' => ['/communities/0'],
            'garis miring akhir' => ['/profile/'],
            'komunitas tak ada' => ['/communities/999999'],
        ];
    }

    #[DataProvider('invalidLinks')]
    public function test_tujuan_yang_tidak_sah_ditolak(string $link): void
    {
        Passport::actingAs($this->admin);

        $this->postJson('/api/v1/admin/banners', $this->payload(['link_url' => $link, 'image_asset_id' => $this->photo()->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('link_url');

        $this->assertSame(0, Banner::query()->count());
    }

    public function test_tujuan_komunitas_harus_terverifikasi(): void
    {
        $approved = Community::factory()->approved()->create(['name' => 'Panahan Nusantara']);
        $pending = Community::factory()->create();

        Passport::actingAs($this->admin);

        $this->postJson('/api/v1/admin/banners', $this->payload(['link_url' => "/communities/{$pending->id}", 'image_asset_id' => $this->photo()->id]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.link_url.0', 'Komunitas tujuan tidak ditemukan atau belum terverifikasi.');

        $this->postJson('/api/v1/admin/banners', $this->payload(['link_url' => "/communities/{$approved->id}", 'image_asset_id' => $this->photo()->id]))
            ->assertCreated()
            ->assertJsonPath('data.link_community.name', 'Panahan Nusantara');
    }

    public function test_ubah_tanpa_foto_baru_mempertahankan_foto_dan_jadwal(): void
    {
        $photo = $this->photo(attributes: ['retain_until' => null]);
        $banner = Banner::factory()->create([
            'image' => $photo->id,
            'link_url' => '/profile',
            'starts_at' => '2026-11-01 00:00:00',
            'ends_at' => '2036-11-30 00:00:00',
            'sort_order' => 4,
        ]);

        Passport::actingAs($this->admin);

        $this->putJson("/api/v1/admin/banners/{$banner->id}", $this->payload([
            'title' => 'Baru',
            'link_url' => 'https://anaklangit.manahpro.web.id/info',
            'is_active' => false,
        ]))
            ->assertOk()
            ->assertJsonPath('data.title', 'Baru')
            ->assertJsonPath('data.subtitle', null)
            ->assertJsonPath('data.link_url', 'https://anaklangit.manahpro.web.id/info')
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.image_url', $photo->url);

        $banner->refresh();
        $this->assertSame($photo->id, $banner->image);
        $this->assertSame(4, $banner->sort_order);
        $this->assertNotNull($banner->starts_at);
        $this->assertNotNull($banner->ends_at);
        $this->assertSame(AssetStatus::Active, $photo->refresh()->status);
    }

    public function test_tujuan_bisa_dikosongkan(): void
    {
        $banner = Banner::factory()->create(['link_url' => '/profile']);

        Passport::actingAs($this->admin);

        $this->putJson("/api/v1/admin/banners/{$banner->id}", $this->payload(['title' => 'Tetap']))
            ->assertOk()
            ->assertJsonPath('data.link_url', null);
    }

    public function test_ubah_tidak_menghapus_kolom_yang_terlewat(): void
    {
        $banner = Banner::factory()->create(['title' => 'Asli', 'link_url' => '/profile']);

        Passport::actingAs($this->admin);

        $this->putJson("/api/v1/admin/banners/{$banner->id}", ['is_active' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'subtitle', 'link_url']);

        $this->assertSame('/profile', $banner->refresh()->link_url);
    }

    public function test_ganti_foto_menghapus_berkas_lama(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('banner/lama.jpg', 'isi');
        $old = $this->photo(attributes: ['storage_type' => StorageType::Local, 'path' => 'banner/lama.jpg', 'retain_until' => null]);
        $new = $this->photo();
        $banner = Banner::factory()->create(['image' => $old->id]);

        Passport::actingAs($this->admin);

        $this->putJson("/api/v1/admin/banners/{$banner->id}", $this->payload(['title' => 'Foto baru', 'image_asset_id' => $new->id]))
            ->assertOk()
            ->assertJsonPath('data.image_url', $new->url);

        $this->assertSame(AssetStatus::HardDeleted, $old->refresh()->status);
        Storage::disk('public')->assertMissing('banner/lama.jpg');
        $this->assertNull($new->refresh()->retain_until);
    }

    public function test_foto_lama_yang_dilindungi_tidak_menggagalkan_penggantian(): void
    {
        $old = $this->photo(attributes: ['is_protected' => true, 'retain_until' => null]);
        $new = $this->photo();
        $banner = Banner::factory()->create(['image' => $old->id]);

        Passport::actingAs($this->admin);

        $this->putJson("/api/v1/admin/banners/{$banner->id}", $this->payload(['image_asset_id' => $new->id]))->assertOk();

        $this->assertSame($new->id, $banner->refresh()->image);
        $this->assertSame(AssetStatus::Active, $old->refresh()->status);
    }

    public function test_foto_pengganti_juga_diperiksa(): void
    {
        $old = $this->photo(attributes: ['retain_until' => null]);
        $banner = Banner::factory()->create(['image' => $old->id]);
        $other = $this->photo(User::factory()->create());

        Passport::actingAs($this->admin);

        $this->putJson("/api/v1/admin/banners/{$banner->id}", $this->payload(['image_asset_id' => $other->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image_asset_id');

        $this->assertSame($old->id, $banner->refresh()->image);
        $this->assertSame(AssetStatus::Active, $old->refresh()->status);
    }

    public function test_hapus_banner_ikut_menghapus_fotonya(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('banner/foto.jpg', 'isi');
        $photo = $this->photo(attributes: ['storage_type' => StorageType::Local, 'path' => 'banner/foto.jpg']);
        $banner = Banner::factory()->create(['image' => $photo->id]);

        Passport::actingAs($this->admin);

        $this->deleteJson("/api/v1/admin/banners/{$banner->id}")->assertOk();

        $this->assertNull(Banner::query()->find($banner->id));
        $this->assertSame(AssetStatus::HardDeleted, $photo->refresh()->status);
        Storage::disk('public')->assertMissing('banner/foto.jpg');
    }

    public function test_urutan_baru_berlaku_di_beranda(): void
    {
        $first = Banner::factory()->create(['title' => 'A', 'sort_order' => 1]);
        $second = Banner::factory()->create(['title' => 'B', 'sort_order' => 2]);
        $third = Banner::factory()->create(['title' => 'C', 'sort_order' => 3, 'is_active' => false]);

        Passport::actingAs($this->admin);

        $response = $this->putJson('/api/v1/admin/banners/order', ['ids' => [$third->id, $second->id, $first->id]])
            ->assertOk()
            ->assertJsonPath('meta.summary.total', 3);

        $this->assertSame(['C', 'B', 'A'], collect($response->json('data'))->pluck('title')->all());

        $public = $this->getJson('/api/v1/banners')->assertOk();
        $this->assertSame(['B', 'A'], collect($public->json('data'))->pluck('title')->all());
    }

    public function test_urutan_basi_ditolak_dan_tidak_mengubah_apa_pun(): void
    {
        $first = Banner::factory()->create(['sort_order' => 1]);
        $second = Banner::factory()->create(['sort_order' => 2]);

        Passport::actingAs($this->admin);

        // Banner kedua terlewat: ditambahkan sesudah daftarnya dibuka.
        $this->putJson('/api/v1/admin/banners/order', ['ids' => [$first->id]])->assertConflict();
        // Banner dalam daftar sudah terhapus.
        $this->putJson('/api/v1/admin/banners/order', ['ids' => [$second->id, $first->id, 999999]])->assertConflict();
        $this->putJson('/api/v1/admin/banners/order', ['ids' => [$second->id, 999999]])->assertConflict();

        $this->assertSame(1, $first->refresh()->sort_order);
        $this->assertSame(2, $second->refresh()->sort_order);
    }

    public function test_urutan_ganda_ditolak(): void
    {
        $banner = Banner::factory()->create();

        Passport::actingAs($this->admin);

        $this->putJson('/api/v1/admin/banners/order', ['ids' => [$banner->id, $banner->id]])
            ->assertUnprocessable();
        $this->putJson('/api/v1/admin/banners/order', ['ids' => []])->assertUnprocessable();
    }

    public function test_ringkasan_admin_panel_memuat_banner(): void
    {
        Banner::factory()->create();
        Banner::factory()->create(['is_active' => false]);

        Passport::actingAs($this->admin);

        $this->getJson('/api/v1/admin/summary')
            ->assertOk()
            ->assertJsonPath('data.banners', ['total' => 2, 'live' => 1, 'max_visible' => 10]);
    }

    public function test_banner_dari_admin_panel_bisa_disunting_di_back_office(): void
    {
        $banner = Banner::factory()->create(['title' => '', 'link_url' => '/communities']);

        $this->actingAs($this->admin);

        Livewire::test(EditBanner::class, ['record' => $banner->getRouteKey()])
            ->fillForm(['subtitle' => 'Disunting'])
            ->call('save')
            ->assertHasNoFormErrors();

        $banner->refresh();
        $this->assertSame('', $banner->title);
        $this->assertSame('Disunting', $banner->subtitle);
        $this->assertSame('/communities', $banner->link_url);
    }

    public function test_back_office_menolak_tujuan_yang_tidak_sah(): void
    {
        $banner = Banner::factory()->create(['link_url' => null]);

        $this->actingAs($this->admin);

        Livewire::test(EditBanner::class, ['record' => $banner->getRouteKey()])
            ->fillForm(['link_url' => 'javascript:alert(1)'])
            ->call('save')
            ->assertHasFormErrors(['link_url']);

        $this->assertNull($banner->refresh()->link_url);
    }
}
