<?php

namespace App\Services\Admin;

use App\Models\Asset;
use App\Models\Banner;
use App\Models\Community;
use App\Models\User;
use App\Services\AssetDeletionService;
use App\Services\ImageAssetGuard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Banner slider Beranda dari Admin Panel. Perubahan tercatat lewat activity log model.
 */
class BannerAdminService
{
    public function __construct(
        private readonly ImageAssetGuard $images,
        private readonly AssetDeletionService $assets,
    ) {}

    /**
     * Semua banner sesuai urutan tampil, apa pun statusnya.
     *
     * @return Collection<int, Banner>
     */
    public function all(): Collection
    {
        return $this->withLinkCommunities(
            Banner::query()->with('imageAsset')->orderBy('sort_order')->orderBy('id')->get(),
        );
    }

    /** @return array{total: int, live: int, max_visible: int} */
    public function summary(): array
    {
        return [
            'total' => Banner::query()->count(),
            'live' => Banner::query()->visible()->count(),
            'max_visible' => Banner::MAX_VISIBLE,
        ];
    }

    /**
     * @param  array{title: string|null, subtitle: string|null, link_url: string|null, image_asset_id: string, is_active?: bool}  $data
     *
     * @throws ValidationException
     */
    public function create(User $actor, array $data): Banner
    {
        $assetId = $data['image_asset_id'];

        $this->images->assertUsable($actor, [$assetId], 'image_asset_id');

        $banner = DB::transaction(function () use ($data, $assetId): Banner {
            $banner = Banner::query()->create([
                'title' => $data['title'] ?? '',
                'subtitle' => $data['subtitle'],
                'image' => $assetId,
                'link_url' => $data['link_url'],
                'is_active' => $data['is_active'] ?? true,
                // Tampil paling akhir sampai diurutkan.
                'sort_order' => (int) Banner::query()->max('sort_order') + 1,
            ]);

            Asset::query()->whereKey($assetId)->update(['retain_until' => null]);

            return $banner;
        });

        return $this->detail($banner);
    }

    /**
     * Jadwal tayang dari back-office tidak disentuh.
     *
     * @param  array{title: string|null, subtitle: string|null, link_url: string|null, image_asset_id?: string, is_active?: bool}  $data
     *
     * @throws ValidationException
     */
    public function update(User $actor, Banner $banner, array $data): Banner
    {
        $oldImage = $banner->image;
        $newImage = $data['image_asset_id'] ?? $oldImage;
        $replaced = $newImage !== $oldImage;

        if ($replaced) {
            $this->images->assertUsable($actor, [(string) $newImage], 'image_asset_id');
        }

        DB::transaction(function () use ($banner, $data, $newImage, $replaced): void {
            $banner->update([
                'title' => $data['title'] ?? '',
                'subtitle' => $data['subtitle'],
                'image' => $newImage,
                'link_url' => $data['link_url'],
                'is_active' => $data['is_active'] ?? $banner->is_active,
            ]);

            if ($replaced) {
                Asset::query()->whereKey($newImage)->update(['retain_until' => null]);
            }
        });

        if ($replaced) {
            $this->deleteImage($oldImage);
        }

        return $this->detail($banner->refresh());
    }

    public function delete(Banner $banner): void
    {
        $banner->delete();
    }

    /**
     * [$ids] harus memuat semua banner: bila tidak, ada yang ditambah atau dihapus sejak
     * daftarnya dibuka dan urutan yang dikirim sudah basi.
     *
     * @param  list<int>  $ids
     */
    public function reorder(array $ids): void
    {
        DB::transaction(function () use ($ids): void {
            $banners = Banner::query()->lockForUpdate()->get()->keyBy('id');

            abort_if(
                $banners->count() !== count($ids) || $banners->keys()->diff($ids)->isNotEmpty(),
                409,
                'Daftar banner sudah berubah. Muat ulang untuk melihat yang terbaru.',
            );

            foreach ($ids as $order => $id) {
                $banners->get($id)?->update(['sort_order' => $order + 1]);
            }
        });
    }

    private function detail(Banner $banner): Banner
    {
        $this->withLinkCommunities(new Collection([$banner->load('imageAsset')]));

        return $banner;
    }

    /**
     * Nama komunitas tujuan, satu kueri untuk semua banner.
     *
     * @param  Collection<int, Banner>  $banners
     * @return Collection<int, Banner>
     */
    private function withLinkCommunities(Collection $banners): Collection
    {
        $ids = $banners->map(fn (Banner $banner): ?int => Banner::communityIdFromLink($banner->link_url))->filter();
        $communities = Community::query()->whereKey($ids->all())->get(['id', 'name'])->keyBy('id');

        return $banners->each(fn (Banner $banner): Banner => $banner->setRelation(
            'linkCommunity',
            $communities->get(Banner::communityIdFromLink($banner->link_url)),
        ));
    }

    /** Foto baru sudah tersimpan; sisa berkas lama tidak boleh menggagalkannya. */
    private function deleteImage(?string $image): void
    {
        $asset = $image !== null && Str::isUuid($image) ? Asset::find($image) : null;

        if ($asset !== null) {
            rescue(fn () => $this->assets->hardDelete($asset));
        }
    }
}
