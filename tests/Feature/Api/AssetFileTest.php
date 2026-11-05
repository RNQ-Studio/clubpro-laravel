<?php

namespace Tests\Feature\Api;

use App\Models\Asset;
use App\Models\User;
use App\Support\Enums\AssetStatus;
use App\Support\Enums\StorageType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AssetFileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('public');
        Storage::fake('gcs');
    }

    public function test_tanpa_gcs_unggahan_tersimpan_di_server_dan_bisa_dibuka(): void
    {
        config(['filesystems.assets' => 'public']);
        Passport::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/assets/upload', [
            'file' => UploadedFile::fake()->image('logo.jpg', 200, 200),
            'type' => 'community-logo',
        ])->assertCreated();

        $asset = Asset::query()->findOrFail($response->json('data.id'));

        $this->assertSame(StorageType::Local, $asset->storage_type);
        $this->assertNull($asset->url);
        Storage::disk('public')->assertExists($asset->path);
        Storage::disk('gcs')->assertMissing($asset->path);
        $this->assertSame(route('assets.file', $asset), $response->json('data.public_url'));

        $file = $this->get('/api/v1/assets/'.$asset->id.'/file')->assertOk();
        $this->assertSame('image/jpeg', $file->headers->get('Content-Type'));
        $this->assertStringContainsString('immutable', (string) $file->headers->get('Cache-Control'));
    }

    public function test_dengan_gcs_unggahan_tetap_ke_gcs(): void
    {
        config(['filesystems.assets' => 'gcs']);
        Passport::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/assets/upload', [
            'file' => UploadedFile::fake()->image('logo.jpg'),
            'type' => 'community-logo',
        ])->assertCreated();

        $asset = Asset::query()->findOrFail($response->json('data.id'));

        $this->assertSame(StorageType::Gcs, $asset->storage_type);
        Storage::disk('gcs')->assertExists($asset->path);
        $this->get('/api/v1/assets/'.$asset->id.'/file')->assertNotFound();
    }

    public function test_svg_tidak_disajikan_dari_domain_server(): void
    {
        Storage::disk('public')->put('x/jahat.svg', '<svg onload="alert(1)"/>');
        $asset = $this->localAsset(['path' => 'x/jahat.svg', 'mime_type' => 'image/svg+xml']);

        $this->get('/api/v1/assets/'.$asset->id.'/file')->assertNotFound();
    }

    public function test_dokumen_tidak_disajikan(): void
    {
        Storage::disk('public')->put('x/rahasia.pdf', 'pdf');
        $asset = $this->localAsset(['path' => 'x/rahasia.pdf', 'mime_type' => 'application/pdf']);

        $this->get('/api/v1/assets/'.$asset->id.'/file')->assertNotFound();
    }

    public function test_aset_terhapus_tidak_disajikan(): void
    {
        Storage::disk('public')->put('x/a.jpg', 'jpg');
        $asset = $this->localAsset(['path' => 'x/a.jpg', 'mime_type' => 'image/jpeg']);
        $asset->status = AssetStatus::SoftDeleted;
        $asset->save();

        $this->get('/api/v1/assets/'.$asset->id.'/file')->assertNotFound();
    }

    public function test_id_bukan_uuid_404_bukan_galat_database(): void
    {
        $this->get('/api/v1/assets/bukan-uuid/file')->assertNotFound();
    }

    /** @param  array<string, mixed>  $attributes */
    private function localAsset(array $attributes): Asset
    {
        return Asset::factory()->create([
            'storage_type' => StorageType::Local,
            'url' => null,
            ...$attributes,
        ]);
    }
}
