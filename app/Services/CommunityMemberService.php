<?php

namespace App\Services;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\User;
use App\Support\Enums\CommunityMemberRole;
use App\Support\Enums\CommunityMemberStatus;
use App\Support\Enums\CommunityStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Keanggotaan komunitas. Pendiri/admin mengelola anggota; hanya pendiri
 * yang dapat mengaktifkan atau mencabut admin lain.
 */
class CommunityMemberService
{
    /**
     * @return LengthAwarePaginator<int, CommunityMember>
     */
    public function paginate(Community $community, CommunityMemberStatus $status, int $perPage, ?string $search = null, ?string $role = null): LengthAwarePaginator
    {
        $members = $community->members()
            ->select('community_members.*')
            ->leftJoin('users', 'users.id', '=', 'community_members.user_id')
            ->leftJoin('member_identities', 'member_identities.id', '=', 'community_members.member_identity_id')
            ->with(['user', 'identity'])
            ->where('community_members.status', $status)
            ->when(filled($search), fn ($query) => $query->whereRaw('lower(coalesce(member_identities.name, users.name)) like ?', ['%'.addcslashes(mb_strtolower(trim((string) $search)), '\\%_').'%']))
            ->when($role, fn ($query) => $query->where('community_members.role', $role))
            ->orderByRaw("case community_members.role when 'creator' then 0 when 'admin' then 1 when 'officer' then 2 when 'coach' then 3 else 4 end")
            ->orderByRaw('coalesce(member_identities.name, users.name)')
            ->orderBy('community_members.id')
            ->paginate($perPage);

        // `users.avatar` bisa berisi path lama; hanya yang UUID boleh menyentuh tabel assets.
        Collection::make(array_map(fn (CommunityMember $member): ?User => $member->user, $members->items()))
            ->filter(fn (?User $user): bool => $user !== null)
            ->filter(fn (User $user): bool => Str::isUuid((string) $user->avatar))
            ->load('avatarAsset');

        return $members;
    }

    /**
     * Minta bergabung; statusnya menunggu sampai diterima creator/admin.
     *
     * @throws ValidationException
     */
    public function join(User $user, Community $community): CommunityMember
    {
        try {
            return DB::transaction(function () use ($user, $community): CommunityMember {
                $community = Community::query()->whereKey($community->id)->lockForUpdate()->firstOrFail();
                if ($community->status !== CommunityStatus::Approved) {
                    $this->reject('community', 'Komunitas ini belum terverifikasi.');
                }
                $existing = $community->members()->where('user_id', $user->id)->lockForUpdate()->first();
                if ($existing?->status === CommunityMemberStatus::Archived) {
                    app(BillingService::class)->prepareArchivedMembership($existing);
                    $existing->update(['status' => CommunityMemberStatus::Pending]);
                    app(BillingService::class)->initializeJoin($existing);

                    return $existing->refresh();
                }
                if ($existing !== null) {
                    return $existing;
                }
                $member = $community->members()->create(['user_id' => $user->id, 'role' => CommunityMemberRole::Member, 'status' => CommunityMemberStatus::Pending]);
                app(BillingService::class)->initializeJoin($member);

                return $member->refresh();
            }, 3);
        } catch (UniqueConstraintViolationException) {
            return $community->membershipOf($user) ?? throw new \LogicException('Keanggotaan hilang.');
        }
    }

    /**
     * Keluar dari komunitas, atau membatalkan permintaan bergabung.
     *
     * @throws ValidationException
     */
    public function leave(User $user, Community $community): void
    {
        $member = $community->membershipOf($user);

        if ($member === null) {
            $this->reject('community', 'Anda bukan anggota komunitas ini.');
        }

        if ($member->role === CommunityMemberRole::Creator) {
            $this->reject('community', 'Creator tidak bisa keluar dari komunitasnya.');
        }

        app(BillingService::class)->pauseMembership($member);
        $member->update(['status' => CommunityMemberStatus::Archived]);
        $this->log($user, $member, 'community_member_left');
    }

    /**
     * @throws ValidationException
     */
    public function approve(User $actor, CommunityMember $member): CommunityMember
    {
        return DB::transaction(function () use ($actor, $member): CommunityMember {
            $member = $this->lockedTarget($actor, $member);
            $this->assertManageTarget($actor, $member);
            if ($member->isActive()) {
                $this->reject('member', 'Pengguna ini sudah menjadi anggota.');
            }

            if ($member->status === CommunityMemberStatus::Archived) {
                app(BillingService::class)->prepareArchivedMembership($member);
                app(BillingService::class)->memberSubject($member, true);
            } else {
                app(BillingService::class)->memberSubject($member);
            }
            $member->update(['status' => CommunityMemberStatus::Active, 'joined_at' => $member->joined_at ?? now()]);
            app(BillingService::class)->initializeJoin($member);

            $this->log($actor, $member, 'community_member_approved');

            return $member->refresh();
        });
    }

    /**
     * Ubah peran anggota aktif.
     *
     * @throws ValidationException
     */
    public function setRole(User $actor, CommunityMember $member, CommunityMemberRole $role): CommunityMember
    {
        return DB::transaction(function () use ($actor, $member, $role): CommunityMember {
            $member = $this->lockedTarget($actor, $member);
            $this->assertManageTarget($actor, $member, $role);
            if (! $member->isActive()) {
                $this->reject('role', 'Terima permintaan bergabungnya terlebih dahulu.');
            }

            if ($member->role === CommunityMemberRole::Creator) {
                $this->reject('role', 'Peran creator tidak bisa diubah.');
            }

            if ($member->role === $role) {
                return $member;
            }

            app(BillingService::class)->refresh(app(BillingService::class)->memberSubject($member));
            $oldRole = $member->role;
            $member->update(['role' => $role]);

            $this->log($actor, $member, $role === CommunityMemberRole::Admin ? 'community_admin_granted' : ($oldRole === CommunityMemberRole::Admin ? 'community_admin_revoked' : 'community_role_updated'), ['old_role' => $oldRole->value, 'new_role' => $role->value]);

            return $member->refresh();
        });
    }

    /**
     * Keluarkan anggota, atau tolak permintaan bergabung.
     *
     * @throws ValidationException
     */
    public function remove(User $actor, CommunityMember $member): void
    {
        DB::transaction(function () use ($actor, $member): void {
            $member = $this->lockedTarget($actor, $member);
            $this->assertManageTarget($actor, $member);
            if ($member->role === CommunityMemberRole::Creator) {
                $this->reject('member', 'Creator tidak bisa dikeluarkan.');
            }

            if ($member->user_id === $actor->getKey()) {
                $this->reject('member', 'Gunakan menu Keluar untuk meninggalkan komunitas.');
            }

            if ($member->role === CommunityMemberRole::Admin && ! $this->isCreator($actor, $member)) {
                $this->reject('member', 'Hanya creator yang bisa mengeluarkan admin.');
            }

            $event = $member->isActive() ? 'community_member_removed' : 'community_member_rejected';
            app(BillingService::class)->pauseMembership($member);
            $member->update(['status' => CommunityMemberStatus::Archived]);

            $this->log($actor, $member, $event);
        });
    }

    private function lockedTarget(User $actor, CommunityMember $member): CommunityMember
    {
        Community::query()->whereKey($member->community_id)->lockForUpdate()->firstOrFail();
        $locked = CommunityMember::query()->where('community_id', $member->community_id)
            ->where(fn ($query) => $query->whereKey($member->id)->orWhere('user_id', $actor->getKey()))
            ->orderBy('id')->lockForUpdate()->get();

        return $locked->firstWhere('id', $member->id) ?? throw new \LogicException('Keanggotaan hilang.');
    }

    private function assertManageTarget(User $actor, CommunityMember $member, ?CommunityMemberRole $newRole = null): void
    {
        if (! $actor->can('manageMembers', $member->community)) {
            abort(403);
        }
        if ($member->merged_into_member_id !== null) {
            $this->reject('member', 'Keanggotaan telah digabung ke identitas utama. Gunakan anggota utama; nomor dan riwayat lama tetap tersimpan.');
        }
        if ($member->role === CommunityMemberRole::Creator) {
            $this->reject('role', 'Pendiri tidak dapat diubah.');
        }
        if (! $this->isCreator($actor, $member) && ($newRole === CommunityMemberRole::Admin || ($member->role === CommunityMemberRole::Admin && $member->user_id !== $actor->getKey()))) {
            $this->reject('role', 'Hanya pendiri yang dapat mengaktifkan atau mengubah admin.');
        }
    }

    /** Super admin setara creator: ia yang menengahi bila ada masalah di komunitas. */
    private function isCreator(User $actor, CommunityMember $member): bool
    {
        return $actor->hasRole('super-admin')
            || $member->community->membershipOf($actor)?->role === CommunityMemberRole::Creator;
    }

    /** @param array<string, mixed> $changes */
    private function log(User $actor, CommunityMember $member, string $event, array $changes = []): void
    {
        activity('community')
            ->causedBy($actor)
            ->performedOn($member->community)
            ->event($event)
            ->withProperties(['user_id' => $member->user_id, 'member_id' => $member->id, 'member_identity_id' => $member->member_identity_id, 'role' => $member->role->value, 'status' => $member->status->value, ...$changes])
            ->log($event);
    }

    /**
     * @throws ValidationException
     */
    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
