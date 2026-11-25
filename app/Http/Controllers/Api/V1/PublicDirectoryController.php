<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PublicCommunityResource;
use App\Http\Resources\Api\V1\PublicMemberResource;
use App\Models\Community;
use App\Services\CommunityService;
use App\Services\PublicDirectoryService;
use App\Support\ApiResponse;
use App\Support\Enums\CommunityMemberRole;
use App\Support\Enums\CommunityType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PublicDirectoryController extends Controller
{
    public function __construct(private readonly PublicDirectoryService $directory, private readonly CommunityService $communities) {}

    public function communities(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'type' => ['nullable', Rule::enum(CommunityType::class)],
            'province_code' => ['nullable', 'string', 'max:2'], 'sort' => ['nullable', Rule::in(['name', 'popular'])],
            'page' => ['integer', 'min:1'], 'per_page' => ['integer', 'min:1', 'max:100'],
        ]);

        return ApiResponse::success(PublicCommunityResource::collection($this->communities->paginateApproved($data, $request->integer('per_page', 20))));
    }

    public function community(Community $community): JsonResponse
    {
        return ApiResponse::success(new PublicCommunityResource($this->directory->detail($community)));
    }

    public function members(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'community_id' => ['nullable', 'integer', 'min:1'],
            'role' => ['nullable', Rule::enum(CommunityMemberRole::class)],
            'page' => ['integer', 'min:1'], 'per_page' => ['integer', 'min:1', 'max:100'],
        ]);

        return ApiResponse::success(PublicMemberResource::collection($this->directory->members($data, $request->integer('per_page', 20))));
    }
}
