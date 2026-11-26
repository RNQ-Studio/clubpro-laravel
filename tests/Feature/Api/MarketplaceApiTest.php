<?php

namespace Tests\Feature\Api;

use App\Models\Asset;
use App\Models\CommunityMember;
use App\Models\MarketplaceOrder;
use App\Models\MemberIdentity;
use App\Models\User;
use App\Services\AssetDeletionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use RuntimeException;
use Tests\Support\MarketplaceFixture;
use Tests\TestCase;

class MarketplaceApiTest extends TestCase
{
    use MarketplaceFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
    }

    public function test_public_catalogue_scopes_search_paging_privacy_and_sold_out(): void
    {
        $product = $this->product(0);
        $this->getJson('/api/v1/public/products')->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.can_buy', false)->assertJsonMissingPath('data.items.0.seller.user_id')->assertJsonMissingPath('data.items.0.seller.email')->assertJsonMissingPath('data.items.0.community.dues_account_number');
        $this->getJson('/api/v1/public/products?community_id='.$this->community->id.'&seller_identity_id='.$product->seller_identity_id.'&search=raket&per_page=1')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/v1/public/products?search=absent')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/v1/public/products?page=2&per_page=1')->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson('/api/v1/public/products/'.$product->id)->assertOk()->assertJsonPath('data.unavailable_reason', 'Stok habis.');
        MemberIdentity::query()->whereKey($product->seller_identity_id)->update(['is_public' => false]);
        $this->getJson('/api/v1/public/products')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/v1/public/products/'.$product->id)->assertNotFound();
        Passport::actingAs($this->recipient);
        $this->getJson('/api/v1/marketplace/products/'.$product->id)->assertOk();
    }

    public function test_sixth_active_global_listing_rejected_reactivation_and_other_owner_forbidden(): void
    {
        $products = [];
        for ($i = 0; $i < 5; $i++) {
            $products[] = $this->product();
        }
        Passport::actingAs($this->recipient);
        $this->getJson('/api/v1/marketplace/products')->assertOk()->assertJsonPath('data.active_count', 5)->assertJsonPath('data.active_limit', 5);
        $this->getJson('/api/v1/marketplace/products?search=absent')->assertOk()->assertJsonPath('data.active_count', 5)->assertJsonCount(0, 'data.items');
        $data = $this->productData();
        $this->postJson('/api/v1/marketplace/products', $data)->assertUnprocessable();
        $first = $products[0];
        $edit = [...$this->productData(Asset::query()->findOrFail($first->photo_asset_id)), 'expected_version' => 1, 'is_active' => false];
        $this->putJson('/api/v1/marketplace/products/'.$first->id, $edit)->assertOk();
        $this->postJson('/api/v1/marketplace/products', $data)->assertCreated();
        $this->putJson('/api/v1/marketplace/products/'.$first->id, [...$edit, 'expected_version' => 2, 'is_active' => true])->assertUnprocessable();
        foreach ([$this->sender, $this->founder, $this->admin] as $other) {
            Passport::actingAs($other);
            $this->putJson('/api/v1/marketplace/products/'.$first->id, $edit)->assertForbidden();
        }
        $super = User::factory()->create();
        $super->assignRole('super-admin');
        Passport::actingAs($super);
        $this->putJson('/api/v1/marketplace/products/'.$first->id, $edit)->assertForbidden();
    }

    public function test_checkout_atomically_moves_existing_wallet_stocks_snapshot_history_and_retry(): void
    {
        $product = $this->product();
        $data = $this->checkoutData($product);
        Passport::actingAs($this->sender);
        $this->postJson('/api/v1/marketplace/quote', ['product_id' => $product->id, 'quantity' => 1])->assertOk()->assertJsonPath('data.total', 40000)->assertJsonPath('data.available_balance', 100000)->assertJsonPath('data.no_refund', true);
        $response = $this->postJson('/api/v1/marketplace/orders', $data)->assertCreated()->assertJsonPath('data.status', 'succeeded');
        $id = $response->json('data.id');
        $this->postJson('/api/v1/marketplace/orders', [...$data, 'pin' => str_repeat('0', 6)])->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/marketplace/orders', [...$data, 'quantity' => 2])->assertConflict();
        $this->getJson('/api/v1/marketplace/orders/by-key/'.$data['idempotency_key'])->assertOk()->assertJsonPath('data.id', $id);
        $this->getJson('/api/v1/marketplace/orders')->assertOk()->assertJsonPath('data.total', 1);
        $this->assertSame(1, $product->fresh()->stock);
        $this->assertSame(60000, $this->ledger->wallet($this->sender)['available']);
        $this->assertSame(40000, $this->ledger->wallet($this->recipient)['available']);
        $this->assertSame(2, DB::table('ledger_entries')->where('journal_id', $response->json('data.journal_id'))->count());
        $this->assertTrue($this->ledger->reconcile()['balanced']);
        Passport::actingAs($this->recipient);
        $this->getJson('/api/v1/marketplace/orders?direction=sales')->assertOk()->assertJsonPath('data.items.0.idempotency_key', null);
        $this->getJson('/api/v1/marketplace/orders/'.$id)->assertOk();
        $new = $this->productPhoto();
        $this->putJson('/api/v1/marketplace/products/'.$product->id, [...$this->productData($new, price: 80000), 'expected_version' => 1])->assertOk();
        $this->getJson('/api/v1/marketplace/orders/'.$id)->assertOk()->assertJsonPath('data.unit_price', 40000)->assertJsonPath('data.snapshot.product.photo_asset_id', $product->photo_asset_id);
        $this->assertTrue(Asset::query()->findOrFail($product->photo_asset_id)->is_protected);
        Passport::actingAs($this->admin);
        $this->getJson('/api/v1/marketplace/orders/'.$id)->assertForbidden();
        $this->getJson('/api/v1/marketplace/orders/by-key/'.$data['idempotency_key'])->assertNotFound();
        $this->postJson('/api/v1/marketplace/orders/'.$id.'/refund')->assertNotFound();
    }

    public function test_stale_price_insufficient_stock_bad_pin_no_ack_and_insufficient_balance_leave_no_debit(): void
    {
        $product = $this->product();
        Passport::actingAs($this->sender);
        $valid = $this->checkoutData($product);
        foreach ([['expected_unit_price' => 1], ['quantity' => 3], ['pin' => str_repeat('0', 6)], ['acknowledged' => false]] as $bad) {
            $this->postJson('/api/v1/marketplace/orders', [...$valid, ...$bad])->assertStatus(isset($bad['expected_unit_price']) ? 409 : 422);
        }
        $expensive = $this->product(2, 100001);
        $this->postJson('/api/v1/marketplace/orders', $this->checkoutData($expensive))->assertUnprocessable();
        $this->assertSame(0, MarketplaceOrder::query()->count());
        $this->assertSame(100000, $this->ledger->wallet($this->sender)['available']);
        $this->assertSame(0, $this->ledger->wallet($this->recipient)['available']);
        $this->assertSame(2, $product->fresh()->stock);
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    public function test_buyer_need_not_join_seller_community_or_have_current_membership_but_seller_must_be_active(): void
    {
        $product = $this->product();
        CommunityMember::query()->where('user_id', $this->sender->id)->update(['status' => 'archived']);
        Passport::actingAs($this->sender);
        $this->postJson('/api/v1/marketplace/orders', $this->checkoutData($product))->assertCreated();
        CommunityMember::query()->where('user_id', $this->recipient->id)->update(['status' => 'archived']);
        $this->postJson('/api/v1/marketplace/orders', $this->checkoutData($product))->assertNotFound();
        Passport::actingAs($this->recipient);
        $this->putJson('/api/v1/marketplace/products/'.$product->id, [...$this->productData(Asset::query()->findOrFail($product->photo_asset_id)), 'expected_version' => 1, 'is_active' => false])->assertOk();
    }

    public function test_error_after_ledger_posting_rolls_back_stock_wallet_lots_and_order_then_retry_succeeds(): void
    {
        $product = $this->product();
        $before = $this->moneyState();
        $data = $this->checkoutData($product);
        MarketplaceOrder::creating(fn () => throw new RuntimeException('Injected storage failure after ledger transfer'));
        try {
            $this->market()->checkout($this->sender, $data);
            $this->fail('Expected injected storage error');
        } catch (RuntimeException $error) {
            $this->assertSame('Injected storage failure after ledger transfer', $error->getMessage());
        } finally {
            MarketplaceOrder::flushEventListeners();
        }
        $this->assertSame($before, $this->moneyState());
        $this->assertSame(2, $product->fresh()->stock);
        $this->assertSame(0, MarketplaceOrder::query()->count());
        $this->market()->checkout($this->sender, $data);
        $this->assertSame(1, MarketplaceOrder::query()->count());
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }

    public function test_photo_upload_invalid_reuse_missing_replacement_and_immutable_final_no_refund(): void
    {
        Passport::actingAs($this->recipient);
        foreach ([UploadedFile::fake()->create('bad.svg', 10, 'image/svg+xml'), UploadedFile::fake()->image('big.png')->size(5121)] as $file) {
            $this->postJson('/api/v1/assets/upload', ['type' => 'marketplace-product', 'file' => $file])->assertUnprocessable();
        }
        $photo = $this->postJson('/api/v1/assets/upload', ['type' => 'marketplace-product', 'file' => UploadedFile::fake()->image('valid.png', 8, 8)])->assertCreated()->json('data.id');
        $product = $this->market()->save($this->recipient, $this->productData(Asset::query()->findOrFail($photo)));
        $this->postJson('/api/v1/marketplace/products', $this->productData(Asset::query()->findOrFail($photo)))->assertUnprocessable();
        $order = $this->market()->checkout($this->sender, $this->checkoutData($product));
        $asset = Asset::query()->findOrFail($photo);
        $asset->update(['is_protected' => false]);
        $this->assertFalse($asset->markAsSoftDeleted());
        try {
            app(AssetDeletionService::class)->hardDelete($asset);
            $this->fail('Referenced photo must survive');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }
        Storage::disk($asset->storage_type->disk())->delete($asset->path);
        $this->getJson('/api/v1/public/products/'.$product->id)->assertOk()->assertJsonPath('data.photo_url', null);
        $invalid = $this->productPhoto();
        Storage::disk('public')->put($invalid->path, 'invalid raster bytes with trusted metadata');
        $this->postJson('/api/v1/marketplace/products', $this->productData($invalid))->assertUnprocessable();
        $missing = $this->productPhoto();
        Storage::disk('public')->delete($missing->path);
        $this->postJson('/api/v1/marketplace/products', $this->productData($missing))->assertUnprocessable();
        DB::beginTransaction();
        try {
            DB::table('marketplace_orders')->where('id', $order->id)->update(['total' => 1]);
            $this->fail('Order must be immutable');
        } catch (QueryException $error) {
            $this->assertStringContainsString('immutable', $error->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    public function test_guest_mutations_rejected_and_migration_replay_preserves_final_orders(): void
    {
        $product = $this->product();
        $this->postJson('/api/v1/marketplace/orders', $this->checkoutData($product))->assertUnauthorized();
        $order = $this->market()->checkout($this->sender, $this->checkoutData($product));
        $migration = require database_path('migrations/2026_11_26_070001_create_marketplace.php');
        $migration->up();
        $migration->up();
        $this->assertEquals($order->snapshot, $order->fresh()->snapshot);
        $this->assertTrue($this->ledger->reconcile()['balanced']);
    }
}
