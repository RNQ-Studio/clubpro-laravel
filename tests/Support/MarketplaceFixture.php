<?php

namespace Tests\Support;

use App\Models\Asset;
use App\Models\MarketplaceProduct;
use App\Models\User;
use App\Services\MarketplaceService;
use App\Support\Enums\StorageType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Shares the real single-wallet/custody funding fixture from S05. */
trait MarketplaceFixture
{
    use PayoutFixture;

    private function market(): MarketplaceService
    {
        return app(MarketplaceService::class);
    }

    private function productPhoto(?User $owner = null): Asset
    {
        $owner ??= $this->recipient;
        $photo = Asset::factory()->create(['user_id' => $owner->id, 'url' => null, 'storage_type' => StorageType::Local, 'path' => 'marketplace/'.Str::uuid().'.png', 'mime_type' => 'image/png', 'size' => 64, 'category' => 'marketplace-product']);
        $file = UploadedFile::fake()->image('fixture.png', 4, 4);
        Storage::disk('public')->put($photo->path, file_get_contents($file->getRealPath()));

        return $photo;
    }

    /** @return array<string,mixed> */
    private function productData(?Asset $photo = null, int $stock = 2, int $price = 40000): array
    {
        $photo ??= $this->productPhoto();

        return ['community_id' => $this->community->id, 'name' => 'Raket Fixture', 'description' => 'Barang sintetis untuk pengujian.', 'photo_asset_id' => $photo->id, 'price' => $price, 'stock' => $stock, 'is_active' => true];
    }

    private function product(int $stock = 2, int $price = 40000): MarketplaceProduct
    {
        return $this->market()->save($this->recipient, $this->productData(stock: $stock, price: $price));
    }

    /** @return array<string,mixed> */
    private function checkoutData(MarketplaceProduct $product, int $quantity = 1, ?string $key = null): array
    {
        return ['product_id' => $product->id, 'quantity' => $quantity, 'expected_version' => $product->version, 'expected_unit_price' => $product->price, 'pin' => $this->fixturePin, 'idempotency_key' => $key ?? (string) Str::uuid(), 'acknowledged' => true];
    }
}
