<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\MemberIdentity;
use App\Models\User;
use App\Services\BillingService;
use App\Support\ApiResponse;
use App\Support\Enums\CommunityMemberStatus;
use App\Support\Enums\CommunityStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemberProfileController extends Controller
{
    public function __construct(private readonly BillingService $billing) {}

    /**
     * Profil umum sesama anggota komunitas: foto, nama, dan komunitas yang sama.
     * Selain anggota komunitas yang sama, pengguna ini seolah tidak ada.
     */
    public function show(Request $request, User $user): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();

        $seesAll = $viewer->is($user) || $viewer->hasRole('super-admin');

        $memberships = CommunityMember::query()
            ->active()
            ->where('user_id', $user->getKey())
            ->whereHas('community', fn (Builder $community): Builder => $community->where('status', CommunityStatus::Approved))
            ->when(! $seesAll, fn (Builder $query): Builder => $query->whereIn(
                'community_id',
                CommunityMember::query()
                    ->select('community_id')
                    ->where('user_id', $viewer->getKey())
                    ->where('status', CommunityMemberStatus::Active),
            ))
            ->with(['community.logo'])
            ->orderBy('joined_at')
            ->get();

        if (! $seesAll) {
            // This route exposes internal membership context; public discovery remains separate.
            $memberships = $memberships->filter(function (CommunityMember $member) use ($viewer): bool {
                $viewerMember = $member->community->membershipOf($viewer);

                return $viewerMember !== null
                    && $this->billing->entitlement($this->billing->memberSubject($viewerMember))['active']
                    && $this->billing->entitlement($this->billing->platformSubject($member->community))['active'];
            });
        }

        abort_if(! $seesAll && $memberships->isEmpty(), 404);

        return ApiResponse::success([
            'id' => $user->id,
            'identity_id' => MemberIdentity::forUser($user)->id,
            'name' => $user->name,
            'avatar_url' => $user->avatarUrl(),
            'registered_at' => $user->created_at?->toIso8601String(),
            'communities' => $memberships->map(fn (CommunityMember $member): array => $this->community($member->community, $member))->values(),
        ]);
    }

    /** @return array<string, mixed> */
    private function community(Community $community, CommunityMember $member): array
    {
        return [
            'id' => $community->id,
            'name' => $community->name,
            'logo_url' => $community->logo?->getPublicUrl(),
            'role' => $member->role->value,
            'role_label' => $member->role->label(),
            'joined_at' => $member->joined_at?->toIso8601String(),
        ];
    }
}
