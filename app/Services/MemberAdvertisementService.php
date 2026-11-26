<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\MarketplaceProduct;
use App\Models\MemberAdvertisement;
use App\Models\MemberIdentity;
use App\Models\User;
use App\Policies\MemberAdvertisementPolicy;
use App\Support\Enums\AssetStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MemberAdvertisementService
{
    public function __construct(private readonly LedgerService $locks, private readonly MoneyMovementService $movement, private readonly MarketplaceService $market, private readonly ImageAssetGuard $images) {}

    public function visible(MemberAdvertisement $ad): bool
    {
        $identity = MemberIdentity::query()->with('user')->findOrFail($ad->seller_identity_id);
        $member = CommunityMember::query()->where('community_id', $ad->community_id)->where('member_identity_id', $identity->id)->whereNull('merged_into_member_id')->first();
        if (! $ad->is_active || ! $this->market->sellerVisible($identity) || $member === null || ! $this->movement->activeMember($member)) {
            return false;
        }
        if ($ad->target_product_id !== null) {
            $product = MarketplaceProduct::query()->find($ad->target_product_id);
            if ($product === null || ! $this->market->eligible($product)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $data */
    public function save(User $actor, array $data, ?MemberAdvertisement $ad = null): MemberAdvertisement
    {
        return DB::transaction(function () use ($actor, $data, $ad): MemberAdvertisement {
            $identity = MemberIdentity::forUser($actor);
            $this->locks->lockOperation('advertisement-owner:'.$identity->id);
            if ($ad !== null) {
                $ad = MemberAdvertisement::query()->lockForUpdate()->findOrFail($ad->id);
                $this->assertOwner($actor, $ad);
                abort_unless($ad->version === (int) $data['expected_version'], 409, 'Iklan berubah. Muat ulang.');
            }
            $community = Community::query()->findOrFail($data['community_id']);
            if ($data['is_active']) {
                $this->movement->requireMember($actor, $community, true);
                abort_unless($this->market->sellerVisible($identity), 422, 'Iklan aktif memerlukan profil publik yang sah.');
                $count = MemberAdvertisement::query()->where('seller_identity_id', $identity->id)->where('is_active', true)->when($ad !== null, fn (Builder $q) => $q->whereKeyNot($ad->id))->count();
                abort_if($count >= (int) config('achievements.max_active_ads', 5), 422, 'Batas iklan aktif tercapai. Nonaktifkan iklan lama.');
            } elseif ($ad === null || $ad->community_id !== $community->id) {
                $this->movement->requireMember($actor, $community);
            }
            $target = $data['target_product_id'] ?? null;
            if ($target !== null) {
                $product = MarketplaceProduct::query()->findOrFail($target);
                abort_unless($product->user_id === $actor->id && $product->seller_identity_id === $identity->id && $product->community_id === $community->id, 422, 'Tujuan iklan harus produk milik sendiri dari komunitas yang sama.');
            }
            $photo = $data['photo_asset_id'] ?? null;
            if ($photo !== null && $photo !== $ad?->photo_asset_id) {
                $asset = Asset::query()->lockForUpdate()->findOrFail($photo);
                $this->images->assertUsable($actor, [$asset->id], 'photo_asset_id');
                $disk = Storage::disk($asset->storage_type->disk());
                abort_unless($disk->exists($asset->path), 422, 'Foto hilang. Unggah ulang.');
                $dimensions = @getimagesizefromstring($disk->get($asset->path));
                abort_unless($dimensions !== false && in_array($dimensions[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true), 422, 'Foto bukan raster yang valid.');
                $asset->update(['is_protected' => true, 'retain_until' => null]);
            }
            $ad ??= new MemberAdvertisement;
            $ad->fill(['user_id' => $actor->id, 'seller_identity_id' => $identity->id, 'community_id' => $community->id, 'title' => trim($data['title']), 'body' => trim($data['body']), 'photo_asset_id' => $photo, 'target_product_id' => $target, 'is_active' => $data['is_active'], 'version' => ($ad->version ?? 0) + 1])->save();
            activity('advertisement')->causedBy($actor)->performedOn($community)->event('saved')->withProperties(['advertisement_id' => $ad->id, 'version' => $ad->version])->log('Iklan anggota disimpan');

            return $ad;
        }, 3);
    }

    public function assertOwner(User $actor, MemberAdvertisement $ad): void
    {
        abort_unless(app(MemberAdvertisementPolicy::class)->update($actor, $ad), 403, 'Hanya pemilik dapat mengelola iklan.');
    }

    /** @return array<string,mixed> */
    public function data(MemberAdvertisement $ad): array
    {
        $identity = MemberIdentity::query()->findOrFail($ad->seller_identity_id);
        $community = Community::query()->findOrFail($ad->community_id);
        $asset = $ad->photo_asset_id === null ? null : Asset::query()->find($ad->photo_asset_id);
        $url = $asset !== null && $asset->status === AssetStatus::Active && ! $asset->isSensitive() && Storage::disk($asset->storage_type->disk())->exists($asset->path) ? $asset->getPublicUrl() : null;

        return ['id' => $ad->id, 'title' => $ad->title, 'body' => $ad->body, 'photo_asset_id' => $ad->photo_asset_id, 'photo_url' => $url, 'target_product_id' => $ad->target_product_id, 'is_active' => $ad->is_active, 'version' => $ad->version, 'seller' => ['identity_id' => $identity->id, 'name' => $identity->name], 'community' => ['id' => $community->id, 'name' => $community->name], 'created_at' => $ad->created_at->toIso8601String(), 'updated_at' => $ad->updated_at->toIso8601String()];
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function catalogue(array $filters, ?User $owner = null): array
    {
        $identity = $owner === null ? null : MemberIdentity::forUser($owner);
        $query = MemberAdvertisement::query()->when($identity !== null, fn (Builder $q) => $q->where('seller_identity_id', $identity->id))->when($identity === null, fn (Builder $q) => $q->where('is_active', true))
            ->when($filters['community_id'] ?? null, fn (Builder $q, $id) => $q->where('community_id', $id))->when($filters['seller_identity_id'] ?? null, fn (Builder $q, $id) => $q->where('seller_identity_id', $id))
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->whereRaw("lower(title || ' ' || body) like ?", ['%'.addcslashes(mb_strtolower(trim($search)), '\\%_').'%']))->latest()->orderBy('id');
        $page = (int) ($filters['page'] ?? 1);
        $per = (int) ($filters['per_page'] ?? 20);
        $total = 0;
        $items = [];
        foreach ($query->lazy(100) as $ad) {
            if ($owner === null && ! $this->visible($ad)) {
                continue;
            }
            if ($total >= ($page - 1) * $per && count($items) < $per) {
                $items[] = $this->data($ad);
            }$total++;
        }

        return ['items' => $items, 'current_page' => $page, 'last_page' => max(1, (int) ceil($total / $per)), 'total' => $total, ...($identity === null ? [] : ['active_count' => MemberAdvertisement::query()->where('seller_identity_id', $identity->id)->where('is_active', true)->count(), 'max_active' => (int) config('achievements.max_active_ads', 5)])];
    }
}
