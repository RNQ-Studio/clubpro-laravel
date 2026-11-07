<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\BannerAdminService;
use App\Services\Admin\CommunityAdminService;
use App\Services\Admin\UserAdminService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class SummaryController extends Controller
{
    /**
     * Angka ringkas untuk halaman menu Admin Panel.
     */
    public function __invoke(
        UserAdminService $users,
        CommunityAdminService $communities,
        BannerAdminService $banners,
    ): JsonResponse {
        return ApiResponse::success([
            'users' => $users->summary(),
            'communities' => $communities->summary(),
            'banners' => $banners->summary(),
        ]);
    }
}
