<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MemberCardResource;
use App\Models\Community;
use App\Models\User;
use App\Services\DuesService;
use App\Support\ApiResponse;
use App\Support\Enums\CommunityMemberStatus;
use App\Support\Enums\DuesPaymentStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemberCardController extends Controller
{
    public function __construct(private readonly DuesService $dues) {}

    /**
     * Kartu anggota milik pengguna yang login di komunitas ini, dengan masa berlaku menurut
     * iuran bulanan yang lunas. Hanya untuk anggota aktif, termasuk bagi super admin.
     */
    public function show(Request $request, Community $community): JsonResponse
    {
        $this->authorize('view', $community);

        /** @var User $user */
        $user = $request->user();
        $member = $community->membershipOf($user);
        if ($member?->status !== CommunityMemberStatus::Active) {
            throw new AuthorizationException('Kartu anggota hanya untuk anggota aktif komunitas ini.');
        }

        $member->setRelation('community', $community->loadMissing('logo'));
        $member->setRelation('user', $user);

        return ApiResponse::success(new MemberCardResource(
            $member,
            $this->dues->cardValidity($community, $member),
            $community->duesPayments()
                ->where('user_id', $user->getKey())
                ->where('status', DuesPaymentStatus::Pending)
                ->exists(),
        ));
    }
}
