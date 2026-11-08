<?php

namespace App\Services\Admin;

use App\Models\AppConfig;
use App\Models\Asset;
use App\Models\Banner;
use App\Models\Community;
use App\Models\User;
use App\Services\AssetDeletionService;
use App\Services\ImageAssetGuard;
use App\Support\Enums\AppConfigType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Paid Promotion (popup Beranda) dari Admin Panel. Perubahan banner tercatat lewat activity
 * log model; jeda tampil lewat log `admin-panel`.
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

    /** @return array{total: int, live: int, max_visible: int, interval_hours: int} */
    public function summary(): array
    {
        return [
            'total' => Banner::query()->count(),
            'live' => Banner::query()->visible()->count(),
            'max_visible' => Banner::MAX_VISIBLE,
            'interval_hours' => Banner::intervalHours(),
        ];
    }

    /** Popup tampil lagi di perangkat yang sama sesudah [$hours] jam. */
    public function updateInterval(User $actor, int $hours): void
    {
        $old = Banner::intervalHours();

        AppConfig::query()->updateOrCreate(
            ['key' => Banner::INTERVAL_CONFIG_KEY],
            [
                'value' => (string) $hours,
                'type' => AppConfigType::Integer,
                'description' => 'Paid Promotion: popup Beranda tampil lagi sesudah sekian jam (1–720).',
            ],
        );
        AppConfig::bustCache(Banner::INTERVAL_CONFIG_KEY);

        if ($old !== $hours) {
            activity('admin-panel')
                ->causedBy($actor)
                ->event('promotion-interval-updated')
                ->withProperties(['old' => $old, 'new' => $hours])
                ->log('promotion-interval-updated');
        }
    }

    /**
     * @param  array{title: string|null, subtitle: string|null, link_url: string|null, button_label?: string|null, image_asset_id: string, is_active?: bool}  $data
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
                // Tanpa aksi tidak ada tombol.
                'button_label' => $data['link_url'] === null ? null : ($data['button_label'] ?? null),
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
     * @param  array{title: string|null, subtitle: string|null, link_url: string|null, button_label?: string|null, image_asset_id?: string, is_active?: bool}  $data
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
                'button_label' => match (true) {
                    $data['link_url'] === null => null,
                    array_key_exists('button_label', $data) => $data['button_label'],
                    default => $banner->button_label,
                },
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
