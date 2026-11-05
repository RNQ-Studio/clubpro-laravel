<?php

namespace App\Services\Admin;

use App\Models\Community;
use App\Models\User;
use App\Support\Enums\CommunityStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Verifikasi komunitas dari Admin Panel. Keputusan bisa diubah: komunitas yang salah
 * disetujui harus bisa dicabut dari daftar umum.
 */
class CommunityAdminService
{
    /**
     * @param  array{search?: string|null, status?: string|null}  $filters
     * @return LengthAwarePaginator<int, Community>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $search = Str::lower(trim($filters['search'] ?? ''));

        return Community::query()
            ->with(['logo', 'owner'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.addcslashes($search, '\\%_').'%';
                $query->where(fn (Builder $match): Builder => $match
                    ->whereRaw('lower(name) like ?', [$like])
                    ->orWhereRaw('lower(short_name) like ?', [$like])
                    ->orWhereRaw('lower(city_name) like ?', [$like])
                    ->orWhereHas('owner', fn (Builder $owner): Builder => $owner
                        ->whereRaw('lower(name) like ?', [$like])
                        ->orWhereRaw('lower(email) like ?', [$like])));
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /** @return array{total: int, pending: int, approved: int, rejected: int} */
    public function summary(): array
    {
        $counts = Community::query()
            ->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'total' => (int) $counts->sum(),
            'pending' => (int) $counts->get(CommunityStatus::Pending->value, 0),
            'approved' => (int) $counts->get(CommunityStatus::Approved->value, 0),
            'rejected' => (int) $counts->get(CommunityStatus::Rejected->value, 0),
        ];
    }

    public function detail(Community $community): Community
    {
        return $community->load(['logo', 'photos', 'owner', 'reviewer']);
    }

    /**
     * Setujui atau tolak. [$expected] adalah status yang dilihat super admin: bila super
     * admin lain sudah memutuskan lebih dulu, keputusannya tidak ditimpa diam-diam.
     *
     * @throws ValidationException
     */
    public function review(
        User $actor,
        Community $community,
        CommunityStatus $status,
        CommunityStatus $expected,
        ?string $note,
    ): Community {
        if ($status === $expected) {
            throw ValidationException::withMessages([
                'status' => ['Komunitas ini sudah berstatus demikian.'],
            ]);
        }

        $updated = Community::query()
            ->whereKey($community->getKey())
            ->where('status', $expected)
            ->update([
                'status' => $status,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
                // Alasan hanya untuk penolakan; komunitas disetujui terbuka untuk umum.
                'review_note' => $status === CommunityStatus::Rejected ? $note : null,
            ]);

        abort_if($updated === 0, 409, 'Status komunitas sudah berubah. Muat ulang untuk melihat yang terbaru.');

        activity('admin-panel')
            ->causedBy($actor)
            ->performedOn($community)
            ->event($status === CommunityStatus::Approved ? 'community_approved' : 'community_rejected')
            ->withProperties(['from' => $expected->value, 'note' => $note])
            ->log('community_reviewed');

        return $this->detail($community->refresh());
    }
}
