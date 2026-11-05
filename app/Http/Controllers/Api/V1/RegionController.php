<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\IndonesiaRegionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class RegionController extends Controller
{
    /**
     * Seluruh provinsi beserta kabupaten/kotanya, untuk pilihan wilayah di app.
     *
     * @unauthenticated
     */
    public function indonesia(IndonesiaRegionService $regions): JsonResponse
    {
        return ApiResponse::success($regions->provinces())
            ->header('Cache-Control', 'public, max-age=86400');
    }
}
