<?php

namespace App\Services;

use App\Mail\MemberInvitationMail;
use App\Models\CommunityMember;
use App\Models\GuardianGrant;
use App\Models\MemberIdentity;
use App\Models\MemberInvitation;
use App\Models\User;
use App\Support\Enums\CommunityMemberStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class GuardianService
{
    public function __construct(private readonly ManualMemberService $members) {}

    public function invite(User $actor, CommunityMember $member, string $kind, string $email): MemberInvitation
    {
        if (! $member->isActive() || $member->merged_into_member_id !== null) {
            $this->members->reject('member', 'Undangan hanya untuk keanggotaan aktif.');
        }
        $identity = $member->identity ?? throw new \LogicException('Identitas anggota belum tersedia.');
        if ($identity->user_id !== $actor->getKey()) {
            $this->members->authorizeSubject($actor, $member);
            if ($identity->user_id !== null) {
                throw new AuthorizationException('Undangan untuk pemilik akun harus dibuat oleh pemilik akun tersebut.');
            }
        }
        if ($kind === 'account' && $identity->user_id !== null) {
            $this->members->reject('member', 'Identitas sudah terhubung ke akun.');
        }
        $email = strtolower(trim($email));
        app(SensitiveMailGuard::class)->assertAvailable();

        return DB::transaction(function () use ($actor, $member, $identity, $kind, $email): MemberInvitation {
            MemberIdentity::query()->whereKey($identity->id)->lockForUpdate()->firstOrFail();
            MemberInvitation::query()->where('member_identity_id', $identity->id)->where('kind', $kind)
                ->where('email', $email)->whereNull('claimed_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $token = Str::random(40);
            $invitation = MemberInvitation::query()->create([
                'member_identity_id' => $identity->id, 'community_member_id' => $member->id,
                'invited_by' => $actor->getKey(), 'kind' => $kind, 'email' => $email,
                'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours(24),
            ]);
            try {
                Mail::to($email)->send(new MemberInvitationMail($invitation, $token));
            } catch (\Throwable) {
                throw new HttpException(503, 'Pengiriman undangan belum berhasil. Coba lagi nanti.');
            }
            $this->members->audit($actor, $member, $kind.'_invitation_created');

            return $invitation;
        });
    }

    public function claim(User $actor, MemberInvitation $invitation, string $token): MemberIdentity
    {
        return DB::transaction(function () use ($actor, $invitation, $token): MemberIdentity {
            $invitation = MemberInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            if (! $actor->hasVerifiedEmail() || strtolower($actor->email) !== $invitation->email
                || $invitation->claimed_at !== null || $invitation->revoked_at !== null || $invitation->expires_at->isPast()
                || ! $invitation->member->isActive()
                || ! hash_equals($invitation->token_hash, hash('sha256', $token))) {
                $this->members->reject('token', 'Undangan tidak dapat digunakan. Pastikan email terverifikasi dan kode masih berlaku.');
            }
            $issuer = User::query()->findOrFail($invitation->invited_by);
            if ($invitation->identity->user_id !== $issuer->getKey()) {
                $this->members->authorizeSubject($issuer, $invitation->member);
            }
            $identity = MemberIdentity::query()->lockForUpdate()->findOrFail($invitation->member_identity_id);
            if ($invitation->kind === 'account') {
                $identity = $this->linkAccount($actor, $identity, $invitation->id);
            } else {
                if ($identity->user_id === $actor->getKey()) {
                    $this->members->reject('token', 'Pemilik akun tidak perlu menjadi wali bagi dirinya sendiri.');
                }
                GuardianGrant::query()->updateOrCreate([
                    'member_identity_id' => $identity->id, 'guardian_user_id' => $actor->getKey(),
                ], ['community_member_id' => $invitation->community_member_id, 'verified_at' => now(), 'revoked_at' => null]);
            }
            $invitation->update(['claimed_at' => now()]);
            $this->members->audit($actor, $invitation->member, $invitation->kind.'_invitation_claimed');

            return $identity->refresh();
        });
    }

    public function authorizeDependent(User $actor, MemberIdentity $identity): void
    {
        if (! GuardianGrant::query()->where('member_identity_id', $identity->id)->where('guardian_user_id', $actor->getKey())->whereNull('revoked_at')->exists()) {
            throw new AuthorizationException('Akses wali belum terverifikasi atau telah dicabut.');
        }
    }

    /** @param array<string, mixed> $data */
    public function updateDependent(User $actor, MemberIdentity $identity, array $data): MemberIdentity
    {
        $this->authorizeDependent($actor, $identity);

        return DB::transaction(function () use ($actor, $identity, $data): MemberIdentity {
            $identity = MemberIdentity::query()->lockForUpdate()->findOrFail($identity->id);
            $identity->update(Arr::only($data, ['name', 'birth_date', 'gender', 'is_public']));
            if ($identity->user_id !== null) {
                $identity->user?->update(Arr::only($data, ['name', 'birth_date', 'gender']));
            }
            foreach ($identity->memberships as $member) {
                $this->members->audit($actor, $member, 'guardian_dependent_updated', array_keys($data));
            }

            return $identity->refresh();
        });
    }

    public function revoke(User $actor, GuardianGrant $grant): void
    {
        if ($grant->guardian_user_id !== $actor->getKey() && $grant->identity->user_id !== $actor->getKey()) {
            $this->members->authorizeSubject($actor, $grant->member);
        }
        $grant->update(['revoked_at' => now()]);
        $identity = $grant->identity;
        if (($identity->user_id === null || ($identity->birth_date !== null && $identity->birth_date->age < 18))
            && ! $identity->guardianGrants()->whereNull('revoked_at')->exists()) {
            $identity->update(['is_public' => false]);
        }
        foreach ($grant->identity->memberships as $member) {
            $this->members->audit($actor, $member, 'guardian_grant_revoked');
        }
    }

    /** @return array<string, mixed> */
    public function details(MemberIdentity $identity): array
    {
        return [
            'id' => $identity->id, 'name' => $identity->name, 'user_id' => $identity->user_id,
            'birth_date' => $identity->birth_date?->toDateString(), 'gender' => $identity->gender?->value,
            'is_public' => $identity->is_public,
            'memberships' => $identity->memberships()->with('community')->get()->map(fn (CommunityMember $member): array => [
                'id' => $member->id, 'community_id' => $member->community_id, 'community_name' => $member->community->name,
                'status' => $member->status->value, 'role' => $member->role->value, 'member_number' => $member->cardNumber(),
            ])->all(),
        ];
    }

    private function revokeManualDelegations(User $actor, MemberIdentity $source, int $invitationId): void
    {
        // A community manager's manual-record grant never becomes authority over
        // the linked login account. Its owner can issue new invitations explicitly.
        $source->guardianGrants()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        MemberInvitation::query()->where('member_identity_id', $source->id)->whereKeyNot($invitationId)
            ->whereNull('claimed_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
        foreach ($source->memberships as $member) {
            $this->members->audit($actor, $member, 'manual_delegations_revoked_on_account_link');
        }
    }

    public function grantId(User $actor, MemberIdentity $identity): ?int
    {
        return GuardianGrant::query()->where('member_identity_id', $identity->id)
            ->where('guardian_user_id', $actor->getKey())->whereNull('revoked_at')->value('id');
    }

    private function demographicValue(User|MemberIdentity $subject, string $field): ?string
    {
        return $field === 'birth_date' ? $subject->birth_date?->toDateString() : $subject->gender?->value;
    }

    private function reconcileAccountDemographics(User $actor, MemberIdentity $identity): void
    {
        foreach (['birth_date', 'gender'] as $field) {
            $accountValue = $this->demographicValue($actor, $field);
            $identityValue = $this->demographicValue($identity, $field);
            if ($accountValue !== null && $identityValue !== null && $accountValue !== $identityValue) {
                $this->members->reject($field, 'Data anggota berbeda dari akun. Perbaiki data terlebih dahulu; pengaitan tidak menimpa data diam-diam.');
            }
            if ($accountValue === null && $identityValue !== null) {
                $actor->setAttribute($field, $identityValue);
            } elseif ($identityValue === null && $accountValue !== null) {
                $identity->setAttribute($field, $accountValue);
            }
        }
        $actor->save();
        $identity->save();
    }

    private function linkAccount(User $actor, MemberIdentity $source, int $invitationId): MemberIdentity
    {
        $actor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
        if ($source->user_id !== null || $source->merged_into_id !== null) {
            $this->members->reject('token', 'Identitas sudah terhubung ke akun.');
        }
        $target = MemberIdentity::query()->where('user_id', $actor->getKey())->lockForUpdate()->first();
        $this->revokeManualDelegations($actor, $source, $invitationId);
        if ($target === null) {
            $this->reconcileAccountDemographics($actor, $source);
            $source->update(['user_id' => $actor->getKey(), 'name' => $actor->name, 'is_public' => false]);
            $source->memberships()->update(['user_id' => $actor->getKey(), 'account_email' => $actor->email]);

            return $source;
        }
        foreach (['birth_date', 'gender'] as $field) {
            $sourceValue = $this->demographicValue($source, $field);
            $targetValue = $this->demographicValue($target, $field);
            if ($sourceValue !== null && $targetValue !== null && $sourceValue !== $targetValue) {
                $this->members->reject($field, 'Data anggota berbeda dari akun. Perbaiki data terlebih dahulu; pengaitan tidak menimpa data diam-diam.');
            }
            if ($targetValue === null && $sourceValue !== null) {
                $target->setAttribute($field, $sourceValue);
            }
        }
        $target->save();
        $this->reconcileAccountDemographics($actor, $target);
        foreach ($source->memberships()->lockForUpdate()->get() as $sourceMember) {
            $canonicalMember = $target->memberships()->where('community_id', $sourceMember->community_id)->lockForUpdate()->first();
            if ($canonicalMember !== null) {
                $sourceMember->update(['status' => CommunityMemberStatus::Archived, 'merged_into_member_id' => $canonicalMember->id]);
                $this->members->audit($actor, $sourceMember, 'member_identity_merged', ['canonical_member_id:'.$canonicalMember->id]);
            } else {
                $sourceMember->update(['member_identity_id' => $target->id, 'user_id' => $actor->getKey(), 'account_email' => $actor->email]);
            }
        }
        MemberInvitation::query()->where('member_identity_id', $source->id)->update(['member_identity_id' => $target->id]);
        $source->update(['merged_into_id' => $target->id, 'is_public' => false]);

        return $target;
    }
}
