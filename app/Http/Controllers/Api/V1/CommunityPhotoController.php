<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CommunityDetailResource;
use App\Models\Asset;
use App\Models\Community;
use App\Models\User;
use App\Services\CommunityService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommunityPhotoController extends Controller
{
    public function __construct(private readonly CommunityService $communities) {}

    /**
     * Tambah foto ke galeri; berkasnya diunggah lebih dulu lewat `assets/upload`.
     */
    public function store(Request $request, Community $community): JsonResponse
    {
        $this->authorize('manage', $community);

        $data = $request->validate(['asset_id' => ['required', 'uuid']]);

        /** @var User $user */
        $user = $request->user();

        $this->communities->addPhoto($user, $community, $data['asset_id']);

        return ApiResponse::success(
            new CommunityDetailResource($this->communities->detail($user, $community->refresh())),
            'Foto ditambahkan',
            201,
        );
    }

    public function destroy(Request $request, Community $community, Asset $asset): JsonResponse
    {
        $this->authorize('manage', $community);

        /** @var User $user */
        $user = $request->user();

        $this->communities->removePhoto($community, $asset);

        return ApiResponse::success(
            new CommunityDetailResource($this->communities->detail($user, $community->refresh())),
            'Foto dihapus',
        );
    }
}
