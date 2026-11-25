<?php

namespace App\Services;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\GuardianGrant;
use App\Models\MemberIdentity;
use App\Models\MemberInvitation;
use App\Models\User;
use App\Support\Enums\CommunityMemberRole;
use App\Support\Enums\CommunityMemberStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManualMemberService
{
    public function authorizeManager(User $actor, Community $community): void
    {
        $membership = $community->membershipOf($actor);
        if (! $actor->hasRole('super-admin') && ! ($membership?->isActive() && in_array($membership->role->value, ['creator', 'admin', 'officer'], true))) {
            throw new AuthorizationException('Anda tidak berwenang mengelola anggota komunitas ini.');
        }
    }

    public function authorizeSubject(User $actor, CommunityMember $member): void
    {
        $this->authorizeManager($actor, $member->community);
        $actorRole = $member->community->membershipOf($actor)?->role;
        if (in_array($member->role->value, ['creator', 'admin'], true)
            && ! $actor->hasRole('super-admin') && $actorRole !== CommunityMemberRole::Creator) {
            throw new AuthorizationException('Hanya pendiri yang dapat mengelola identitas pendiri atau admin.');
        }
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, Community $community, array $data): CommunityMember
    {
        $payload = Arr::only($data, ['name', 'birth_date', 'gender', 'external_reference', 'account_email']);
        ksort($payload);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $community, $data, $hash): CommunityMember {
            $community = Community::query()->whereKey($community->id)->lockForUpdate()->firstOrFail();
            $this->authorizeManager($actor, $community);
            $existing = $community->members()->where('creation_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                if ($existing->creation_hash !== $hash) {
                    $this->reject('idempotency_key', 'Kunci permintaan telah dipakai untuk data berbeda.');
                }

                return $existing->load('identity', 'user');
            }
            if (filled($data['external_reference'] ?? null) && $community->members()->where('external_reference', $data['external_reference'])->exists()) {
                $this->reject('external_reference', 'Referensi anggota sudah tercatat di komunitas ini.');
            }
            $this->checkAccount($community, $data['account_email'] ?? null);
            if (! filled($data['external_reference'] ?? null)) {
                $similar = $community->members()->whereHas('identity', function ($query) use ($data): void {
                    $query->whereRaw('lower(name) = ?', [mb_strtolower(trim($data['name']))]);
                    isset($data['birth_date']) ? $query->whereDate('birth_date', $data['birth_date']) : $query->whereNull('birth_date');
                })->exists();
                if ($similar) {
                    $this->reject('name', 'Nama dan tanggal lahir sudah tercatat. Pilih anggota tersebut, atau isi referensi berbeda untuk orang lain.');
                }
            }
            $identity = MemberIdentity::query()->create([
                ...Arr::only($data, ['name', 'birth_date', 'gender']), 'created_by' => $actor->getKey(), 'is_public' => false,
            ]);
            $member = $community->members()->create([
                'member_identity_id' => $identity->id, 'user_id' => null,
                'role' => CommunityMemberRole::Member, 'status' => CommunityMemberStatus::Active, 'joined_at' => now(),
                'external_reference' => $data['external_reference'] ?? null,
                'creation_key' => $data['idempotency_key'], 'creation_hash' => $hash, 'account_email' => $data['account_email'] ?? null,
            ]);
            $this->audit($actor, $member, 'manual_member_created', array_keys($data));

            return $member->load('identity', 'user');
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, CommunityMember $member, array $data): CommunityMember
    {
        return DB::transaction(function () use ($actor, $member, $data): CommunityMember {
            $member = $this->lockedMember($member);
            $this->authorizeSubject($actor, $member);
            if ($member->merged_into_member_id !== null || ! $member->isActive()) {
                $this->reject('member', 'Keanggotaan telah diarsipkan. Riwayat tetap tersimpan dan tidak dapat diaktifkan melalui editor profil.');
            }
            if ($member->user_id !== null) {
                $this->reject('member', 'Anggota sudah memiliki akun. Profil akun hanya dapat diubah pemilik atau wali terverifikasi.');
            }
            $this->checkAccount($member->community, $data['account_email'] ?? null, $member->id);
            if (filled($data['external_reference'] ?? null) && $member->community->members()->whereKeyNot($member->id)->where('external_reference', $data['external_reference'])->exists()) {
                $this->reject('external_reference', 'Referensi anggota sudah tercatat di komunitas ini.');
            }
            $member->identity?->update(Arr::only($data, ['name', 'birth_date', 'gender']));
            $member->fill(Arr::only($data, ['external_reference', 'account_email']));
            if ($member->isDirty('account_email')) {
                MemberInvitation::query()->where('community_member_id', $member->id)->where('kind', 'account')->whereNull('claimed_at')->update(['revoked_at' => now()]);
            }
            $member->save();
            $this->audit($actor, $member, 'manual_member_updated', array_keys($data));

            return $member->refresh()->load('identity', 'user');
        });
    }

    /** All community identity writes share the role-mutation lock order. */
    public function lockedMember(CommunityMember $member): CommunityMember
    {
        $community = Community::query()->whereKey($member->community_id)->lockForUpdate()->firstOrFail();
        $fresh = CommunityMember::query()->where('community_id', $community->id)->whereKey($member->id)->lockForUpdate()->firstOrFail();
        $fresh->setRelation('community', $community);
        $fresh->setRelation('identity', $fresh->member_identity_id === null ? null
            : MemberIdentity::query()->whereKey($fresh->member_identity_id)->lockForUpdate()->firstOrFail());

        return $fresh;
    }

    /** @return array<string, mixed> */
    public function details(CommunityMember $member): array
    {
        return [
            'id' => $member->id, 'user_id' => $member->user_id, 'member_identity_id' => $member->member_identity_id, 'merged_into_member_id' => $member->merged_into_member_id,
            'name' => $member->user->name ?? $member->identity?->name,
            'avatar_url' => $member->user?->avatarUrl(), 'role' => $member->role->value, 'role_label' => $member->role->label(),
            'status' => $member->status->value, 'birth_date' => $member->identity?->birth_date?->toDateString(),
            'gender' => $member->identity?->gender?->value,
            'external_reference' => $member->external_reference, 'account_email' => $member->account_email,
            'joined_at' => $member->joined_at?->toIso8601String(), 'member_number' => $member->cardNumber(),
            'guardian_grants' => GuardianGrant::query()->where('member_identity_id', $member->member_identity_id)->where('community_member_id', $member->id)->get()->map(fn (GuardianGrant $grant): array => [
                'id' => $grant->id, 'email' => User::query()->find($grant->guardian_user_id)?->email,
                'verified_at' => $grant->verified_at->toIso8601String(), 'revoked_at' => $grant->revoked_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /** @param list<string> $fields */
    public function audit(User $actor, CommunityMember $member, string $event, array $fields = []): void
    {
        activity('community')->causedBy($actor)->performedOn($member->community)->event($event)
            ->withProperties(['member_id' => $member->id, 'identity_id' => $member->member_identity_id, 'fields' => $fields])->log($event);
    }

    private function checkAccount(Community $community, ?string $email, ?int $except = null): void
    {
        if ($email === null) {
            return;
        }
        $account = User::query()->whereRaw('lower(email) = ?', [strtolower($email)])->first();
        $query = $community->members()->where(function ($query) use ($email, $account): void {
            $query->where('account_email', strtolower($email));
            if ($account !== null) {
                $query->orWhere('user_id', $account->getKey());
            }
        });
        if ($except !== null) {
            $query->whereKeyNot($except);
        }
        if ($query->exists()) {
            $this->reject('account_email', 'Email tersebut sudah terkait anggota di komunitas ini. Gunakan anggota yang sudah tercatat.');
        }
    }

    public function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
