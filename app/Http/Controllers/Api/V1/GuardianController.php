<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ClaimMemberInvitationRequest;
use App\Http\Requests\Api\V1\UpdateDependentRequest;
use App\Models\CommunityMember;
use App\Models\GuardianGrant;
use App\Models\MemberIdentity;
use App\Models\MemberInvitation;
use App\Models\User;
use App\Services\DuesService;
use App\Services\GuardianService;
use App\Support\ApiResponse;
use App\Support\Enums\MemberCardStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GuardianController extends Controller
{
    public function __construct(private readonly GuardianService $guardians, private readonly DuesService $dues) {}

    public function invitations(Request $request): JsonResponse
    {
        if (! $this->actor($request)->hasVerifiedEmail()) {
            throw new AuthorizationException('Verifikasi email untuk melihat undangan.');
        }
        $invitations = MemberInvitation::query()->with('identity')->where('email', strtolower($this->actor($request)->email))
            ->whereNull('claimed_at')->whereNull('revoked_at')->where('expires_at', '>', now())->orderByDesc('id')->get();

        return ApiResponse::success($invitations->map(fn (MemberInvitation $invite): array => ManualMemberController::invitationData($invite))->all());
    }

    public function claim(ClaimMemberInvitationRequest $request, MemberInvitation $invitation): JsonResponse
    {
        $data = $request->validated();
        $identity = $this->guardians->claim($this->actor($request), $invitation, $data['token']);

        return ApiResponse::success($this->guardians->details($identity), 'Hubungan anggota terverifikasi');
    }

    public function index(Request $request): JsonResponse
    {
        $grants = GuardianGrant::query()->with('identity')->where('guardian_user_id', $this->actor($request)->getKey())->whereNull('revoked_at')->get();

        return ApiResponse::success($grants->map(fn (GuardianGrant $grant): array => ['grant_id' => $grant->id, ...$this->guardians->details($grant->identity)])->all());
    }

    public function show(Request $request, MemberIdentity $identity): JsonResponse
    {
        $this->guardians->authorizeDependent($this->actor($request), $identity);

        return ApiResponse::success(['grant_id' => $this->guardians->grantId($this->actor($request), $identity), ...$this->guardians->details($identity)]);
    }

    public function update(UpdateDependentRequest $request, MemberIdentity $identity): JsonResponse
    {
        $identity = $this->guardians->updateDependent($this->actor($request), $identity, $request->validated());

        return ApiResponse::success(['grant_id' => $this->guardians->grantId($this->actor($request), $identity), ...$this->guardians->details($identity)], 'Data anggota tersimpan');
    }

    public function revoke(Request $request, GuardianGrant $grant): JsonResponse
    {
        $this->guardians->revoke($this->actor($request), $grant);

        return ApiResponse::success(null, 'Hubungan wali dicabut');
    }

    public function card(Request $request, MemberIdentity $identity, CommunityMember $member): JsonResponse
    {
        $this->guardians->authorizeDependent($this->actor($request), $identity);
        abort_unless($member->member_identity_id === $identity->id, 404);
        abort_unless($member->isActive(), 403, 'Kartu anggota hanya tersedia untuk keanggotaan aktif.');
        $validity = $member->user_id === null
            ? ['status' => $member->community->dues_amount ? MemberCardStatus::Inactive : MemberCardStatus::NoDues, 'valid_until' => null, 'paid_until' => null]
            : $this->dues->cardValidity($member->community, $member);

        return ApiResponse::success([
            'member_number' => $member->cardNumber(), 'name' => $identity->name, 'role' => $member->role->value,
            'community_name' => $member->community->name, 'status' => $member->status->value,
            'validity' => ['status' => $validity['status']->value, 'valid_until' => $validity['valid_until']?->toDateString(), 'paid_until' => $validity['paid_until']?->format('Y-m')],
        ]);
    }

    private function actor(Request $request): User
    {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }
}
