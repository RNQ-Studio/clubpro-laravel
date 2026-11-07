<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ReorderBannersRequest;
use App\Http\Requests\Api\V1\Admin\SaveBannerRequest;
use App\Http\Resources\Api\V1\Admin\AdminBannerResource;
use App\Models\Banner;
use App\Models\User;
use App\Services\Admin\BannerAdminService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class BannerController extends Controller
{
    public function __construct(private readonly BannerAdminService $banners) {}

    /**
     * Semua banner sesuai urutan tampil, beserta ringkasannya.
     */
    public function index(): JsonResponse
    {
        return $this->list('OK');
    }

    /**
     * Tambah banner; fotonya diunggah lebih dulu lewat `assets/upload`.
     */
    public function store(SaveBannerRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        /** @var array{title: string|null, subtitle: string|null, link_url: string|null, image_asset_id: string, is_active?: bool} $data */
        $data = $request->validated();

        return ApiResponse::success(
            new AdminBannerResource($this->banners->create($actor, $data)),
            'Banner ditambahkan',
            201,
        );
    }

    public function update(SaveBannerRequest $request, Banner $banner): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        /** @var array{title: string|null, subtitle: string|null, link_url: string|null, image_asset_id?: string, is_active?: bool} $data */
        $data = $request->validated();

        return ApiResponse::success(
            new AdminBannerResource($this->banners->update($actor, $banner, $data)),
            'Banner tersimpan',
        );
    }

    public function destroy(Banner $banner): JsonResponse
    {
        $this->banners->delete($banner);

        return ApiResponse::success(message: 'Banner dihapus');
    }

    /**
     * Simpan urutan tampil; `ids` memuat semua banner dari yang tampil pertama.
     */
    public function reorder(ReorderBannersRequest $request): JsonResponse
    {
        /** @var list<int> $ids */
        $ids = array_map('intval', $request->validated('ids'));

        $this->banners->reorder($ids);

        return $this->list('Urutan banner tersimpan');
    }

    private function list(string $message): JsonResponse
    {
        return ApiResponse::success(
            AdminBannerResource::collection($this->banners->all()),
            $message,
            meta: ['summary' => $this->banners->summary()],
        );
    }
}
