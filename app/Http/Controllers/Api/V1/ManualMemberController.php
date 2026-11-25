<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ManualMemberRequest;
use App\Http\Requests\Api\V1\MemberInvitationRequest;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\MemberInvitation;
use App\Models\User;
use App\Services\GuardianService;
use App\Services\ManualMemberService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManualMemberController extends Controller
{
    public function __construct(private readonly ManualMemberService $members, private readonly GuardianService $guardians) {}

    public function show(Request $request, Community $community, CommunityMember $member): JsonResponse
    {
        abort_unless($member->community_id === $community->id, 404);
        if ($member->user_id !== $this->actor($request)->getKey()) {
            $this->members->authorizeSubject($this->actor($request), $member);
        }

        return ApiResponse::success($this->members->details($member->load('identity', 'user')));
    }

    public function store(ManualMemberRequest $request, Community $community): JsonResponse
    {
        return ApiResponse::success($this->members->details($this->members->create($this->actor($request), $community, $request->validated())), 'Anggota manual tersimpan');
    }

    public function update(ManualMemberRequest $request, Community $community, CommunityMember $member): JsonResponse
    {
        abort_unless($member->community_id === $community->id, 404);

        return ApiResponse::success($this->members->details($this->members->update($this->actor($request), $member, $request->validated())), 'Data anggota tersimpan');
    }

    public function inviteGuardian(MemberInvitationRequest $request, Community $community, CommunityMember $member): JsonResponse
    {
        return $this->invite($request, $community, $member, 'guardian');
    }

    public function inviteAccount(MemberInvitationRequest $request, Community $community, CommunityMember $member): JsonResponse
    {
        return $this->invite($request, $community, $member, 'account');
    }

    private function invite(MemberInvitationRequest $request, Community $community, CommunityMember $member, string $kind): JsonResponse
    {
        abort_unless($member->community_id === $community->id, 404);
        $data = $request->validated();
        $invitation = $this->guardians->invite($this->actor($request), $member, $kind, $data['email']);

        return ApiResponse::success(self::invitationData($invitation), $invitation->delivery_method === 'in_app'
            ? 'Undangan tersedia di menu Undangan penerima setelah masuk dengan email terverifikasi'
            : 'Undangan dikirim ke email penerima');
    }

    /** @return array<string, mixed> */
    public static function invitationData(MemberInvitation $invitation): array
    {
        return ['id' => $invitation->id, 'kind' => $invitation->kind, 'email' => $invitation->email,
            'delivery_method' => $invitation->delivery_method, 'invited_by_name' => $invitation->issuer->name,
            'community_name' => $invitation->member->community->name,
            'member_name' => $invitation->identity->name, 'status' => 'pending', 'expires_at' => $invitation->expires_at->toIso8601String()];
    }

    private function actor(Request $request): User
    {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }
}
