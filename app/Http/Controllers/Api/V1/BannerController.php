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
     * Banner slider Beranda: hanya yang aktif dan sedang dalam jadwal tayang.
     *
     * @unauthenticated
     */
    public function index(): JsonResponse
    {
        $banners = Banner::query()->visible()->with('imageAsset')->limit(10)->get();

        return ApiResponse::success(BannerResource::collection($banners));
    }
}
