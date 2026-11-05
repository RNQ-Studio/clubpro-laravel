<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveCommunityActivityRequest;
use App\Http\Resources\Api\V1\CommunityActivityResource;
use App\Models\Community;
use App\Models\CommunityActivity;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class CommunityActivityController extends Controller
{
    /** Batas tiap kelompok; kegiatan lama jarang perlu dibuka. */
    private const LIMIT = 50;

    /**
     * Kegiatan mendatang (terdekat dulu) dan yang sudah lewat (terbaru dulu).
     */
    public function index(Community $community): JsonResponse
    {
        $this->authorize('view', $community);

        $activities = $community->activities();

        return ApiResponse::success([
            'upcoming' => CommunityActivityResource::collection(
                (clone $activities)->where('starts_at', '>=', now())->orderBy('starts_at')->limit(self::LIMIT)->get(),
            ),
            'past' => CommunityActivityResource::collection(
                (clone $activities)->where('starts_at', '<', now())->orderByDesc('starts_at')->limit(self::LIMIT)->get(),
            ),
        ]);
    }

    public function store(SaveCommunityActivityRequest $request, Community $community): JsonResponse
    {
        $this->authorize('manage', $community);

        /** @var User $user */
        $user = $request->user();

        $activity = new CommunityActivity($request->validated());
        $activity->created_by = $user->getKey();
        $community->activities()->save($activity);

        return ApiResponse::success(new CommunityActivityResource($activity), 'Kegiatan ditambahkan', 201);
    }

    public function update(SaveCommunityActivityRequest $request, Community $community, CommunityActivity $activity): JsonResponse
    {
        $this->authorize('manage', $community);

        $activity->update($request->validated());

        return ApiResponse::success(new CommunityActivityResource($activity), 'Kegiatan diperbarui');
    }

    public function destroy(Community $community, CommunityActivity $activity): JsonResponse
    {
        $this->authorize('manage', $community);

        $activity->delete();

        return ApiResponse::success(null, 'Kegiatan dihapus');
    }
}
