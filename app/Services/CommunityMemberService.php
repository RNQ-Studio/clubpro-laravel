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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Keanggotaan komunitas. Creator dan admin menerima anggota dan memberi akses admin;
 * mencabut admin hanya oleh creator agar admin tidak bisa saling menjatuhkan.
 */
class CommunityMemberService
{
    /**
     * @return LengthAwarePaginator<int, CommunityMember>
     */
    public function paginate(Community $community, CommunityMemberStatus $status, int $perPage): LengthAwarePaginator
    {
        $members = $community->members()
            ->select('community_members.*')
            ->join('users', 'users.id', '=', 'community_members.user_id')
            ->with('user')
            ->where('community_members.status', $status)
            ->orderByRaw("case community_members.role when 'creator' then 0 when 'admin' then 1 else 2 end")
            ->orderBy('users.name')
            ->orderBy('community_members.id')
            ->paginate($perPage);

        // `users.avatar` bisa berisi path lama; hanya yang UUID boleh menyentuh tabel assets.
        Collection::make(array_map(fn (CommunityMember $member): User => $member->user, $members->items()))
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
        if ($community->status !== CommunityStatus::Approved) {
            $this->reject('community', 'Komunitas ini belum terverifikasi.');
        }

        $existing = $community->membershipOf($user);
        if ($existing !== null) {
            return $existing->isActive()
                ? $this->reject('community', 'Anda sudah menjadi anggota komunitas ini.')
                : $existing;
        }

        try {
            return $community->members()->create([
                'user_id' => $user->getKey(),
                'role' => CommunityMemberRole::Member,
                'status' => CommunityMemberStatus::Pending,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Ketuk ganda bersamaan: permintaan pertama sudah tercatat.
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

        $member->delete();
    }

    /**
     * @throws ValidationException
     */
    public function approve(User $actor, CommunityMember $member): CommunityMember
    {
        if ($member->isActive()) {
            $this->reject('member', 'Pengguna ini sudah menjadi anggota.');
        }

        $member->update(['status' => CommunityMemberStatus::Active, 'joined_at' => now()]);

        $this->log($actor, $member, 'community_member_approved');

        return $member->refresh();
    }

    /**
     * Ubah peran anggota aktif.
     *
     * @throws ValidationException
     */
    public function setRole(User $actor, CommunityMember $member, CommunityMemberRole $role): CommunityMember
    {
        if (! $member->isActive()) {
            $this->reject('role', 'Terima permintaan bergabungnya terlebih dahulu.');
        }

        if ($member->role === CommunityMemberRole::Creator) {
            $this->reject('role', 'Peran creator tidak bisa diubah.');
        }

        if ($member->role === $role) {
            return $member;
        }

        // Admin boleh mundur sendiri, tetapi tidak mencabut admin lain.
        if ($role === CommunityMemberRole::Member && $member->user_id !== $actor->getKey() && ! $this->isCreator($actor, $member)) {
            $this->reject('role', 'Hanya creator yang bisa mencabut akses admin.');
        }

        $member->update(['role' => $role]);

        $this->log($actor, $member, $role === CommunityMemberRole::Admin ? 'community_admin_granted' : 'community_admin_revoked');

        return $member->refresh();
    }

    /**
     * Keluarkan anggota, atau tolak permintaan bergabung.
     *
     * @throws ValidationException
     */
    public function remove(User $actor, CommunityMember $member): void
    {
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
        $member->delete();

        $this->log($actor, $member, $event);
    }

    /** Super admin setara creator: ia yang menengahi bila ada masalah di komunitas. */
    private function isCreator(User $actor, CommunityMember $member): bool
    {
        return $actor->hasRole('super-admin')
            || $member->community->membershipOf($actor)?->role === CommunityMemberRole::Creator;
    }

    private function log(User $actor, CommunityMember $member, string $event): void
    {
        activity('community')
            ->causedBy($actor)
            ->performedOn($member->community)
            ->event($event)
            ->withProperties(['user_id' => $member->user_id])
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
