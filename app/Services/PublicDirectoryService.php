<?php

namespace App\Services;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Support\Enums\CommunityMemberStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class PublicDirectoryService
{
    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, CommunityMember>
     */
    public function members(array $filters, int $perPage): LengthAwarePaginator
    {
        return CommunityMember::query()->with(['community', 'identity', 'user'])
            ->where('status', CommunityMemberStatus::Active)
            ->whereIn('community_id', Community::query()->approved()->select('communities.id'))
            ->whereHas('identity', fn (Builder $query) => $query->where('is_public', true)
                ->where(fn (Builder $adult) => $adult->where(fn (Builder $account) => $account->whereNotNull('user_id')->where(fn (Builder $age) => $age->whereNull('birth_date')->orWhere('birth_date', '<=', now()->subYears(18)->toDateString())))
                    ->orWhereHas('guardianGrants', fn (Builder $grant) => $grant->whereNotNull('verified_at')->whereNull('revoked_at'))))
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->whereHas('identity', fn (Builder $identity) => $identity->whereRaw('lower(name) like ?', ['%'.addcslashes(mb_strtolower(trim($search)), '\\%_').'%'])))
            ->when($filters['community_id'] ?? null, fn (Builder $query, $id) => $query->where('community_id', $id))
            ->when($filters['role'] ?? null, fn (Builder $query, string $role) => $query->where('role', $role))
            ->orderBy('member_identity_id')->orderBy('id')->paginate($perPage);
    }

    public function detail(Community $community): Community
    {
        abort_unless(Community::query()->approved()->whereKey($community->id)->exists(), 404);

        return $community->load(['logo', 'photos'])->loadCount(['members as members_count' => fn (Builder $member) => $member->where('status', CommunityMemberStatus::Active)]);
    }
}
