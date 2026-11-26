<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceProduct;
use App\Models\MemberIdentity;
use App\Models\User;
use App\Support\Enums\AssetStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Marketplace uses the original available wallet and custody lots; no commission or refund. */
class MarketplaceService
{
    public function __construct(private readonly LedgerService $ledger, private readonly MoneyMovementService $movement, private readonly ImageAssetGuard $images) {}

    public function sellerVisible(MemberIdentity $identity): bool
    {
        return $identity->merged_into_id === null && $identity->is_public && $identity->user !== null && $identity->user->is_active
            && ($identity->birth_date === null || $identity->birth_date->age >= 18 || $identity->guardianGrants()->whereNotNull('verified_at')->whereNull('revoked_at')->exists());
    }

    public function eligible(MarketplaceProduct $product): bool
    {
        $member = CommunityMember::query()->with('community')->where('community_id', $product->community_id)->where('member_identity_id', $product->seller_identity_id)->whereNull('merged_into_member_id')->first();

        return $product->is_active && $this->sellerVisible($product->seller) && $member !== null && $this->movement->activeMember($member);
    }

    /** @param array<string,mixed> $filters @return LengthAwarePaginator<int,MarketplaceProduct> */
    public function catalogue(array $filters): LengthAwarePaginator
    {
        $query = MarketplaceProduct::query()->with(['seller.user', 'community', 'photo'])->where('is_active', true)
            ->when($filters['community_id'] ?? null, fn (Builder $q, $id) => $q->where('community_id', $id))
            ->when($filters['seller_identity_id'] ?? null, fn (Builder $q, $id) => $q->where('seller_identity_id', $id))
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->whereRaw('lower(name || \' \' || description) like ?', ['%'.addcslashes(mb_strtolower(trim($search)), '\\%_').'%']))
            ->orderByDesc('created_at')->orderBy('id');
        // Stream bounded chunks: exact live-entitlement totals without loading the whole catalogue.
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 20);
        $offset = ($page - 1) * $perPage;
        $items = [];
        $total = 0;
        $eligibility = [];
        foreach ($query->lazy(100) as $product) {
            $key = $product->seller_identity_id.':'.$product->community_id;
            $eligibility[$key] ??= $this->eligible($product);
            if (! $eligibility[$key]) {
                continue;
            }
            if ($total >= $offset && count($items) < $perPage) {
                $items[] = $product;
            }
            $total++;
        }

        return new LengthAwarePaginator($items, $total, $perPage, $page);
    }

    public function publicProduct(MarketplaceProduct $product): MarketplaceProduct
    {
        $product->load(['seller.user', 'community', 'photo']);
        abort_unless($this->eligible($product), 404, 'Produk tidak tersedia atau penjual tidak aktif.');

        return $product;
    }

    /** @param array<string,mixed> $data */
    public function save(User $actor, array $data, ?MarketplaceProduct $product = null): MarketplaceProduct
    {
        return DB::transaction(function () use ($actor, $data, $product): MarketplaceProduct {
            $identity = MemberIdentity::forUser($actor);
            $this->ledger->lockOperation('marketplace-seller:'.$identity->id);
            MemberIdentity::query()->whereKey($identity->id)->lockForUpdate()->firstOrFail();
            if ($product !== null) {
                $product = MarketplaceProduct::query()->lockForUpdate()->findOrFail($product->id);
                abort_unless($product->user_id === $actor->id && $product->seller_identity_id === $identity->id, 403, 'Hanya pemilik dapat mengubah produk.');
                Gate::forUser($actor)->authorize('update', $product);
                abort_unless($product->version === (int) $data['expected_version'], 409, 'Produk berubah. Muat ulang sebelum mengedit.');
            }
            $active = (bool) $data['is_active'];
            $community = Community::query()->findOrFail($data['community_id']);
            if ($active) {
                $this->movement->requireMember($actor, $community, true);
                abort_unless($this->sellerVisible($identity), 422, 'Aktifkan profil publik yang memenuhi persetujuan privasi sebelum membuka lapak.');
                $count = MarketplaceProduct::query()->where('seller_identity_id', $identity->id)->where('is_active', true)->when($product !== null, fn (Builder $q) => $q->where('id', '<>', $product->id))->count();
                abort_if($count >= 5, 422, 'Lapak maksimal lima barang aktif. Nonaktifkan satu barang terlebih dahulu.');
            } else {
                // Expired sellers can always withdraw an existing listing.
                abort_unless($actor->is_active && $identity->merged_into_id === null, 403);
                if ($product === null || $product->community_id !== $community->id) {
                    $this->movement->requireMember($actor, $community);
                }
            }
            $price = $data['price'];
            abort_unless(is_int($price) && is_int($data['stock']), 422, 'Harga dan stok harus bilangan bulat.');
            $this->ledger->assertAmount($price);
            abort_unless($data['stock'] >= 0 && $data['stock'] <= 1000000, 422, 'Stok tidak valid.');
            $asset = Asset::query()->lockForUpdate()->findOrFail($data['photo_asset_id']);
            if ($product === null || $product->photo_asset_id !== $asset->id) {
                $this->images->assertUsable($actor, [$asset->id], 'photo_asset_id');
                abort_unless(Storage::disk($asset->storage_type->disk())->exists($asset->path), 422, 'Berkas foto hilang. Unggah ulang foto.');
                $dimensions = @getimagesizefromstring(Storage::disk($asset->storage_type->disk())->get($asset->path));
                abort_unless($dimensions !== false && $dimensions[0] > 0 && $dimensions[1] > 0 && $dimensions[0] <= 16000 && $dimensions[1] <= 16000 && in_array($dimensions[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true), 422, 'Berkas foto tidak valid. Unggah foto JPG, PNG, atau WebP yang dapat dibaca.');
                $asset->update(['is_protected' => true, 'retain_until' => null]);
            }
            $oldAsset = $product?->photo_asset_id;
            $product ??= new MarketplaceProduct;
            $product->fill(['seller_identity_id' => $identity->id, 'user_id' => $actor->id, 'community_id' => $community->id, 'name' => trim($data['name']), 'description' => trim($data['description']), 'photo_asset_id' => $asset->id, 'price' => $price, 'stock' => $data['stock'], 'is_active' => $active, 'version' => ($product->version ?? 0) + 1])->save();
            if ($oldAsset !== null && $oldAsset !== $asset->id && ! MarketplaceOrder::query()->where('snapshot->product->photo_asset_id', $oldAsset)->exists()) {
                $previous = Asset::query()->lockForUpdate()->find($oldAsset);
                if ($previous !== null) {
                    $previous->update(['is_protected' => false]);
                    $previous->markAsSoftDeleted();
                }
            }

            return $product->load(['seller.user', 'community', 'photo']);
        }, 3);
    }

    /** @return array<string,mixed> */
    public function quote(User $actor, MarketplaceProduct $product, int $quantity): array
    {
        $this->assertBuyer($actor);
        $this->publicProduct($product);
        $this->assertPurchase($actor, $product, $quantity);

        return ['product' => $this->productData($product), 'quantity' => $quantity, 'unit_price' => $product->price, 'total' => $this->total($product, $quantity), 'product_version' => $product->version, 'available_balance' => $this->ledger->wallet($actor)['available'], 'no_refund' => true];
    }

    /** @param array<string,mixed> $data */
    public function checkout(User $actor, #[\SensitiveParameter] array $data): MarketplaceOrder
    {
        $pin = (string) $data['pin'];
        unset($data['pin']);
        $hash = $this->ledger->payloadHash($data);

        return DB::transaction(function () use ($actor, $data, $pin, $hash): MarketplaceOrder {
            $key = $data['idempotency_key'];
            $this->ledger->lockOperation('marketplace-order:'.$actor->id.':'.$key);
            $existing = MarketplaceOrder::query()->where('user_id', $actor->id)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                abort_unless(hash_equals($existing->payload_hash, $hash), 409, 'Kunci transaksi sudah dipakai untuk data berbeda.');

                return $existing;
            }
            $this->assertBuyer($actor);
            $this->movement->verifyPin($actor, $pin);
            $product = MarketplaceProduct::query()->with(['seller.user', 'community', 'photo'])->lockForUpdate()->findOrFail($data['product_id']);
            $this->publicProduct($product);
            abort_unless($product->version === $data['expected_version'] && $product->price === $data['expected_unit_price'], 409, 'Harga atau rincian produk berubah. Periksa ulang sebelum membeli.');
            abort_unless(($data['acknowledged'] ?? false) === true, 422, 'Konfirmasi ketentuan pembelian final tanpa refund.');
            $quantity = $data['quantity'];
            abort_unless(is_int($quantity), 422, 'Jumlah harus bilangan bulat.');
            $this->assertPurchase($actor, $product, $quantity);
            $buyer = MemberIdentity::forUser($actor);
            $total = $this->total($product, $quantity);
            $id = (string) Str::uuid();
            $snapshot = ['product' => ['id' => $product->id, 'name' => $product->name, 'description' => $product->description, 'photo_asset_id' => $product->photo_asset_id, 'photo_url' => $this->photoUrl($product), 'version' => $product->version], 'seller' => ['identity_id' => $product->seller_identity_id, 'user_id' => $product->user_id, 'name' => $product->seller->name], 'buyer' => ['identity_id' => $buyer->id, 'user_id' => $actor->id, 'name' => $buyer->name], 'community' => ['id' => $product->community_id, 'name' => $product->community->name, 'slug' => null]];
            $journal = $this->ledger->transfer($actor, 'marketplace:'.$id, 'marketplace_purchase', $this->ledger->walletAccount($buyer), $this->ledger->walletAccount($product->seller), $total, $product->community, ['reference' => $id]);
            $product->decrement('stock', $quantity); // Quantity changes do not invalidate the agreed product content version.

            return MarketplaceOrder::query()->create(['id' => $id, 'product_id' => $product->id, 'user_id' => $actor->id, 'buyer_identity_id' => $buyer->id, 'seller_identity_id' => $product->seller_identity_id, 'community_id' => $product->community_id, 'journal_id' => $journal->id, 'quantity' => $quantity, 'unit_price' => $product->price, 'total' => $total, 'status' => 'succeeded', 'idempotency_key' => $key, 'payload_hash' => $hash, 'snapshot' => $snapshot, 'created_at' => now()]);
        }, 3);
    }

    private function assertBuyer(User $actor): void
    {
        $identity = MemberIdentity::forUser($actor);
        abort_unless($actor->is_active && $identity->merged_into_id === null, 403, 'Akun pembeli tidak aktif.');
    }

    private function assertPurchase(User $actor, MarketplaceProduct $product, int $quantity): void
    {
        abort_if(MemberIdentity::forUser($actor)->id === $product->seller_identity_id, 422, 'Tidak dapat membeli produk sendiri.');
        abort_unless($quantity > 0 && $quantity <= $product->stock, 422, 'Stok barang tidak mencukupi.');
        $this->total($product, $quantity);
    }

    private function total(MarketplaceProduct $product, int $quantity): int
    {
        abort_unless($quantity > 0 && $quantity <= intdiv((int) config('finance.max_transaction'), $product->price), 422, 'Total pembelian melebihi batas transaksi.');

        return $product->price * $quantity;
    }

    private function photoUrl(MarketplaceProduct $product): ?string
    {
        $photo = $product->photo;

        return $photo !== null && $photo->status === AssetStatus::Active && ! $photo->isSensitive() && Storage::disk($photo->storage_type->disk())->exists($photo->path) ? $photo->getPublicUrl() : null;
    }

    /** @return array<string,mixed> */
    public function productData(MarketplaceProduct $product): array
    {
        $eligible = $this->eligible($product);

        return ['id' => $product->id, 'name' => $product->name, 'description' => $product->description, 'photo_asset_id' => $product->photo_asset_id, 'photo_url' => $this->photoUrl($product), 'price' => $product->price, 'stock' => $product->stock, 'is_active' => $product->is_active, 'version' => $product->version, 'can_buy' => $eligible && $product->stock > 0, 'unavailable_reason' => ! $eligible ? 'Produk atau penjual tidak aktif.' : ($product->stock < 1 ? 'Stok habis.' : null), 'seller' => ['identity_id' => $product->seller_identity_id, 'name' => $product->seller->name], 'community' => ['id' => $product->community_id, 'name' => $product->community->name, 'slug' => null], 'created_at' => $product->created_at->toIso8601String()];
    }
}
