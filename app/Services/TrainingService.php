<?php

namespace App\Services;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\LedgerAccount;
use App\Models\LedgerJournal;
use App\Models\LedgerReservation;
use App\Models\MemberIdentity;
use App\Models\TrainingAttendance;
use App\Models\TrainingCatalog;
use App\Models\TrainingFinalEvent;
use App\Models\TrainingLocation;
use App\Models\TrainingPurchase;
use App\Models\TrainingSession;
use App\Models\User;
use App\Support\Enums\CommunityMemberRole;
use App\Support\Enums\CommunityMemberStatus;
use App\Support\Enums\CommunityStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** Training is a consumer of S03 custody lots and reservations, never a second wallet. */
class TrainingService
{
    public function __construct(private readonly LedgerService $ledger, private readonly BillingService $billing) {}

    public function member(User $user, Community $community): CommunityMember
    {
        $member = $community->membershipOf($user);
        abort_unless($member !== null && $member->merged_into_member_id === null && $community->status === CommunityStatus::Approved, 403, 'Anda bukan anggota komunitas ini.');

        return $member;
    }

    public function active(CommunityMember $member, Community $community): bool
    {
        return $member->status === CommunityMemberStatus::Active
            && $this->billing->entitlement($this->billing->platformSubject($community))['active']
            && $this->billing->entitlement($this->billing->memberSubject($member))['active'];
    }

    private function requireActive(CommunityMember $member, Community $community): void
    {
        abort_unless($this->active($member, $community), 402, 'Keanggotaan atau langganan komunitas tidak aktif. Buka pembayaran untuk melanjutkan.');
    }

    private function lockCommunity(Community $community): Community
    {
        return Community::query()->lockForUpdate()->findOrFail($community->id);
    }

    private function founder(User $actor, Community $community): CommunityMember
    {
        Gate::forUser($actor)->authorize('configureTraining', $community);
        $member = $this->member($actor, $community);
        abort_unless($member->role === CommunityMemberRole::Creator, 403, 'Hanya pendiri dapat mengubah pengaturan latihan.');
        $this->requireActive($member, $community);

        return $member;
    }

    public function catalog(Community $community): TrainingCatalog
    {
        return TrainingCatalog::query()->firstOrCreate(['community_id' => $community->id], ['version' => 0, 'enabled' => false, 'packages' => []]);
    }

    /** @return array<string,mixed> */
    public function catalogData(TrainingCatalog $catalog): array
    {
        return ['version' => $catalog->version, 'enabled' => $catalog->enabled, 'packages' => array_map(fn (array $package): array => [...$package, 'session_floor' => intdiv($package['price'], $package['sessions']), 'session_last' => $package['price'] - intdiv($package['price'], $package['sessions']) * ($package['sessions'] - 1)], $catalog->packages)];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function saveCatalog(User $actor, Community $community, array $data): array
    {
        return DB::transaction(function () use ($actor, $community, $data): array {
            $community = $this->lockCommunity($community);
            $this->founder($actor, $community);
            $catalog = $this->catalog($community);
            abort_unless($catalog->version === (int) $data['expected_version'], 409, 'Harga sudah berubah. Muat ulang sebelum menyimpan.');
            $packages = array_map(fn (array $p): array => ['sessions' => (int) $p['sessions'], 'price' => (int) $p['price']], $data['packages']);
            usort($packages, fn (array $a, array $b): int => $a['sessions'] <=> $b['sessions']);
            abort_unless(array_column($packages, 'sessions') === [7, 14, 21], 422, 'Isi tiga paket 7, 14, dan 21 latihan.');
            foreach ($packages as $index => $package) {
                $this->ledger->assertAmount($package['price']);
                abort_unless($package['price'] >= $package['sessions'], 422, 'Harga harus menyediakan minimal Rp1 per sesi.');
                if ($index > 0) {
                    abort_unless($packages[$index - 1]['price'] * $package['sessions'] > $package['price'] * $packages[$index - 1]['sessions'], 422, 'Paket lebih besar harus lebih murah per sesi.');
                }
            }
            $ceiling = min(array_map(fn (array $p): int => intdiv($p['price'], $p['sessions']), $packages));
            foreach (TrainingLocation::query()->where('community_id', $community->id)->where('active', true)->get() as $location) {
                abort_unless(array_sum(array_column($location->splits, 'amount')) <= $ceiling, 422, 'Harga baru lebih rendah dari pembagian lokasi. Sesuaikan pembagian dahulu.');
            }
            $catalog->update(['enabled' => $data['enabled'], 'packages' => $packages, 'version' => $catalog->version + 1, 'updated_by' => $actor->id]);
            $this->audit($actor, $community, 'training_catalog_updated', ['version' => $catalog->version, 'packages' => $packages]);

            return $this->catalogData($catalog);
        }, 3);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function saveLocation(User $actor, Community $community, array $data, ?TrainingLocation $location = null): array
    {
        return DB::transaction(function () use ($actor, $community, $data, $location): array {
            $community = $this->lockCommunity($community);
            $this->founder($actor, $community);
            if ($location !== null) {
                $location = TrainingLocation::query()->where('community_id', $community->id)->lockForUpdate()->findOrFail($location->id);
                abort_unless($location->version === (int) $data['expected_version'], 409, 'Lokasi sudah berubah. Muat ulang.');
            }
            $managers = array_values(array_unique(array_map('intval', $data['manager_member_ids'])));
            foreach ($managers as $id) {
                $manager = CommunityMember::query()->where('community_id', $community->id)->where('status', CommunityMemberStatus::Active)->findOrFail($id);
                abort_unless(in_array($manager->role, [CommunityMemberRole::Creator, CommunityMemberRole::Admin, CommunityMemberRole::Officer, CommunityMemberRole::Coach], true), 422, 'Pengelola harus pendiri, admin, pengurus atau pelatih aktif.');
            }
            $splits = [];
            foreach ($data['splits'] as $split) {
                $recipient = CommunityMember::query()->with('identity')->where('community_id', $community->id)->where('status', CommunityMemberStatus::Active)->whereNull('merged_into_member_id')->findOrFail($split['member_id']);
                abort_if($recipient->member_identity_id === null || array_key_exists($recipient->id, $splits), 422, 'Penerima tidak valid atau berulang.');
                $splits[$recipient->id] = ['member_id' => $recipient->id, 'identity_id' => $recipient->member_identity_id, 'name' => $recipient->identity->name ?? '', 'role' => $recipient->role->value, 'amount' => (int) $split['amount']];
            }
            $catalog = $this->catalog($community);
            abort_unless(count($catalog->packages) === 3, 422, 'Atur katalog paket sebelum lokasi.');
            $ceilings = array_map(fn (array $p): int => intdiv($p['price'], $p['sessions']), $catalog->packages);
            foreach (TrainingPurchase::query()->where('community_id', $community->id)->where('remaining_sessions', '>', 0)->get() as $purchase) {
                $ceilings[] = intdiv($purchase->price, $purchase->sessions);
            }
            abort_unless(array_sum(array_column($splits, 'amount')) <= min($ceilings), 422, 'Total pembagian melebihi dana sesi paket yang masih berlaku.');
            $values = ['community_id' => $community->id, 'name' => $data['name'], 'address' => $data['address'] ?? '', 'active' => $data['active'], 'manager_member_ids' => $managers, 'splits' => array_values($splits), 'version' => ($location->version ?? 0) + 1, 'updated_by' => $actor->id];
            if ($location === null) {
                $location = TrainingLocation::query()->create($values);
            } else {
                $location->update($values);
            }
            $this->audit($actor, $community, 'training_location_updated', ['location_id' => $location->id, 'version' => $location->version, 'settings' => $values]);

            return $this->locationData($location, $this->member($actor, $community));
        }, 3);
    }

    public function mayConfirm(CommunityMember $actor, TrainingLocation $location): bool
    {
        return $actor->isActive() && ($actor->role === CommunityMemberRole::Creator || $actor->role === CommunityMemberRole::Admin || (in_array($actor->role, [CommunityMemberRole::Officer, CommunityMemberRole::Coach], true) && in_array($actor->id, $location->manager_member_ids, true)));
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function createSession(User $actor, Community $community, array $data): array
    {
        return DB::transaction(function () use ($actor, $community, $data): array {
            $community = $this->lockCommunity($community);
            $member = $this->member($actor, $community);
            $this->requireActive($member, $community);
            $this->ledger->lockOperation('training-request:'.$actor->id.':'.($data['idempotency_key'] ?? ''));
            $hash = $this->ledger->payloadHash(['community_id' => $community->id, ...$data]);
            $existing = TrainingSession::query()->where('created_by', $actor->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                abort_unless($existing->payload_hash === $hash, 409, 'Kunci sesi dipakai untuk data berbeda.');

                return $this->sessionData($existing, $member);
            }
            $location = TrainingLocation::query()->where('community_id', $community->id)->where('active', true)->findOrFail($data['location_id']);
            abort_unless($this->mayConfirm($member, $location), 403, 'Anda bukan petugas lokasi ini.');
            $start = CarbonImmutable::parse($data['starts_at']);
            $end = CarbonImmutable::parse($data['ends_at']);
            abort_unless($end->greaterThan($start) && $end->diffInSeconds($start, true) <= 86400, 422, 'Rentang sesi harus positif dan maksimal 24 jam.');
            $session = TrainingSession::query()->create(['community_id' => $community->id, 'location_id' => $location->id, 'title' => $data['title'], 'starts_at' => $start, 'ends_at' => $end, 'created_by' => $actor->id, 'idempotency_key' => $data['idempotency_key'], 'payload_hash' => $hash]);
            $this->audit($actor, $community, 'training_session_created', ['session_id' => $session->id, 'location_id' => $location->id]);

            return $this->sessionData($session, $member);
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function purchase(User $actor, Community $community, array $data): TrainingPurchase
    {
        return DB::transaction(function () use ($actor, $community, $data): TrainingPurchase {
            $community = $this->lockCommunity($community);
            $member = $this->member($actor, $community);
            $this->ledger->lockOperation('training-request:'.$actor->id.':'.($data['idempotency_key'] ?? ''));
            $hash = $this->ledger->payloadHash(['community_id' => $community->id, ...$data]);
            $existing = TrainingPurchase::query()->where('buyer_id', $actor->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                abort_unless($existing->payload_hash === $hash, 409, 'Kunci pembelian sudah digunakan untuk data berbeda.');

                return $existing;
            }
            $this->requireActive($member, $community);
            $catalog = $this->catalog($community);
            $package = collect($catalog->packages)->firstWhere('sessions', $data['sessions']);
            abort_unless($catalog->enabled && $package !== null, 422, 'Paket belum tersedia.');
            abort_unless($catalog->version === (int) $data['catalog_version'] && $package['price'] === (int) $data['expected_price'], 409, 'Harga paket berubah. Muat ulang dan konfirmasi harga baru.');
            $id = (string) Str::uuid();
            $identity = MemberIdentity::query()->findOrFail($member->member_identity_id);
            $reservation = $this->ledger->reserve($actor, $identity, $package['price'], 'reserved', 'training_purchase:'.$id, 'training_purchase:'.hash('sha256', $data['idempotency_key']), $community);
            $journal = LedgerJournal::query()->where('reference', 'training_purchase:'.$id)->where('operation', 'reserve_reserved')->firstOrFail();
            $purchase = TrainingPurchase::query()->create(['id' => $id, 'community_id' => $community->id, 'member_id' => $member->id, 'member_identity_id' => $identity->id, 'buyer_id' => $actor->id, 'sessions' => $package['sessions'], 'remaining_sessions' => $package['sessions'], 'price' => $package['price'], 'remaining_amount' => $package['price'], 'catalog_version' => $catalog->version, 'snapshot' => ['community_name' => $community->name, 'member_name' => $identity->name, 'sessions' => $package['sessions'], 'price' => $package['price'], 'catalog_version' => $catalog->version, 'expiry' => null, 'selection' => 'earliest_purchased', 'rounding' => 'floor_then_last_remainder'], 'reservation_id' => $reservation->id, 'journal_id' => $journal->id, 'idempotency_key' => $data['idempotency_key'], 'payload_hash' => $hash]);
            $this->event($community, $identity->id, 'training_purchase_final', $id, ['purchase_id' => $id, 'price' => $package['price'], 'sessions' => $package['sessions'], 'journal_id' => $journal->id]);

            return $purchase;
        }, 3);
    }

    private function validSession(Community $community, TrainingSession $session): TrainingLocation
    {
        abort_unless($session->community_id === $community->id, 404);
        $location = TrainingLocation::query()->where('community_id', $community->id)->findOrFail($session->location_id);
        abort_unless($location->active, 422, 'Lokasi latihan tidak aktif.');
        abort_unless(now()->greaterThanOrEqualTo($session->starts_at) && now()->lessThanOrEqualTo($session->ends_at), 422, 'Absensi hanya dapat dikonfirmasi pada waktu sesi latihan.');

        return $location;
    }

    /** @param array<string,mixed> $data */
    public function checkIn(User $actor, Community $community, TrainingSession $session, array $data): TrainingAttendance
    {
        return DB::transaction(function () use ($actor, $community, $session, $data): TrainingAttendance {
            $community = $this->lockCommunity($community);
            $member = $this->member($actor, $community);
            $this->ledger->lockOperation('training-checkin:'.$actor->id.':'.$data['idempotency_key']);
            $keyUse = TrainingAttendance::query()->where('checked_in_by', $actor->id)->where('checkin_key', $data['idempotency_key'])->first();
            abort_if($keyUse !== null && ($keyUse->community_id !== $community->id || $keyUse->session_id !== $session->id), 409, 'Kunci check-in dipakai sesi lain.');
            $existing = TrainingAttendance::query()->where('session_id', $session->id)->where('member_identity_id', $member->member_identity_id)->first();
            if ($existing !== null) {
                abort_unless($existing->community_id === $community->id && $existing->checked_in_by === $actor->id && $existing->checkin_key === $data['idempotency_key'], 409, 'Absensi sesi ini sudah tercatat.');

                return $existing;
            }
            $this->requireActive($member, $community);
            $this->validSession($community, $session);
            abort_unless(TrainingPurchase::query()->where('community_id', $community->id)->where('member_identity_id', $member->member_identity_id)->where('remaining_sessions', '>', 0)->exists(), 422, 'Paket latihan habis atau belum dibeli.');

            return TrainingAttendance::query()->create(['community_id' => $community->id, 'session_id' => $session->id, 'member_id' => $member->id, 'member_identity_id' => $member->member_identity_id, 'checked_in_by' => $actor->id, 'checkin_key' => $data['idempotency_key'], 'status' => 'pending']);
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function confirm(User $actor, Community $community, TrainingSession $session, array $data): TrainingAttendance
    {
        return DB::transaction(function () use ($actor, $community, $session, $data): TrainingAttendance {
            $community = $this->lockCommunity($community);
            abort_unless($session->community_id === $community->id, 404);
            $staff = $this->member($actor, $community);
            $location = TrainingLocation::query()->where('community_id', $community->id)->findOrFail($session->location_id);
            abort_unless($this->mayConfirm($staff, $location), 403, 'Anda bukan petugas lokasi ini.');
            $member = CommunityMember::query()->where('community_id', $community->id)->whereNull('merged_into_member_id')->findOrFail($data['member_id']);
            $attendance = TrainingAttendance::query()->where('session_id', $session->id)->where('member_identity_id', $member->member_identity_id)->lockForUpdate()->first();
            if ($attendance !== null && $attendance->status !== 'pending') {
                abort_unless($attendance->status === 'confirmed' && $attendance->confirmed_by === $actor->id && $attendance->confirmation_key === $data['idempotency_key'], 409, 'Absensi sesi ini sudah final.');

                return $attendance;
            }
            abort_if(TrainingAttendance::query()->where('confirmed_by', $actor->id)->where('confirmation_key', $data['idempotency_key'])->exists(), 409, 'Kunci konfirmasi dipakai absensi lain.');
            $this->requireActive($staff, $community);
            $this->requireActive($member, $community);
            $location = $this->validSession($community, $session);
            $purchase = TrainingPurchase::query()->where('community_id', $community->id)->where('member_identity_id', $member->member_identity_id)->where('remaining_sessions', '>', 0)->orderBy('purchase_order')->lockForUpdate()->first();
            abort_unless($purchase !== null, 422, 'Paket latihan habis atau belum dibeli.');
            $floor = intdiv($purchase->price, $purchase->sessions);
            $allocation = $purchase->remaining_sessions === 1 ? $purchase->remaining_amount : $floor;
            abort_unless(array_sum(array_column($location->splits, 'amount')) <= $floor, 422, 'Pembagian lokasi melebihi dana sesi paket terdahulu. Pendiri perlu menyesuaikan pengaturan.');
            $allocations = [];
            $splits = [];
            foreach ($location->splits as $split) {
                $recipient = CommunityMember::query()->where('community_id', $community->id)->where('status', CommunityMemberStatus::Active)->whereNull('merged_into_member_id')->findOrFail($split['member_id']);
                abort_unless($recipient->member_identity_id === $split['identity_id'], 409, 'Identitas penerima berubah. Perbarui pembagian lokasi.');
                $account = $this->ledger->walletAccount(MemberIdentity::query()->findOrFail($split['identity_id']));
                $splits[] = [...$split, 'account_id' => $account->id];
                if ($split['amount'] > 0) {
                    $allocations[] = ['account_id' => $account->id, 'amount' => (int) $split['amount']];
                }
            }
            $residual = $allocation - array_sum(array_column($splits, 'amount'));
            if ($residual > 0) {
                $account = $this->ledger->communityAccount($community);
                $allocations[] = ['account_id' => $account->id, 'amount' => $residual];
                $splits[] = ['member_id' => null, 'identity_id' => null, 'name' => $community->name, 'role' => 'community', 'account_id' => $account->id, 'amount' => $residual];
            }
            $attendance ??= TrainingAttendance::query()->create(['community_id' => $community->id, 'session_id' => $session->id, 'member_id' => $member->id, 'member_identity_id' => $member->member_identity_id, 'status' => 'pending']);
            $snapshot = ['location_id' => $location->id, 'location_name' => $location->name, 'location_version' => $location->version, 'session_id' => $session->id, 'session_title' => $session->title, 'starts_at' => $session->starts_at->toIso8601String(), 'ends_at' => $session->ends_at->toIso8601String(), 'member_name' => $member->identity->name ?? '', 'member_role' => $member->role->value, 'purchase' => $purchase->snapshot, 'allocation' => $allocation, 'splits' => $splits, 'residual' => $residual, 'platform_amount' => 0, 'confirmed_by_name' => $actor->name];
            $journal = $this->ledger->allocateReservation($actor, LedgerReservation::query()->findOrFail($purchase->reservation_id), $allocations, $data['idempotency_key'], ['reference' => $attendance->id, 'attendance_id' => $attendance->id, 'purchase_id' => $purchase->id, 'snapshot' => $snapshot]);
            $purchase->update(['remaining_sessions' => $purchase->remaining_sessions - 1, 'remaining_amount' => $purchase->remaining_amount - $allocation]);
            $attendance->update(['status' => 'confirmed', 'purchase_id' => $purchase->id, 'allocation' => $allocation, 'snapshot' => $snapshot, 'journal_id' => $journal->id, 'confirmed_by' => $actor->id, 'confirmed_at' => now(), 'confirmation_key' => $data['idempotency_key']]);
            $this->event($community, $member->member_identity_id, 'training_attendance_final', $attendance->id, ['attendance_id' => $attendance->id, 'purchase_id' => $purchase->id, 'journal_id' => $journal->id, 'allocation' => $allocation, 'snapshot' => $snapshot]);

            return $attendance;
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function correct(User $actor, Community $community, TrainingAttendance $attendance, array $data): TrainingAttendance
    {
        return DB::transaction(function () use ($actor, $community, $attendance, $data): TrainingAttendance {
            $community = $this->lockCommunity($community);
            $this->founder($actor, $community);
            $attendance = TrainingAttendance::query()->where('community_id', $community->id)->lockForUpdate()->findOrFail($attendance->id);
            if ($attendance->status === 'corrected') {
                abort_unless($attendance->corrected_by === $actor->id && $attendance->correction_key === $data['idempotency_key'] && $attendance->correction_reason === $data['reason'], 409, 'Absensi telah dikoreksi.');

                return $attendance;
            }
            abort_unless($attendance->status === 'confirmed', 422, 'Hanya absensi final dapat dikoreksi secara teknis.');
            abort_unless(mb_strlen(trim($data['reason'])) >= 10, 422, 'Jelaskan alasan insiden teknis minimal 10 karakter.');
            abort_if(TrainingAttendance::query()->where('corrected_by', $actor->id)->where('correction_key', $data['idempotency_key'])->exists(), 409, 'Kunci koreksi dipakai absensi lain.');
            $purchase = TrainingPurchase::query()->lockForUpdate()->findOrFail($attendance->purchase_id);
            abort_if(MemberIdentity::query()->findOrFail($purchase->member_identity_id)->merged_into_id !== null, 409, 'Identitas atlet telah digabung. Koreksi memerlukan pemulihan teknis yang menjaga kepemilikan paket.');
            $journal = $this->ledger->restoreTrainingAllocation($actor, LedgerReservation::query()->findOrFail($purchase->reservation_id), LedgerJournal::query()->findOrFail($attendance->journal_id), $data['idempotency_key'], ['reference' => $attendance->id, 'attendance_id' => $attendance->id, 'reason' => $data['reason']]);
            $purchase->update(['remaining_sessions' => $purchase->remaining_sessions + 1, 'remaining_amount' => $purchase->remaining_amount + $attendance->allocation]);
            $attendance->update(['status' => 'corrected', 'corrected_by' => $actor->id, 'corrected_by_name' => $actor->name, 'corrected_at' => now(), 'correction_reason' => $data['reason'], 'correction_key' => $data['idempotency_key'], 'correction_journal_id' => $journal->id]);
            $this->event($community, $attendance->member_identity_id, 'training_attendance_corrected', $attendance->id, ['attendance_id' => $attendance->id, 'original_journal_id' => $attendance->journal_id, 'correction_journal_id' => $journal->id, 'reason' => $data['reason'], 'actor_id' => $actor->id]);
            $this->audit($actor, $community, 'training_attendance_corrected', ['attendance_id' => $attendance->id, 'reason' => $data['reason'], 'journal_id' => $journal->id]);

            return $attendance;
        }, 3);
    }

    /** @param array<string,mixed> $payload */
    private function event(Community $community, int $identity, string $type, string $source, array $payload): void
    {
        TrainingFinalEvent::query()->firstOrCreate(['type' => $type, 'source_id' => $source], ['community_id' => $community->id, 'member_identity_id' => $identity, 'payload' => $payload, 'created_at' => now()]);
    }

    /** @param array<string,mixed> $properties */
    private function audit(User $actor, Community $community, string $type, array $properties): void
    {
        activity('training')->performedOn($community)->causedBy($actor)->event($type)->withProperties($properties)->log($type);
    }

    /** @return array<string,mixed> */
    public function purchaseData(TrainingPurchase $purchase): array
    {
        return ['id' => $purchase->id, 'sessions' => $purchase->sessions, 'remaining_sessions' => $purchase->remaining_sessions, 'price' => $purchase->price, 'remaining_amount' => $purchase->remaining_amount, 'session_floor' => intdiv($purchase->price, $purchase->sessions), 'created_at' => $purchase->created_at->toIso8601String(), 'reservation_id' => $purchase->reservation_id, 'journal_id' => $purchase->journal_id, 'snapshot' => $purchase->snapshot, 'event_id' => TrainingFinalEvent::query()->where('source_id', $purchase->id)->where('type', 'training_purchase_final')->value('id')];
    }

    /** @return array<string,mixed> */
    public function locationData(TrainingLocation $location, CommunityMember $viewer): array
    {
        return ['id' => $location->id, 'name' => $location->name, 'address' => $location->address, 'active' => $location->active, 'version' => $location->version, 'manager_member_ids' => $location->manager_member_ids, 'splits' => $location->splits, 'can_confirm' => $this->mayConfirm($viewer, $location)];
    }

    /** @return array<string,mixed> */
    public function sessionData(TrainingSession $session, CommunityMember $viewer): array
    {
        $location = TrainingLocation::query()->findOrFail($session->location_id);

        return ['id' => $session->id, 'title' => $session->title, 'location_id' => $location->id, 'location_name' => $location->name, 'starts_at' => $session->starts_at->toIso8601String(), 'ends_at' => $session->ends_at->toIso8601String(), 'can_confirm' => $this->mayConfirm($viewer, $location)];
    }

    /** @return array<string,mixed> */
    public function attendanceData(TrainingAttendance $attendance): array
    {
        $session = TrainingSession::query()->findOrFail($attendance->session_id);
        $snapshot = $attendance->snapshot;

        return ['id' => $attendance->id, 'session_id' => $session->id, 'session_title' => $snapshot['session_title'] ?? $session->title, 'location_id' => $snapshot['location_id'] ?? $session->location_id, 'location_name' => $snapshot['location_name'] ?? TrainingLocation::query()->findOrFail($session->location_id)->name, 'member_id' => $attendance->member_id, 'member_name' => $snapshot['member_name'] ?? CommunityMember::query()->findOrFail($attendance->member_id)->identity->name ?? '', 'status' => $attendance->status, 'purchase_id' => $attendance->purchase_id, 'allocation' => $attendance->allocation, 'created_at' => $attendance->created_at->toIso8601String(), 'confirmed_at' => $attendance->confirmed_at?->toIso8601String(), 'confirmed_by' => $snapshot['confirmed_by_name'] ?? '', 'confirmed_by_id' => $attendance->confirmed_by, 'journal_id' => $attendance->journal_id, 'snapshot' => $snapshot, 'event_id' => TrainingFinalEvent::query()->where('source_id', $attendance->id)->where('type', 'training_attendance_final')->value('id'), 'correction_reason' => $attendance->correction_reason, 'corrected_at' => $attendance->corrected_at?->toIso8601String(), 'corrected_by' => $attendance->corrected_by_name ?? '', 'corrected_by_id' => $attendance->corrected_by, 'correction_journal_id' => $attendance->correction_journal_id];
    }

    /** @return array<string,mixed> */
    public function overview(User $actor, Community $community): array
    {
        return DB::transaction(function () use ($actor, $community): array {
            $community = $this->lockCommunity($community);
            $member = $this->member($actor, $community);
            $locations = TrainingLocation::query()->where('community_id', $community->id)->orderBy('id')->get();
            $staffLocations = $locations->filter(fn (TrainingLocation $location): bool => $this->mayConfirm($member, $location))->pluck('id')->all();
            $canConfigure = $member->isActive() && $member->role === CommunityMemberRole::Creator;
            $attendance = TrainingAttendance::query()->where('community_id', $community->id)->where(function ($query) use ($member, $staffLocations): void {
                $query->where('member_identity_id', $member->member_identity_id)->orWhereIn('session_id', TrainingSession::query()->select('id')->whereIn('location_id', $staffLocations));
            })->orderByDesc('created_at')->get();

            return ['community_id' => $community->id, 'self_member_id' => $member->id, 'community_name' => $community->name, 'can_configure' => $canConfigure, 'can_confirm' => count($staffLocations) > 0 || in_array($member->role, [CommunityMemberRole::Creator, CommunityMemberRole::Admin], true), 'can_report' => true, 'active' => $this->active($member, $community), 'catalog' => $this->catalogData($this->catalog($community)), 'locations' => $locations->filter(fn (TrainingLocation $l): bool => $member->isActive() && ($l->active || $this->mayConfirm($member, $l)))->map(fn (TrainingLocation $l): array => $this->locationData($l, $member))->values()->all(), 'members' => $canConfigure || count($staffLocations) > 0 ? CommunityMember::query()->with('identity')->where('community_id', $community->id)->where('status', CommunityMemberStatus::Active)->whereNull('merged_into_member_id')->get()->map(fn (CommunityMember $m): array => ['id' => $m->id, 'name' => $m->identity->name ?? '', 'role' => $m->role->value])->all() : [], 'sessions' => TrainingSession::query()->where('community_id', $community->id)->when(! $member->isActive(), fn ($q) => $q->whereRaw('1 = 0'))->orderByDesc('starts_at')->get()->map(fn (TrainingSession $s): array => $this->sessionData($s, $member))->all(), 'purchases' => TrainingPurchase::query()->where('community_id', $community->id)->where('member_identity_id', $member->member_identity_id)->orderBy('purchase_order')->get()->map(fn (TrainingPurchase $p): array => $this->purchaseData($p))->all(), 'attendances' => $attendance->map(fn (TrainingAttendance $a): array => $this->attendanceData($a))->all()];
        }, 3);
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function report(User $actor, Community $community, array $filters = []): array
    {
        return DB::transaction(function () use ($actor, $community, $filters): array {
            if (DB::transactionLevel() === 1 && DB::getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            $community = $this->lockCommunity($community);
            $member = $this->member($actor, $community);
            $global = $member->isActive() && in_array($member->role, [CommunityMemberRole::Creator, CommunityMemberRole::Admin], true);
            $managed = TrainingLocation::query()->where('community_id', $community->id)->get()->filter(fn (TrainingLocation $l): bool => $this->mayConfirm($member, $l))->pluck('id')->all();
            $scope = $global ? 'community' : (count($managed) > 0 ? 'managed_locations' : 'recipient');
            $allPurchases = TrainingPurchase::query()->where('community_id', $community->id)->get();
            $allAttendance = TrainingAttendance::query()->where('community_id', $community->id)->whereIn('status', ['confirmed', 'corrected'])->get();
            $attendance = $allAttendance->filter(function (TrainingAttendance $a) use ($global, $managed, $member, $filters): bool {
                $snapshot = $a->snapshot ?? [];
                $location = $snapshot['location_id'] ?? null;
                if (! $global && (count($managed) > 0 ? ! in_array($location, $managed, true) : ! collect($snapshot['splits'] ?? [])->contains('member_id', $member->id))) {
                    return false;
                }
                if (isset($filters['location_id']) && $location !== (int) $filters['location_id']) {
                    return false;
                }
                $date = $a->confirmed_at?->timezone('Asia/Jakarta')->toDateString() ?? '';
                if (isset($filters['from']) && $date < $filters['from'] || isset($filters['to']) && $date > $filters['to']) {
                    return false;
                }

                return ! isset($filters['recipient_member_id']) || collect($snapshot['splits'] ?? [])->contains('member_id', (int) $filters['recipient_member_id']);
            });
            $locations = [];
            $recipients = [];
            foreach ($attendance as $a) {
                $snapshot = $a->snapshot ?? [];
                $location = $snapshot['location_id'];
                if ($scope !== 'recipient') {
                    $locations[$location] ??= ['location_id' => $location, 'location_name' => $snapshot['location_name'], 'allocated_amount' => 0, 'attendance_count' => 0];
                    if ($a->status === 'confirmed') {
                        $locations[$location]['allocated_amount'] += $a->allocation;
                        $locations[$location]['attendance_count']++;
                    }
                }
                foreach ($snapshot['splits'] ?? [] as $split) {
                    if ($scope === 'recipient' && $split['member_id'] !== $member->id || isset($filters['recipient_member_id']) && $split['member_id'] !== (int) $filters['recipient_member_id']) {
                        continue;
                    }
                    $key = $split['member_id'] ?? 'community';
                    $recipients[$key] ??= ['member_id' => $split['member_id'], 'name' => $split['name'], 'amount' => 0];
                    if ($a->status === 'confirmed') {
                        $recipients[$key]['amount'] += $split['amount'];
                    }
                }
            }
            $paid = (int) $allPurchases->sum('price');
            $reserved = (int) $allPurchases->sum('remaining_amount');
            $allocated = (int) $allAttendance->where('status', 'confirmed')->sum('allocation');
            $purchasedSessions = (int) $allPurchases->sum('sessions');
            $remaining = (int) $allPurchases->sum('remaining_sessions');
            $used = $allAttendance->where('status', 'confirmed')->count();
            $reservationErrors = [];
            $accountErrors = [];
            if ($global) {
                foreach ($allPurchases as $purchase) {
                    $reservation = LedgerReservation::query()->findOrFail($purchase->reservation_id);
                    if ($reservation->remaining !== $purchase->remaining_amount || $reservation->amount !== $purchase->price || $reservation->community_id !== $community->id) {
                        $reservationErrors[] = $purchase->id;
                    }
                }
                $accountIds = LedgerReservation::query()->whereIn('id', $allPurchases->pluck('reservation_id'))->pluck('reserved_account_id')->unique();
                foreach ($accountIds as $accountId) {
                    $actual = (int) LedgerAccount::query()->findOrFail($accountId)->balance;
                    $expected = (int) LedgerReservation::query()->where('reserved_account_id', $accountId)->sum('remaining');
                    if ($actual !== $expected) {
                        $accountErrors[] = $accountId;
                    }
                }
            }
            $ledgerBalanced = ! $global || $this->ledger->reconcile()['balanced'];
            $filteredAllocated = $scope === 'recipient' || isset($filters['recipient_member_id']) ? array_sum(array_column($recipients, 'amount')) : (int) $attendance->where('status', 'confirmed')->sum('allocation');
            $totals = $global ? ['purchased_amount' => $paid, 'reserved_amount' => $reserved, 'allocated_amount' => $allocated, 'purchased_sessions' => $purchasedSessions, 'used_sessions' => $used, 'remaining_sessions' => $remaining, 'balanced' => $paid === $reserved + $allocated && $purchasedSessions === $used + $remaining && $reservationErrors === [] && $accountErrors === [] && $ledgerBalanced] : ['allocated_amount' => array_sum(array_column($recipients, 'amount'))];

            return ['scope' => $scope, 'totals' => [...$totals, 'filtered_allocated_amount' => $filteredAllocated, 'filtered_attendance_count' => $attendance->where('status', 'confirmed')->count()], 'reconciliation' => $global ? ['reservation_errors' => $reservationErrors, 'reserved_account_errors' => $accountErrors, 'ledger_balanced' => $ledgerBalanced] : null, 'locations' => array_values($locations), 'recipients' => array_values($recipients), 'purchases' => $global ? $allPurchases->map(fn (TrainingPurchase $p): array => $this->purchaseData($p))->all() : [], 'attendances' => $scope === 'recipient' ? [] : $attendance->map(fn (TrainingAttendance $a): array => $this->attendanceData($a))->values()->all()];
        }, 3);
    }
}
