<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ListCommunitiesRequest;
use App\Http\Requests\Api\V1\Admin\ReviewCommunityRequest;
use App\Http\Resources\Api\V1\Admin\AdminCommunityDetailResource;
use App\Http\Resources\Api\V1\Admin\AdminCommunityResource;
use App\Models\Community;
use App\Models\User;
use App\Services\Admin\CommunityAdminService;
use App\Support\ApiResponse;
use App\Support\Enums\CommunityStatus;
use Illuminate\Http\JsonResponse;

class CommunityController extends Controller
{
    public function __construct(private readonly CommunityAdminService $communities) {}

    /**
     * Semua komunitas apa pun statusnya, terbaru di atas, beserta ringkasannya.
     */
    public function index(ListCommunitiesRequest $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        return ApiResponse::success(
            AdminCommunityResource::collection($this->communities->paginate($request->validated(), $perPage)),
            meta: ['summary' => $this->communities->summary()],
        );
    }

    public function show(Community $community): JsonResponse
    {
        return ApiResponse::success(new AdminCommunityDetailResource($this->communities->detail($community)));
    }

    /**
     * Setujui atau tolak pengajuan, atau ubah keputusan sebelumnya.
     */
    public function review(ReviewCommunityRequest $request, Community $community): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return ApiResponse::success(
            new AdminCommunityDetailResource($this->communities->review(
                $actor,
                $community,
                CommunityStatus::from($request->string('status')->toString()),
                CommunityStatus::from($request->string('expected_status')->toString()),
                $request->filled('note') ? $request->string('note')->toString() : null,
            )),
            'Verifikasi komunitas tersimpan',
        );
    }
}
