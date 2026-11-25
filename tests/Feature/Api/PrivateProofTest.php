<?php

namespace Tests\Feature\Api;

use App\Http\Resources\Api\V1\AssetResource;
use App\Models\Asset;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\DuesPayment;
use App\Models\User;
use App\Services\ImageAssetGuard;
use App\Services\SensitiveAssetService;
use App\Support\Enums\StorageType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;
use Tests\TestCase;

class PrivateProofTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        Storage::fake('gcs');
    }

    public function test_new_proofs_are_private_even_when_public_gcs_is_configured(): void
    {
        config(['filesystems.assets' => 'gcs']);
        $owner = User::factory()->create();
        Passport::actingAs($owner);
        $response = $this->postJson('/api/v1/assets/upload', [
            'file' => UploadedFile::fake()->image('proof.jpg'), 'type' => 'dues-proof',
        ])->assertCreated()->assertJsonPath('data.public_url', null);

        $asset = Asset::query()->findOrFail($response->json('data.id'));
        $this->assertSame(StorageType::Private, $asset->storage_type);
        Storage::disk('local')->assertExists($asset->path);
        Storage::disk('gcs')->assertMissing($asset->path);
        $this->getJson(route('assets.file', $asset))->assertForbidden();
    }

    public function test_legacy_bytes_are_moved_and_cannot_be_recovered_from_old_public_path(): void
    {
        foreach ([StorageType::Local, StorageType::Gcs] as $type) {
            $asset = Asset::factory()->create(['storage_type' => $type, 'category' => 'dues-proof']);
            $oldPath = $asset->path;
            Storage::disk($type->disk())->put($oldPath, 'confidential');
            $this->assertNull($asset->getPublicUrl());
            app(SensitiveAssetService::class)->privatize($asset);
            app(SensitiveAssetService::class)->privatize($asset->fresh());
            Storage::disk($type->disk())->assertMissing($oldPath);
            $this->assertSame('confidential', Storage::disk('local')->get($asset->path));
            $this->assertNull($asset->url);
        }
    }

    public function test_only_owner_or_scoped_active_admin_gets_a_five_minute_capability(): void
    {
        $owner = User::factory()->create();
        $community = Community::factory()->approved()->create();
        $otherCommunity = Community::factory()->approved()->create();
        $asset = Asset::factory()->create(['storage_type' => StorageType::Private, 'user_id' => $owner->id]);
        Storage::disk('local')->put($asset->path, 'private-image');
        $payment = new DuesPayment(['months_count' => 1, 'months' => ['2026-11'], 'monthly_amount' => 100, 'entry_fee' => 0, 'total_amount' => 100, 'proof_asset_id' => $asset->id]);
        $payment->user_id = $owner->id;
        $community->duesPayments()->save($payment);
        $outsider = User::factory()->create();
        CommunityMember::factory()->for($otherCommunity)->for($outsider)->admin()->create();
        $coach = User::factory()->create();
        CommunityMember::factory()->for($community)->for($coach)->create(['role' => 'coach']);
        $service = app(SensitiveAssetService::class);

        $this->assertNull($service->viewUrl($asset, null));
        $this->assertNull($service->viewUrl($asset, $outsider));
        $this->assertNull($service->viewUrl($asset, $coach));
        $this->assertNotNull($service->viewUrl($asset, $community->owner));
        $url = $service->viewUrl($asset, $owner);
        $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->get($url.'&altered=1')->assertForbidden();
        $this->travel(6)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_public_asset_resource_never_exposes_a_payment_proof_url(): void
    {
        $asset = Asset::factory()->create(['category' => 'dues-proof']);
        $data = (new AssetResource($asset))->resolve();
        $this->assertNull($data['public_url']);
    }

    public function test_migration_retries_include_trashed_proofs_and_preserve_bytes(): void
    {
        $asset = Asset::factory()->create(['storage_type' => StorageType::Local, 'category' => 'dues-proof']);
        $oldPath = $asset->path;
        Storage::disk('public')->put($oldPath, 'legacy-proof');
        $asset->delete();
        $migration = require database_path('migrations/2026_11_26_000003_privatize_existing_payment_proofs.php');
        $migration->up();
        $migration->up();
        $asset = Asset::withTrashed()->findOrFail($asset->id);
        $this->assertSame(StorageType::Private, $asset->storage_type);
        $this->assertSame('legacy-proof', Storage::disk('local')->get($asset->path));
        Storage::disk('public')->assertMissing($oldPath);
    }

    public function test_private_proofs_cannot_be_reused_as_public_community_photos(): void
    {
        $owner = User::factory()->create();
        $asset = Asset::factory()->create(['storage_type' => StorageType::Private, 'user_id' => $owner->id]);
        $this->expectException(ValidationException::class);
        app(ImageAssetGuard::class)->assertUsable($owner, [$asset->id], 'logo_asset_id');
    }
}
