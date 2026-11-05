<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CommunityMemberResource;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\User;
use App\Services\CommunityMemberService;
use App\Support\ApiResponse;
use App\Support\Enums\CommunityMemberRole;
use App\Support\Enums\CommunityMemberStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CommunityMemberController extends Controller
{
    public function __construct(private readonly CommunityMemberService $members) {}

    /**
     * Anggota aktif; `status=pending` memuat permintaan bergabung untuk creator/admin.
     */
    public function index(Request $request, Community $community): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::enum(CommunityMemberStatus::class)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $status = CommunityMemberStatus::tryFrom($data['status'] ?? '') ?? CommunityMemberStatus::Active;
        $this->authorize($status === CommunityMemberStatus::Pending ? 'manage' : 'viewMembers', $community);

        $perPage = min(max($request->integer('per_page', 50), 1), 100);

        return ApiResponse::success(
            CommunityMemberResource::collection($this->members->paginate($community, $status, $perPage)),
        );
    }

    /**
     * Minta bergabung; menunggu persetujuan creator/admin.
     */
    public function join(Request $request, Community $community): JsonResponse
    {
        $this->authorize('view', $community);

        return ApiResponse::success(
            $this->memberResource($this->members->join($this->actor($request), $community)),
            'Permintaan bergabung terkirim',
        );
    }

    /**
     * Keluar dari komunitas, atau membatalkan permintaan bergabung.
     */
    public function leave(Request $request, Community $community): JsonResponse
    {
        $this->members->leave($this->actor($request), $community);

        return ApiResponse::success(null, 'Anda keluar dari komunitas');
    }

    public function approve(Request $request, Community $community, CommunityMember $member): JsonResponse
    {
        $this->authorize('manage', $community);

        return ApiResponse::success(
            $this->memberResource($this->members->approve($this->actor($request), $member)),
            'Permintaan diterima',
        );
    }

    /**
     * Jadikan admin atau kembalikan menjadi anggota.
     */
    public function updateRole(Request $request, Community $community, CommunityMember $member): JsonResponse
    {
        $this->authorize('manage', $community);

        $data = $request->validate([
            'role' => ['required', Rule::in([CommunityMemberRole::Admin->value, CommunityMemberRole::Member->value])],
        ]);

        return ApiResponse::success(
            $this->memberResource($this->members->setRole($this->actor($request), $member, CommunityMemberRole::from($data['role']))),
            'Peran anggota diperbarui',
        );
    }

    /**
     * Keluarkan anggota, atau tolak permintaan bergabung.
     */
    public function destroy(Request $request, Community $community, CommunityMember $member): JsonResponse
    {
        $this->authorize('manage', $community);

        $this->members->remove($this->actor($request), $member);

        return ApiResponse::success(null, 'Anggota dikeluarkan');
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function memberResource(CommunityMember $member): CommunityMemberResource
    {
        return new CommunityMemberResource($member->load('user'));
    }
}
