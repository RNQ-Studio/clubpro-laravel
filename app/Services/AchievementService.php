<?php

namespace App\Services;

use App\Models\AchievementEntry;
use App\Models\AchievementRecord;
use App\Models\AchievementRule;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\LedgerJournal;
use App\Models\MemberIdentity;
use App\Models\TrainingAttendance;
use App\Models\TrainingFinalEvent;
use App\Models\TrainingPurchase;
use App\Models\User;
use App\Policies\AchievementRecordPolicy;
use App\Policies\AchievementRulePolicy;
use App\Support\Enums\CommunityMemberRole;
use App\Support\Enums\CommunityMemberStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Immutable point entries never touch rupiah accounts, reservations, or payout. */
class AchievementService
{
    public function __construct(private readonly LedgerService $locks, private readonly MoneyMovementService $movement) {}

    public function rule(?Carbon $at = null): AchievementRule
    {
        return AchievementRule::query()->where('effective_at', '<=', ($at ?? now())->format('Y-m-d H:i:s.uP'))->orderByDesc('version')->firstOrFail();
    }

    /** @return array<string,mixed> */
    public function options(): array
    {
        $rule = $this->rule();
        $ids = ['u12', '12_15', '16_18', '19_35', '36_50', '51_plus'];
        $groups = [];
        foreach ($ids as $i => $id) {
            $groups[] = ['id' => $id, 'label' => $i === 0 ? 'Di bawah '.$rule->age_boundaries[0] : ($i === 5 ? $rule->age_boundaries[4].' tahun ke atas' : $rule->age_boundaries[$i - 1].'–'.($rule->age_boundaries[$i] - 1).' tahun')];
        }
        $groups[] = ['id' => 'unknown', 'label' => 'Usia belum lengkap'];

        return ['version' => $rule->version, 'points' => $rule->points, 'age_boundaries' => $rule->age_boundaries, 'effective_at' => $rule->effective_at->toIso8601String(), 'age_groups' => $groups, 'genders' => ['male', 'female', 'unknown'], 'periods' => ['month', 'all'], 'tie_breaker' => 'dense_rank_points_then_'.config('achievements.ranking_tie_order', 'id'), 'default_period' => config('achievements.default_period', 'month')];
    }

    /** @param array<string,mixed> $data */
    public function configure(User $actor, array $data): AchievementRule
    {
        abort_unless(app(AchievementRulePolicy::class)->manage($actor), 403, 'Hanya pengelola aplikasi dapat mengubah aturan pusat.');

        return DB::transaction(function () use ($actor, $data): AchievementRule {
            $this->locks->lockOperation('achievement-rules');
            abort_unless(mb_strlen(trim($data['reason'])) >= 10, 422, 'Alasan perubahan minimal 10 karakter.');
            $current = $this->rule();
            abort_unless($current->version === (int) $data['expected_version'], 409, 'Aturan berubah. Muat ulang.');
            foreach (['training_attendance', 'training_purchase', 'participation', 'champion'] as $type) {
                abort_unless(isset($data['points'][$type]) && is_int($data['points'][$type]) && $data['points'][$type] >= 0 && $data['points'][$type] <= 1000000, 422, 'Poin harus bilangan bulat antara 0 dan 1.000.000.');
            }
            $bounds = $data['age_boundaries'];
            abort_unless(count($bounds) === 5 && count(array_unique($bounds)) === 5 && $bounds === array_values(array_filter($bounds, fn ($v) => is_int($v) && $v > 0 && $v <= 150)), 422, 'Batas usia tidak valid.');
            $sorted = $bounds;
            sort($sorted);
            abort_unless($sorted === $bounds, 422, 'Batas usia harus meningkat.');
            $rule = AchievementRule::query()->create(['version' => $current->version + 1, 'points' => $data['points'], 'age_boundaries' => $bounds, 'effective_at' => now(), 'changed_by' => $actor->id, 'reason' => trim($data['reason']), 'created_at' => now()]);
            activity('achievement')->causedBy($actor)->event('rules_updated')->withProperties(['version' => $rule->version, 'reason' => $rule->reason])->log('Aturan poin pusat diperbarui');

            return $rule;
        }, 3);
    }

    public function canManage(User $actor, Community $community, bool $correction = false): bool
    {
        $member = $community->membershipOf($actor);

        return $actor->is_active && $member !== null && $member->isActive() && in_array($member->role, $correction ? [CommunityMemberRole::Creator, CommunityMemberRole::Admin] : [CommunityMemberRole::Creator, CommunityMemberRole::Admin, CommunityMemberRole::Officer, CommunityMemberRole::Coach], true) && $this->movement->activeMember($member);
    }

    /** Authoritative S04 sources are consumed within their originating transaction. */
    public function consumeTraining(TrainingFinalEvent $event): void
    {
        if ($event->created_at->isFuture()) {
            return;
        }
        DB::transaction(function () use ($event): void {
            $this->locks->lockOperation('achievement-source:'.$event->source_id);
            if ($event->type === 'training_purchase_final') {
                $source = TrainingPurchase::query()->find($event->source_id);
                if ($source === null || $source->created_at->isFuture() || $source->community_id !== $event->community_id || $source->member_identity_id !== $event->member_identity_id || ! LedgerJournal::query()->whereKey($source->journal_id)->where('operation', 'reserve_reserved')->where('reference', 'training_purchase:'.$source->id)->where('community_id', $event->community_id)->exists()) {
                    return;
                }
                $this->award('training_purchase:'.$source->id, $event->community_id, $event->member_identity_id, 'training_purchase', $source->id, $event->created_at, $source->buyer_id);
            } elseif (in_array($event->type, ['training_attendance_final', 'training_attendance_corrected'], true)) {
                $source = TrainingAttendance::query()->find($event->source_id);
                if ($source === null || $source->community_id !== $event->community_id || $source->member_identity_id !== $event->member_identity_id || ! in_array($source->status, ['confirmed', 'corrected'], true) || $source->confirmed_at === null || $source->confirmed_at->gt(now()->addSecond()) || ! LedgerJournal::query()->whereKey($source->journal_id)->where('operation', 'training_attendance')->where('reference', $source->id)->where('community_id', $event->community_id)->exists()) {
                    return;
                }
                $finalEvent = TrainingFinalEvent::query()->where('type', 'training_attendance_final')->where('source_id', $source->id)->where('community_id', $event->community_id)->where('member_identity_id', $event->member_identity_id)->first();
                if ($finalEvent === null || $finalEvent->created_at->isFuture()) {
                    return;
                }
                $entry = $this->award('training_attendance:'.$source->id, $event->community_id, $event->member_identity_id, 'training_attendance', $source->id, $finalEvent->created_at, $source->confirmed_by);
                if ($source->status === 'corrected' && $source->corrected_at !== null && LedgerJournal::query()->whereKey($source->correction_journal_id)->where('operation', 'training_correction')->where('metadata->original_journal_id', $source->journal_id)->exists()) {
                    $this->reverse($entry, 'training_attendance_corrected:'.$source->id, $source->corrected_by);
                }
            }
        }, 3);
    }

    public function syncTraining(): void
    {
        foreach (TrainingFinalEvent::query()->orderBy('created_at')->orderBy('id')->lazy(100) as $event) {
            $this->consumeTraining($event);
        }
    }

    private function award(string $key, int $community, int $identity, string $type, string $source, Carbon $occurred, ?int $actor): AchievementEntry
    {
        $rule = $this->rule($occurred);

        return AchievementEntry::query()->firstOrCreate(['source_key' => $key], ['community_id' => $community, 'member_identity_id' => $identity, 'type' => $type, 'source_id' => $source, 'points' => $rule->points[$type], 'rule_version' => $rule->version, 'actor_id' => $actor, 'occurred_at' => $occurred, 'created_at' => now()]);
    }

    private function reverse(AchievementEntry $entry, string $key, ?int $actor): void
    {
        AchievementEntry::query()->firstOrCreate(['source_key' => $key], ['community_id' => $entry->community_id, 'member_identity_id' => $entry->member_identity_id, 'type' => $entry->type.'_correction', 'source_id' => $entry->source_id, 'points' => -$entry->points, 'rule_version' => $entry->rule_version, 'actor_id' => $actor, 'occurred_at' => $entry->occurred_at, 'created_at' => now()]);
    }

    /** @param array<string,mixed> $data */
    public function record(User $actor, Community $community, array $data): AchievementRecord
    {
        abort_unless(app(AchievementRecordPolicy::class)->create($actor, $community), 403, 'Tidak berwenang mencatat prestasi komunitas.');
        abort_unless(in_array($data['type'], ['participation', 'champion'], true) && mb_strlen(trim($data['title'])) > 0 && mb_strlen(trim($data['reference'])) >= 3, 422, 'Jenis atau bukti referensi prestasi tidak valid.');

        return DB::transaction(function () use ($actor, $community, $data): AchievementRecord {
            $this->locks->lockOperation('achievement-manual:'.$community->id.':'.$data['idempotency_key']);
            $hash = $this->locks->payloadHash($data);
            $existing = AchievementRecord::query()->where('community_id', $community->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                abort_unless($existing->created_by === $actor->id && hash_equals($existing->payload_hash, $hash), 409, 'Referensi retry dipakai data lain.');

                return $existing;
            }
            $member = CommunityMember::query()->where('community_id', $community->id)->whereNull('merged_into_member_id')->findOrFail($data['member_id']);
            abort_unless($member->status === CommunityMemberStatus::Active && $member->identity !== null && $member->identity->merged_into_id === null, 422, 'Anggota tidak aktif atau identitas tidak valid.');
            $businessKey = hash('sha256', $community->id.':'.$member->member_identity_id.':'.$data['type'].':'.mb_strtolower(trim($data['reference'])));
            $this->locks->lockOperation('achievement-business:'.$businessKey);
            $sameEvent = AchievementRecord::query()->where('community_id', $community->id)->whereIn('member_identity_id', $this->identityIds($member->identity))->where('type', $data['type'])->whereRaw('lower(trim(reference)) = ?', [mb_strtolower(trim($data['reference']))])->first();
            abort_if($sameEvent !== null, 409, 'Prestasi untuk anggota dan referensi kegiatan ini sudah dicatat. Gunakan koreksi jika diperlukan.');
            $date = Carbon::parse($data['occurred_at'], 'Asia/Jakarta')->startOfDay();
            if ($date->isSameDay(now('Asia/Jakarta'))) {
                $date = now('Asia/Jakarta');
            }
            abort_if($date->isFuture() || $date->lt(Carbon::parse('1970-01-01')), 422, 'Tanggal kegiatan tidak valid.');
            $record = AchievementRecord::query()->create(['business_key' => $businessKey, 'community_id' => $community->id, 'member_id' => $member->id, 'member_identity_id' => $member->member_identity_id, 'type' => $data['type'], 'title' => trim($data['title']), 'reference' => trim($data['reference']), 'occurred_at' => $date, 'status' => 'final', 'version' => 1, 'created_by' => $actor->id, 'idempotency_key' => $data['idempotency_key'], 'payload_hash' => $hash]);
            $this->award('manual:'.$record->id, $community->id, $member->member_identity_id, $record->type, $record->id, $date, $actor->id);
            activity('achievement')->performedOn($community)->causedBy($actor)->event('recorded')->withProperties(['record_id' => $record->id, 'reference' => $record->reference])->log('Prestasi dicatat');

            return $record;
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function correct(User $actor, Community $community, AchievementRecord $record, array $data): AchievementRecord
    {
        abort_unless($record->community_id === $community->id, 404);
        abort_unless(app(AchievementRecordPolicy::class)->correct($actor, $community), 403, 'Hanya pendiri/admin komunitas dapat mengoreksi prestasi.');

        abort_unless(mb_strlen(trim($data['reason'])) >= 10, 422, 'Alasan koreksi minimal 10 karakter.');

        return DB::transaction(function () use ($actor, $record, $data): AchievementRecord {
            $record = AchievementRecord::query()->lockForUpdate()->findOrFail($record->id);
            if ($record->status === 'corrected') {
                abort_unless($record->corrected_by === $actor->id && $record->correction_reason === trim($data['reason']), 409, 'Prestasi sudah dikoreksi.');

                return $record;
            }
            abort_unless($record->version === (int) $data['expected_version'], 409, 'Prestasi berubah. Muat ulang.');
            $entry = AchievementEntry::query()->where('source_key', 'manual:'.$record->id)->firstOrFail();
            $this->reverse($entry, 'manual_correction:'.$record->id, $actor->id);
            $record->update(['status' => 'corrected', 'version' => $record->version + 1, 'corrected_by' => $actor->id, 'correction_reason' => trim($data['reason']), 'corrected_at' => now()]);
            activity('achievement')->causedBy($actor)->performedOn(Community::query()->findOrFail($record->community_id))->event('corrected')->withProperties(['record_id' => $record->id, 'reason' => $data['reason']])->log('Koreksi prestasi');

            return $record;
        }, 3);
    }

    public function canonical(int $id): ?MemberIdentity
    {
        $seen = [];
        while (! isset($seen[$id])) {
            $seen[$id] = true;
            $identity = MemberIdentity::query()->with('user')->find($id);
            if ($identity === null || $identity->merged_into_id === null) {
                return $identity;
            }
            $id = $identity->merged_into_id;
        }

        return null;
    }

    /** @return list<int> */
    public function identityIds(MemberIdentity $target): array
    {
        $ids = [$target->id];
        do {
            $new = MemberIdentity::query()->whereIn('merged_into_id', $ids)->whereNotIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $ids = [...$ids, ...$new];
        } while ($new !== []);

        return $ids;
    }

    public function publiclyVisible(MemberIdentity $identity): bool
    {
        return $identity->merged_into_id === null && $identity->is_public && ($identity->user_id !== null && $identity->user?->is_active && ($identity->birth_date === null || $identity->birth_date->age >= 18) || $identity->guardianGrants()->whereNotNull('verified_at')->whereNull('revoked_at')->exists());
    }

    public function ageGroup(MemberIdentity $identity, Carbon $asOf, AchievementRule $rule): string
    {
        if ($identity->birth_date === null || $identity->birth_date->gt($asOf)) {
            return 'unknown';
        }
        $age = (int) $identity->birth_date->diffInYears($asOf, false);
        foreach ($rule->age_boundaries as $i => $bound) {
            if ($age < $bound) {
                return ['u12', '12_15', '16_18', '19_35', '36_50'][$i];
            }
        }

        return '51_plus';
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function rankings(array $filters): array
    {
        $period = $filters['period'] ?? config('achievements.default_period', 'month');
        $month = Carbon::createFromFormat('!Y-m', $filters['month'] ?? now('Asia/Jakarta')->format('Y-m'), 'Asia/Jakarta');
        $asOf = $period === 'month' ? $month->copy()->endOfMonth() : Carbon::parse($filters['as_of'] ?? now('Asia/Jakarta')->toDateString(), 'Asia/Jakarta')->endOfDay();
        abort_if($asOf->gt(now('Asia/Jakarta')->endOfMonth()), 422, 'Periode ranking belum tersedia.');
        $rule = $this->rule();
        $query = AchievementEntry::query()->where('occurred_at', '<=', ($period === 'month' ? min($asOf, now()) : $asOf)->format('Y-m-d H:i:s.uP'))
            ->when($period === 'month', fn (Builder $q) => $q->where('occurred_at', '>=', $month))
            ->when($filters['community_id'] ?? null, fn (Builder $q, $id) => $q->where('community_id', $id));
        $groups = (clone $query)->selectRaw('community_id, member_identity_id, SUM(points) as total_points')->groupBy('community_id', 'member_identity_id')->havingRaw('SUM(points) > 0')->orderBy('community_id')->orderBy('member_identity_id')->cursor();
        /** @var array<int,array<string,mixed>> $totals */
        $totals = [];
        $identities = [];
        $communities = [];
        $scope = $filters['scope'] ?? 'communities';
        foreach ($groups as $group) {
            $identity = $identities[$group->member_identity_id] ??= $this->canonical((int) $group->member_identity_id);
            $community = $communities[$group->community_id] ??= Community::query()->approved()->find($group->community_id);
            if ($identity === null || $community === null || $identity->merged_into_id !== null) {
                continue;
            }
            $gender = $identity->gender === null ? 'unknown' : $identity->gender->value;
            $age = $this->ageGroup($identity, $asOf, $rule);
            if (isset($filters['gender']) && $filters['gender'] !== $gender || isset($filters['age_group']) && $filters['age_group'] !== $age) {
                continue;
            }
            if ($scope === 'members' && (! $this->publiclyVisible($identity) || ! CommunityMember::query()->where('community_id', $community->id)->where('member_identity_id', $identity->id)->whereNull('merged_into_member_id')->where('status', CommunityMemberStatus::Active)->exists())) {
                continue;
            }
            $key = $scope === 'communities' ? $community->id : $identity->id;
            $totals[$key] ??= ['points' => 0, 'name' => $scope === 'communities' ? $community->name : $identity->name, ...($scope === 'communities' ? ['community_id' => $community->id] : ['identity_id' => $identity->id, 'gender' => $gender, 'age_group' => $age, 'community' => ['id' => $community->id, 'name' => $community->name]])];
            $totals[$key]['points'] += (int) $group->getAttribute('total_points');
        }
        uksort($totals, fn ($a, $b) => $totals[$b]['points'] <=> $totals[$a]['points'] ?: (config('achievements.ranking_tie_order', 'id') === 'name' ? strcasecmp($totals[$a]['name'], $totals[$b]['name']) : 0) ?: $a <=> $b);
        $rank = 0;
        $previous = null;
        foreach ($totals as &$row) {
            if ($previous !== $row['points']) {
                $rank++;
            }$row['rank'] = $rank;
            $previous = $row['points'];
        }unset($row);
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 20);
        $count = count($totals);

        return ['items' => array_slice(array_values($totals), ($page - 1) * $perPage, $perPage), 'current_page' => $page, 'last_page' => max(1, (int) ceil($count / $perPage)), 'total' => $count, 'period' => $period, 'as_of' => $asOf->toDateString(), 'rule_version' => $rule->version];
    }

    /** @return array<string,mixed> */
    public function recordData(AchievementRecord $record): array
    {
        $points = (int) AchievementEntry::query()->where('source_id', $record->id)->sum('points');

        return ['id' => $record->id, 'member_id' => $record->member_id, 'member_name' => MemberIdentity::query()->findOrFail($record->member_identity_id)->name, 'type' => $record->type, 'title' => $record->title, 'reference' => $record->reference, 'occurred_at' => $record->occurred_at->toIso8601String(), 'points' => $points, 'status' => $record->status, 'version' => $record->version, 'created_by' => $record->created_by, 'created_by_name' => User::query()->find($record->created_by)?->name, 'created_at' => $record->created_at->toIso8601String(), 'corrected_by' => $record->corrected_by, 'corrected_by_name' => $record->corrected_by === null ? null : User::query()->find($record->corrected_by)?->name, 'corrected_at' => $record->corrected_at?->toIso8601String(), 'correction_reason' => $record->correction_reason];
    }
}
