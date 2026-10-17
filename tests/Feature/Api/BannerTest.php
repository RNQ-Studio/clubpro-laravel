<?php

namespace Tests\Feature\Api;

use App\Filament\Resources\Banners\BannerResource;
use App\Models\Asset;
use App\Models\Banner;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_list_returns_only_visible_banners_in_order(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-17 10:00:00'));

        Banner::factory()->create(['title' => 'Kedua', 'sort_order' => 2]);
        Banner::factory()->create(['title' => 'Pertama', 'sort_order' => 1, 'link_url' => 'https://anaklangit.manahpro.web.id/articles']);
        Banner::factory()->create(['title' => 'Nonaktif', 'is_active' => false]);
        Banner::factory()->create(['title' => 'Belum tayang', 'starts_at' => '2026-10-18 00:00:00']);
        Banner::factory()->create(['title' => 'Sudah lewat', 'ends_at' => '2026-10-16 23:59:59']);
        Banner::factory()->create(['title' => 'Dalam jadwal', 'sort_order' => 3, 'starts_at' => '2026-10-01', 'ends_at' => '2026-10-31']);

        $response = $this->getJson('/api/v1/banners')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => [['id', 'title', 'subtitle', 'image_url', 'link_url']]]);

        $this->assertSame(['Pertama', 'Kedua', 'Dalam jadwal'], collect($response->json('data'))->pluck('title')->all());
        $this->assertSame('https://anaklangit.manahpro.web.id/articles', $response->json('data.0.link_url'));
    }

    public function test_banner_image_url_comes_from_asset(): void
    {
        $asset = Asset::factory()->create(['url' => 'https://storage.googleapis.com/bucket/production/banner/banner.jpg']);
        Banner::factory()->create(['image' => $asset->id]);

        $this->getJson('/api/v1/banners')
            ->assertOk()
            ->assertJsonPath('data.0.image_url', 'https://storage.googleapis.com/bucket/production/banner/banner.jpg');
    }

    public function test_empty_list_is_ok(): void
    {
        $this->getJson('/api/v1/banners')->assertOk()->assertJsonPath('data', []);
    }

    public function test_admin_can_manage_banners_in_back_office_and_staff_cannot(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $banner = Banner::factory()->create();

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->get(BannerResource::getUrl('index'))->assertOk();
        $this->actingAs($admin)->get(BannerResource::getUrl('create'))->assertOk();
        $this->actingAs($admin)->get(BannerResource::getUrl('edit', ['record' => $banner]))->assertOk();

        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $this->actingAs($staff)->get(BannerResource::getUrl('index'))->assertForbidden();
    }
}
