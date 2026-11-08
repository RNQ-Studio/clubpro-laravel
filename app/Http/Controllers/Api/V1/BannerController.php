<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BannerResource;
use App\Models\Banner;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class BannerController extends Controller
{
    /**
     * Popup Paid Promotion di Beranda: hanya yang aktif dan sedang dalam jadwal tayang.
     * `meta.interval_hours` = popup tampil lagi di perangkat yang sama sesudah sekian jam.
     *
     * @unauthenticated
     */
    public function index(): JsonResponse
    {
        $banners = Banner::query()->visible()->with('imageAsset')->limit(Banner::MAX_VISIBLE)->get();

        return ApiResponse::success(
            BannerResource::collection($banners),
            meta: ['interval_hours' => Banner::intervalHours()],
        );
    }
}
