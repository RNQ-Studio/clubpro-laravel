<?php

namespace App\Services;

use App\Models\BillingInvoice;
use App\Models\BillingSubject;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\DuesMonth;
use App\Models\DuesPayment;
use App\Models\IncomingReceipt;
use App\Models\MemberIdentity;
use App\Models\User;
use App\Support\Enums\CommunityMemberRole;
use App\Support\Enums\CommunityMemberStatus;
use App\Support\Enums\DuesMonthStatus;
use App\Support\Enums\DuesPaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** One resolver for invoices, access and cards. Billing never disables authentication. */
class BillingService
{
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now('Asia/Jakarta')->startOfDay();
    }

    public function memberSubject(CommunityMember $member, bool $newJoin = false): BillingSubject
    {
        $identity = $member->member_identity_id;
        abort_if($identity === null, 409, 'Identitas anggota belum tersedia.');
        $key = 'member:'.$member->community_id.':'.$identity;
        $existing = BillingSubject::query()->where('subject_key', $key)->first();
        if ($existing !== null) {
            if ($member->merged_into_member_id === null && $member->billing_scheme !== $existing->scheme) {
                $member->forceFill(['billing_scheme' => $existing->scheme])->save();
            }
            if ($existing->member_id !== $member->id && $member->merged_into_member_id === null) {
                $existing->update(['member_id' => $member->id]);
            }

            return $existing;
        }
        $community = $member->community;
        $legacy = ! $newJoin && $member->billing_scheme === 'legacy_calendar';
        $start = $newJoin ? $this->today() : $this->date($member->joined_at ?? $member->created_at ?? $this->today());
        $priorPaid = $member->entry_fee_paid_at !== null || $member->joined_at !== null || ($member->user_id !== null && DuesPayment::query()->where('community_id', $community->id)->where('user_id', $member->user_id)->where('status', 'approved')->exists()) || ($member->user_id !== null && DuesMonth::query()->where('community_id', $community->id)->where('user_id', $member->user_id)->where('status', DuesMonthStatus::Paid)->exists());
        $autoActivate = $legacy && $member->status === CommunityMemberStatus::Pending && ! $priorPaid;
        if ($autoActivate) {
            $legacy = false;
            $start = $community->billing_cutover_date === null ? $this->today() : $this->date($community->billing_cutover_date);
        }
        $trialEnd = $legacy ? null : $start->addDays(7);
        $anchor = $trialEnd ?? $this->legacyAnchor($community, $member);
        $subject = DB::transaction(function () use ($key, $community, $identity, $member, $legacy, $start, $trialEnd, $anchor, $autoActivate): BillingSubject {
            $subject = BillingSubject::query()->firstOrCreate(['subject_key' => $key], [
                'community_id' => $community->id, 'member_identity_id' => $identity, 'member_id' => $member->id,
                'kind' => 'member', 'scheme' => $legacy ? 'legacy_calendar' : 'anniversary',
                'trial_start' => $legacy ? null : $start->toDateString(), 'trial_end' => $trialEnd?->toDateString(),
                'anchor_date' => $anchor->toDateString(), 'due_day' => $legacy ? ($community->dues_start_date === null ? 1 : $community->dues_start_date->day) : null,
            ]);
            if ($member->merged_into_member_id === null && $member->billing_scheme !== $subject->scheme) {
                $member->forceFill(['billing_scheme' => $subject->scheme])->save();
            }
            if ($autoActivate && $member->status === CommunityMemberStatus::Pending && $member->joined_at === null && $trialEnd->greaterThan($this->today())) {
                $member->update(['status' => CommunityMemberStatus::Active, 'joined_at' => now()]);
            }

            return $subject;
        });

        return $subject;
    }

    public function platformSubject(Community $community, bool $newCommunity = false): BillingSubject
    {
        $start = $this->date($community->created_at ?? $this->today());
        $legacy = ! $newCommunity && $community->billing_cutover_date !== null;
        $anchor = $legacy ? $this->date($community->billing_cutover_date) : $start->addDays(7);

        return BillingSubject::query()->firstOrCreate(['subject_key' => 'platform:'.$community->id], [
            'community_id' => $community->id, 'kind' => 'platform', 'scheme' => 'anniversary',
            'trial_start' => $legacy ? null : $start->toDateString(), 'trial_end' => $legacy ? null : $anchor->toDateString(),
            'anchor_date' => $anchor->toDateString(),
        ]);
    }

    public function initializeJoin(CommunityMember $member): void
    {
        $subject = $this->memberSubject($member, true);
        // A returning member keeps the original trial grant and outstanding invoices.
        if ($subject->archived_at === null && $member->status === CommunityMemberStatus::Pending) {
            $member->update(['status' => CommunityMemberStatus::Active, 'joined_at' => $member->joined_at ?? now()]);
        }
        if ($subject->archived_at === null && $subject->paused_at !== null) {
            $oldAnchor = $subject->anchor_date->toDateString();
            $lastEnd = BillingInvoice::query()->whereIn('subject_id', $this->subjectIds($subject))->where('kind', '!=', 'entry')->where('status', '!=', 'void')->max('period_end');
            $anchor = $this->today();
            foreach ([$lastEnd, $subject->trial_end] as $candidate) {
                if ($candidate !== null && $this->date($candidate)->greaterThan($anchor)) {
                    $anchor = $this->date($candidate);
                }
            }
            $subject->update(['anchor_date' => $anchor->toDateString(), 'next_offset' => 0, 'scheme' => 'anniversary', 'paused_at' => null]);
            $member->forceFill(['billing_scheme' => 'anniversary'])->save();
            activity('billing')->performedOn($member->community)->event('billing_membership_resumed')->withProperties(['member_id' => $member->id, 'old_anchor' => $oldAnchor, 'new_anchor' => $anchor->toDateString(), 'trial_preserved' => true, 'archived_gap_not_billed' => true])->log('billing_membership_resumed');
        }
        $this->refresh($subject);
    }

    public function refresh(BillingSubject $subject, bool $renew = false, ?CarbonImmutable $through = null): BillingSubject
    {
        return DB::transaction(function () use ($subject, $renew, $through): BillingSubject {
            $subject = BillingSubject::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            if ($subject->merged_into_subject_id !== null) {
                return $this->refresh(BillingSubject::query()->findOrFail($subject->merged_into_subject_id), $renew, $through);
            }
            $community = Community::query()->findOrFail($subject->community_id);
            $member = $subject->member_id === null ? null : CommunityMember::query()->findOrFail($subject->member_id);
            if ($subject->archived_at !== null || $member?->status === CommunityMemberStatus::Archived) {
                return $subject;
            }
            $this->restoreLegacyRows($subject, $community, $member);
            $legacyEntryPayment = $subject->scheme === 'legacy_calendar' && $member?->user_id !== null ? DuesPayment::query()->where('community_id', $community->id)->where('user_id', $member->user_id)->whereIn('status', ['pending', 'approved'])->where('entry_fee', '>', 0)->oldest('id')->first() : null;
            $today = $through ?? $this->today();
            if ($renew) {
                $today = $this->date($subject->anchor_date)->addMonthsNoOverflow($subject->next_offset);
            }
            $anchor = $this->date($subject->anchor_date);
            for ($iteration = 0; $iteration < 2400; $iteration++) {
                $start = $anchor->addMonthsNoOverflow($subject->next_offset);
                if ($start->greaterThan($today)) {
                    break;
                }
                $period = $subject->kind === 'platform' || $subject->scheme === 'legacy_calendar' ? 1 : max(1, (int) $community->dues_period_months);
                $end = $anchor->addMonthsNoOverflow($subject->next_offset + $period);
                if ($subject->scheme === 'legacy_calendar') {
                    // Legacy inclusive due-day rights become exclusive the following midnight.
                    $month = $anchor->startOfMonth()->addMonths($subject->next_offset);
                    $day = $subject->due_day ?? ($community->dues_start_date === null ? 1 : $community->dues_start_date->day);
                    $start = $month->setDay(min($day, $month->daysInMonth))->addDay();
                    $nextMonth = $month->addMonth();
                    $end = $nextMonth->setDay(min($day, $nextMonth->daysInMonth))->addDay();
                    if ($start->greaterThan($today)) {
                        break;
                    }
                }
                $exempt = $member !== null && $this->exemptRole($member->role)
                    && ($subject->scheme !== 'legacy_calendar' || $start->greaterThanOrEqualTo($this->date($member->updated_at ?? $today)));
                $amount = $subject->kind === 'platform' ? (int) config('finance.platform_monthly_amount', 100000) : ($exempt ? 0 : (int) ($community->dues_amount ?? 0));
                $legacyMonth = $subject->scheme === 'legacy_calendar' ? $anchor->startOfMonth()->addMonths($subject->next_offset)->format('Y-m') : null;
                $legacyRow = $legacyMonth === null || $member?->user_id === null ? null : DuesMonth::query()->where('community_id', $community->id)->where('user_id', $member->user_id)->whereDate('month', $legacyMonth.'-01')->first();
                $legacyNominalUnknown = $legacyRow?->status === DuesMonthStatus::Paid && $legacyRow->payment === null;
                if ($legacyRow !== null && $legacyRow->payment !== null) {
                    $amount = $legacyRow->payment->monthly_amount;
                } elseif ($legacyNominalUnknown) {
                    $amount = 0;
                }
                $paid = $legacyRow?->status === DuesMonthStatus::Paid || $amount === 0;
                $this->invoice($subject, $subject->kind, $start, $end, $amount, $paid, $legacyMonth, [
                    'period_months' => $period, 'role' => $member?->role->value, 'exempt' => $exempt,
                    'legacy_dues_payment_id' => $legacyRow?->dues_payment_id,
                    'legacy_paid' => $legacyRow?->status === DuesMonthStatus::Paid, 'legacy_nominal_unrecorded' => $legacyNominalUnknown,
                ]);
                if ($subject->kind === 'member' && ! $exempt && $member?->entry_fee_paid_at === null && ($legacyEntryPayment === null ? $community->entry_fee : $legacyEntryPayment->entry_fee) > 0) {
                    BillingInvoice::query()->firstOrCreate(['source_key' => $subject->subject_key.':entry'], [
                        'id' => (string) Str::uuid(), 'subject_id' => $subject->id, 'community_id' => $subject->community_id,
                        'member_identity_id' => $subject->member_identity_id, 'kind' => 'entry',
                        'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(),
                        'amount' => $legacyEntryPayment === null ? $community->entry_fee : $legacyEntryPayment->entry_fee, 'paid_amount' => $legacyEntryPayment?->status === DuesPaymentStatus::Approved ? $legacyEntryPayment->entry_fee : 0, 'status' => $legacyEntryPayment?->status === DuesPaymentStatus::Approved ? 'paid' : 'open',
                        'snapshot' => ['entry_fee' => $legacyEntryPayment === null ? $community->entry_fee : $legacyEntryPayment->entry_fee, 'legacy_dues_payment_id' => $legacyEntryPayment?->id, 'role' => $member?->role->value],
                    ]);
                }
                $subject->next_offset += $period;
                $subject->save();
            }
            $this->importLegacyPaid($subject, $community, $member);
            $state = $this->resolve($subject, $community, $member);
            if (! $state['active'] && $state['state'] !== 'archived' && $subject->inactive_at === null) {
                $expiredAt = BillingInvoice::query()->whereIn('subject_id', $this->subjectIds($subject))->whereIn('status', ['open', 'partially_paid'])->min('period_start');
                $subject->inactive_at = $expiredAt ?? $subject->trial_end ?? $today->toDateString();
                $subject->save();
            } elseif ($state['active'] && $subject->inactive_at !== null) {
                $subject->update(['inactive_at' => null, 'retained_until' => null]);
            }

            return $subject;
        }, 3);
    }

    /** @return array<string, mixed> */
    public function entitlement(BillingSubject $subject): array
    {
        $subject = $this->refresh($subject);

        return $this->resolve($subject, Community::query()->findOrFail($subject->community_id), $subject->member_id === null ? null : CommunityMember::query()->findOrFail($subject->member_id));
    }

    /** @return array<string, mixed> */
    private function resolve(BillingSubject $subject, Community $community, ?CommunityMember $member): array
    {
        $today = $this->today();
        $invoices = BillingInvoice::query()->whereIn('subject_id', $this->subjectIds($subject))->where('status', '!=', 'void')->get();
        $arrears = (int) $invoices->filter(fn (BillingInvoice $invoice): bool => $this->date($invoice->period_start)->lessThanOrEqualTo($today))->sum(fn (BillingInvoice $invoice): int => max(0, $invoice->amount - $invoice->paid_amount));
        $current = $invoices->filter(fn (BillingInvoice $invoice): bool => $invoice->kind !== 'entry' && $this->date($invoice->period_start)->lessThanOrEqualTo($today) && $this->date($invoice->period_end)->greaterThan($today))->last();
        $state = 'expired';
        $until = null;
        foreach ($invoices->where('kind', '!=', 'entry')->sortBy('period_start') as $invoice) {
            if ($invoice->status !== 'paid') {
                break;
            }
            $until = $invoice->period_end->toDateString();
        }
        if ($subject->archived_at !== null || $member?->status === CommunityMemberStatus::Archived) {
            $state = 'archived';
        } elseif ($subject->trial_end !== null && $today->lessThan($this->date($subject->trial_end))) {
            $state = 'trial';
            if ($until === null || $this->date($until)->lessThan($this->date($subject->trial_end))) {
                $until = $subject->trial_end->toDateString();
            }
        } elseif ($arrears === 0 && ($current !== null || ($until !== null && $this->date($until)->greaterThan($today)))) {
            $state = ($current?->snapshot['exempt'] ?? false) ? 'exempt' : 'active';
        } elseif ($subject->scheme === 'legacy_calendar' && ($community->dues_amount ?? 0) <= 0 && $arrears === 0) {
            $state = 'exempt';
            $until = null;
        }

        return [
            'state' => $state, 'active' => in_array($state, ['trial', 'active', 'exempt'], true),
            'trial_start' => $subject->trial_start?->toDateString(), 'trial_end' => $subject->trial_end?->toDateString(),
            'valid_until' => $until, 'arrears_amount' => $arrears, 'inactive_at' => $subject->inactive_at?->toDateString(),
            'billing_scheme' => $subject->scheme,
        ];
    }

    /** @return array<string, mixed> */
    public function summary(User $viewer, Community $community, string $scope, ?int $targetMemberId = null): array
    {
        $subject = $this->subjectFor($viewer, $community, $scope, $targetMemberId);
        $entitlement = $this->entitlement($subject);

        return [
            'scope' => $scope, 'entitlement' => $entitlement,
            'platform_active' => $this->entitlement($this->platformSubject($community))['active'],
            'invoices' => BillingInvoice::query()->whereIn('subject_id', $this->subjectIds($subject))->orderBy('period_start')->orderBy('kind')->get()->map(fn (BillingInvoice $invoice): array => $this->invoiceData($invoice))->all(),
            'settings' => [
                'member_amount' => (int) ($community->dues_amount ?? 0), 'entry_fee' => $community->entry_fee,
                'period_months' => (int) $community->dues_period_months, 'platform_monthly_amount' => (int) config('finance.platform_monthly_amount'),
                'bank_name' => $scope === 'member' ? $community->dues_bank_name : config('finance.platform_bank_name'),
                'account_number' => $scope === 'member' ? $community->dues_account_number : config('finance.platform_account_number'),
                'account_name' => $scope === 'member' ? $community->dues_account_name : config('finance.platform_account_name'),
            ],
            'can_manage' => $scope === 'platform' ? $viewer->hasRole('super-admin') : $viewer->can('manageDues', $community),
            'can_configure' => $viewer->can('configureDues', $community),
            'notices' => DB::table('billing_notices')->whereIn('subject_id', $this->subjectIds($subject))->where('user_id', $viewer->id)->orderByDesc('id')->get(['id', 'type', 'message', 'created_at'])->map(fn (object $notice): array => ['id' => $notice->id, 'type' => $notice->type, 'message' => $notice->message, 'created_at' => CarbonImmutable::parse($notice->created_at, 'UTC')->toIso8601String()])->all(),
        ];
    }

    public function subjectFor(User $viewer, Community $community, string $scope, ?int $targetMemberId = null): BillingSubject
    {
        if ($scope === 'platform') {
            abort_unless($community->user_id === $viewer->id || $viewer->hasRole('super-admin'), 403);

            return $this->platformSubject($community);
        }
        $member = $targetMemberId === null ? $community->membershipOf($viewer) : $community->members()->whereKey($targetMemberId)->first();
        if ($targetMemberId !== null && $member !== null && $member->user_id !== $viewer->id) {
            $guardian = DB::table('guardian_grants')->where('member_identity_id', $member->member_identity_id)->where('guardian_user_id', $viewer->id)->whereNull('revoked_at')->exists();
            abort_unless($viewer->can('manageDues', $community) || $guardian, 403, 'Tidak berhak melihat atau membayar tagihan anggota ini.');
        }
        abort_if($member === null || $member->merged_into_member_id !== null, 403, 'Anda bukan anggota komunitas ini.');

        return $this->memberSubject($member);
    }

    /** @param list<string> $ids @return array<string, mixed> */
    public function pay(User $actor, Community $community, string $scope, array $ids, string $key, ?int $targetMemberId = null, ?int $expectedAmount = null): array
    {
        $subject = $this->subjectFor($actor, $community, $scope, $targetMemberId);
        DB::transaction(function () use ($actor, $community, $subject, $ids, $key, $expectedAmount): void {
            Community::query()->whereKey($community->id)->lockForUpdate()->firstOrFail();
            $subject = $this->refresh($subject);
            BillingSubject::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            $all = BillingInvoice::query()->whereIn('subject_id', $this->subjectIds($subject))->whereIn('status', ['open', 'partially_paid'])->orderBy('period_start')->orderBy('kind')->lockForUpdate()->get();
            $selected = $ids === [] ? $all : $all->whereIn('id', $ids);
            // A replay must reach the journal before considering already-paid invoices.
            $payloadIds = $ids;
            if ($ids === []) {
                $previous = DB::table('billing_allocations')->where('source_reference', 'wallet:'.$subject->id.':'.$actor->id.':'.$key)->pluck('invoice_id')->all();
                if ($previous !== []) {
                    $selected = BillingInvoice::query()->whereIn('id', $previous)->orderBy('period_start')->get();
                    $payloadIds = $previous;
                }
            }
            $amount = (int) $selected->sum(fn (BillingInvoice $invoice): int => max(0, $invoice->amount - $invoice->paid_amount));
            $reference = 'wallet:'.$subject->id.':'.$actor->id.':'.$key;
            $previousAmount = (int) DB::table('billing_allocations')->where('source_reference', $reference)->sum('amount');
            if ($previousAmount > 0) {
                abort_if($expectedAmount !== null && $expectedAmount !== $previousAmount, 409, 'Kunci pembayaran sudah digunakan dengan nominal konfirmasi berbeda.');
                $previousIds = DB::table('billing_allocations')->where('source_reference', $reference)->pluck('invoice_id')->sort()->values()->all();
                $check = $ids;
                sort($check);
                abort_if($check !== [] && $check !== $previousIds, 409, 'Kunci idempotensi sudah dipakai untuk tagihan berbeda.');

                return;
            }
            abort_if($expectedAmount !== null && $amount !== $expectedAmount, 409, 'Tagihan berubah. Muat ulang dan konfirmasikan nominal terbaru.');
            abort_if($selected->isEmpty() || $amount <= 0, 422, 'Tidak ada tagihan yang perlu dibayar.');
            abort_if($ids !== [] && $selected->count() !== count(array_unique($ids)), 422, 'Tagihan tidak ditemukan atau sudah lunas.');
            $earliest = $all->take($selected->count())->pluck('id')->sort()->values()->all();
            abort_if($selected->pluck('id')->sort()->values()->all() !== $earliest, 422, 'Lunasi tagihan tertua terlebih dahulu.');
            $ledger = app(LedgerService::class);
            $recipient = $subject->kind === 'platform' ? $ledger->platformAccount() : $ledger->communityAccount($community);
            $ledger->transfer($actor, $key, 'billing_pay:'.$subject->id, $ledger->walletAccount(MemberIdentity::forUser($actor)), $recipient, $amount, $community, ['invoice_ids' => $payloadIds === [] ? $selected->pluck('id')->all() : $payloadIds, 'expected_amount' => $amount]);
            foreach ($selected as $invoice) {
                $this->allocate($invoice, $invoice->amount - $invoice->paid_amount, $reference, $actor->id);
            }
            $this->activate($subject);
        }, 3);

        return $this->summary($actor, $community->refresh(), $scope, $targetMemberId);
    }

    /** @param list<string> $invoiceIds @return list<array{account_id:int,amount:int,reference:string}> */
    public function allocateReceipt(IncomingReceipt $receipt, int $verifiedAmount, array $invoiceIds): array
    {
        $payer = User::query()->findOrFail($receipt->user_id);
        $community = Community::query()->findOrFail($receipt->community_id);
        $scope = $receipt->purpose === 'platform' ? 'platform' : 'member';
        $subject = $this->subjectFor($payer, $community, $scope, $receipt->target_member_id);
        $this->refresh($subject);
        BillingSubject::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
        $invoices = BillingInvoice::query()->whereIn('subject_id', $this->subjectIds($subject))->whereIn('status', ['open', 'partially_paid'])->orderBy('period_start')->orderBy('kind')->lockForUpdate()->get();
        if ($invoiceIds !== []) {
            abort_if(BillingInvoice::query()->whereIn('subject_id', $this->subjectIds($subject))->whereIn('id', $invoiceIds)->count() !== count(array_unique($invoiceIds)), 422, 'Tagihan penerimaan tidak sah.');
        }
        $selected = $invoiceIds === [] ? $invoices : $invoices->whereIn('id', $invoiceIds);
        if ($selected->isEmpty() && $receipt->credit_excess) {
            return [['account_id' => app(LedgerService::class)->walletAccount(MemberIdentity::forUser($payer))->id, 'amount' => $verifiedAmount, 'reference' => 'receipt:'.$receipt->id.':excess']];
        }
        abort_if($selected->isEmpty() || ($invoiceIds !== [] && $selected->count() !== count(array_unique($invoiceIds)) && ! $receipt->credit_excess), 422, 'Tagihan penerimaan tidak sah atau sudah lunas.');
        abort_if($selected->pluck('id')->sort()->values()->all() !== $invoices->take($selected->count())->pluck('id')->sort()->values()->all(), 422, 'Alokasikan tagihan tertua terlebih dahulu.');
        $outstanding = (int) $selected->sum(fn (BillingInvoice $invoice): int => $invoice->amount - $invoice->paid_amount);
        abort_if($verifiedAmount > $outstanding && ! $receipt->credit_excess, 422, 'Nominal melebihi tagihan. Aktifkan alokasi kelebihan ke saldo untuk penerimaan ini.');
        $remaining = min($verifiedAmount, $outstanding);
        foreach ($selected as $invoice) {
            $allocation = min($remaining, $invoice->amount - $invoice->paid_amount);
            if ($allocation > 0) {
                $this->allocate($invoice, $allocation, 'receipt:'.$receipt->id, $receipt->reviewed_by);
                $remaining -= $allocation;
            }
        }
        $ledger = app(LedgerService::class);
        $account = $scope === 'platform' ? $ledger->platformAccount() : $ledger->communityAccount($community);
        $this->activate($subject);
        $postings = [['account_id' => $account->id, 'amount' => min($verifiedAmount, $outstanding), 'reference' => 'receipt:'.$receipt->id]];
        if ($verifiedAmount > $outstanding) {
            $postings[] = ['account_id' => $ledger->walletAccount(MemberIdentity::forUser($payer))->id, 'amount' => $verifiedAmount - $outstanding, 'reference' => 'receipt:'.$receipt->id.':excess'];
        }

        return $postings;
    }

    private function allocate(BillingInvoice $invoice, int $amount, string $reference, ?int $actor): void
    {
        DB::table('billing_allocations')->insert(['invoice_id' => $invoice->id, 'source_reference' => $reference, 'amount' => $amount, 'actor_id' => $actor, 'created_at' => now()]);
        $paid = $invoice->paid_amount + $amount;
        $invoice->update(['paid_amount' => $paid, 'status' => $paid === $invoice->amount ? 'paid' : 'partially_paid', 'paid_at' => $paid === $invoice->amount ? now() : null]);
        $subject = BillingSubject::query()->findOrFail($invoice->subject_id);
        $member = $subject->member_id === null ? null : CommunityMember::query()->findOrFail($subject->member_id);
        if ($paid === $invoice->amount && $member !== null) {
            if ($invoice->kind === 'entry') {
                $member->forceFill(['entry_fee_paid_at' => now()])->save();
            } elseif ($invoice->legacy_month !== null && $member->user_id !== null) {
                $member->community->duesMonths()->updateOrCreate(['user_id' => $member->user_id, 'month' => $invoice->legacy_month.'-01'], ['status' => DuesMonthStatus::Paid, 'updated_by' => $actor]);
            }
        }
    }

    private function activate(BillingSubject $subject): void
    {
        $subject = $this->refresh($subject);
        $state = $this->entitlement($subject);
        if ($state['active'] && $subject->member_id !== null && $subject->archived_at === null) {
            CommunityMember::query()->whereKey($subject->member_id)->where('status', CommunityMemberStatus::Pending)->update(['status' => CommunityMemberStatus::Active, 'joined_at' => now()]);
        }
    }

    /** @param array<string, mixed> $data */
    public function settings(User $actor, Community $community, array $data): void
    {
        abort_unless($actor->can('configureDues', $community), 403);
        DB::transaction(function () use ($actor, $community, $data): void {
            Community::query()->whereKey($community->id)->lockForUpdate()->firstOrFail();
            $community->refresh();
            $period = (int) ($data['dues_period_months'] ?? $community->dues_period_months);
            $transition = [];
            foreach ($community->members()->whereNull('merged_into_member_id')->get() as $member) {
                $subject = $this->refresh($this->memberSubject($member));
                if ($period > 1 && $subject->scheme === 'legacy_calendar' && $subject->archived_at === null && $member->status !== CommunityMemberStatus::Archived) {
                    // Freeze the old price as well as the old monthly periods before saving new settings.
                    $transition[] = $this->freezeLegacyCommitments($subject, $community, $member);
                }
            }
            $community->forceFill($data)->save();
            foreach ($transition as $subject) {
                $this->convertLegacyPeriod($subject, $community, $actor);
            }
            activity('billing')->causedBy($actor)->performedOn($community)->event('billing_settings_updated')->withProperties(['effective' => 'next_unissued_period', 'settings' => $data])->log('billing_settings_updated');
        });
    }

    /** Idempotent repair for communities already configured with a multi-month period before this fix. */
    public function transitionLegacyPeriods(Community $community, ?User $actor = null): void
    {
        DB::transaction(function () use ($community, $actor): void {
            Community::query()->whereKey($community->id)->lockForUpdate()->firstOrFail();
            $community->refresh();
            if ($community->dues_period_months <= 1) {
                return;
            }
            foreach ($community->members()->whereNull('merged_into_member_id')->get() as $member) {
                $subject = $this->memberSubject($member);
                if ($subject->scheme !== 'legacy_calendar' || $subject->archived_at !== null || $member->status === CommunityMemberStatus::Archived) {
                    continue;
                }
                $subject = $this->freezeLegacyCommitments($subject, $community, $member);
                $this->convertLegacyPeriod($subject, $community, $actor);
            }
        }, 3);
    }

    private function freezeLegacyCommitments(BillingSubject $subject, Community $community, CommunityMember $member): BillingSubject
    {
        $subject = $this->refresh($subject);
        $lastMonth = $member->user_id === null ? null : DuesMonth::query()->where('community_id', $community->id)->where('user_id', $member->user_id)->whereIn('status', [DuesMonthStatus::Paid, DuesMonthStatus::Pending])->max('month');
        $lastEnd = BillingInvoice::query()->whereIn('subject_id', $this->subjectIds($subject))->where('kind', 'member')->where('status', '!=', 'void')->max('period_end');
        $through = $this->today();
        if ($lastMonth !== null) {
            $month = $this->date($lastMonth)->startOfMonth();
            $dueDay = $subject->due_day ?? 1;
            $start = $month->setDay(min($dueDay, $month->daysInMonth))->addDay();
            if ($start->greaterThan($through)) {
                $through = $start;
            }
        }
        if ($lastEnd !== null && $this->date($lastEnd)->subDay()->greaterThan($through)) {
            $through = $this->date($lastEnd)->subDay();
        }

        return $this->refresh($subject, false, $through);
    }

    private function convertLegacyPeriod(BillingSubject $subject, Community $community, ?User $actor): void
    {
        $subject = BillingSubject::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
        if ($subject->scheme !== 'legacy_calendar') {
            return;
        }
        $oldAnchor = $subject->anchor_date->toDateString();
        $nextMonth = $this->date($subject->anchor_date)->startOfMonth()->addMonths($subject->next_offset);
        $dueDay = $subject->due_day ?? 1;
        $anchor = $nextMonth->setDay(min($dueDay, $nextMonth->daysInMonth))->addDay();
        $lastEnd = BillingInvoice::query()->whereIn('subject_id', $this->subjectIds($subject))->where('kind', 'member')->where('status', '!=', 'void')->max('period_end');
        if ($lastEnd !== null && $this->date($lastEnd)->greaterThan($anchor)) {
            $anchor = $this->date($lastEnd);
        }
        $subject->update(['scheme' => 'anniversary', 'anchor_date' => $anchor->toDateString(), 'next_offset' => 0]);
        if ($subject->member_id !== null) {
            CommunityMember::query()->whereKey($subject->member_id)->update(['billing_scheme' => 'anniversary']);
        }
        $log = activity('billing')->performedOn($community)->event('billing_period_transition')->withProperties(['subject_id' => $subject->id, 'old_scheme' => 'legacy_calendar', 'new_scheme' => 'anniversary', 'old_anchor' => $oldAnchor, 'new_anchor' => $anchor->toDateString(), 'period_months' => $community->dues_period_months, 'effective' => 'next_unissued_period', 'preserved_legacy_invoices' => true]);
        if ($actor !== null) {
            $log->causedBy($actor);
        }
        $log->log('billing_period_transition');
    }

    /** @return array<string, mixed> */
    public function inactive(User $actor, Community $community): array
    {
        abort_unless($actor->can('manageDues', $community), 403);
        $members = [];
        foreach ($community->members()->whereNull('merged_into_member_id')->with(['identity', 'user'])->get() as $member) {
            $subject = $this->memberSubject($member);
            $state = $this->entitlement($subject);
            if (! $state['active']) {
                $members[] = ['id' => $member->id, 'name' => $member->identity !== null ? $member->identity->name : ($member->user !== null ? $member->user->name : 'Anggota'), 'role' => $member->role->value, 'entitlement' => $state, 'review_due' => $this->reviewDue($subject->refresh())];
            }
        }
        $platform = null;
        if ($actor->hasRole('super-admin') || $community->user_id === $actor->id) {
            $platformSubject = $this->platformSubject($community);
            $entitlement = $this->entitlement($platformSubject);
            $platform = ['id' => $community->id, 'name' => $community->name, 'entitlement' => $entitlement, 'review_due' => $actor->hasRole('super-admin') && $this->reviewDue($platformSubject->refresh()), 'can_decide' => $actor->hasRole('super-admin')];
        }

        return ['members' => $members, 'community' => $platform];
    }

    public function decide(User $actor, Community $community, string $kind, ?int $memberId, string $action, string $reason): void
    {
        if ($kind === 'platform') {
            abort_unless($actor->hasRole('super-admin'), 403);
            $subject = $this->platformSubject($community);
        } else {
            abort_unless($actor->can('manageDues', $community), 403);
            $member = $community->members()->whereKey($memberId)->firstOrFail();
            abort_if($member->role === CommunityMemberRole::Creator, 422, 'Pendiri diarsipkan bersama komunitas melalui admin platform.');
            abort_if($member->role === CommunityMemberRole::Admin && $community->membershipOf($actor)?->role !== CommunityMemberRole::Creator && ! $actor->hasRole('super-admin'), 403);
            $subject = $this->memberSubject($member);
        }
        DB::transaction(function () use ($actor, $subject, $action, $reason): void {
            $subject = $this->refresh($subject);
            BillingSubject::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->reviewDue($subject), 422, 'Keputusan tersedia setelah tiga bulan tidak aktif.');
            DB::table('billing_decisions')->insert(['subject_id' => $subject->id, 'actor_id' => $actor->id, 'action' => $action, 'reason' => $reason, 'created_at' => now()]);
            if ($action === 'retain') {
                $subject->update(['retained_until' => $this->today()->addMonthsNoOverflow(3)->toDateString()]);
            } else {
                $subject->update(['archived_at' => now(), 'archived_by' => $actor->id]);
                if ($subject->member_id !== null) {
                    CommunityMember::query()->whereKey($subject->member_id)->update(['status' => CommunityMemberStatus::Archived]);
                }
            }
        });
    }

    public function reviewDue(BillingSubject $subject): bool
    {
        return $subject->archived_at === null && $subject->inactive_at !== null
            && $this->today()->greaterThanOrEqualTo($subject->retained_until === null ? $this->date($subject->inactive_at)->addMonthsNoOverflow(3) : $this->date($subject->retained_until));
    }

    public function process(): int
    {
        $count = 0;
        foreach (Community::query()->orderBy('id')->cursor() as $community) {
            $this->refresh($this->platformSubject($community));
            foreach ($community->members()->whereNull('merged_into_member_id')->orderBy('id')->cursor() as $member) {
                $this->refresh($this->memberSubject($member));
            }
        }
        foreach (BillingSubject::query()->whereNull('merged_into_subject_id')->orderBy('id')->cursor() as $subject) {
            $state = $this->entitlement($subject);
            $until = $state['valid_until'];
            if ($subject->archived_at !== null) {
                continue;
            }
            if ($until !== null && $this->today()->greaterThanOrEqualTo($this->date($until)->subDays(3)) && $this->today()->lessThan($this->date($until))) {
                $count += $this->notice($subject, 'expires_soon', $until, 'Masa aktif berakhir pada '.$until.'. Siapkan saldo atau unggah bukti pembayaran.');
            }
            if ($this->reviewDue($subject)) {
                $period = $subject->retained_until?->toDateString() ?? $subject->inactive_at?->toDateString();
                $count += $this->notice($subject, 'inactive_review', (string) $period, 'Sudah tiga bulan tidak aktif. Pilih pertahankan atau arsipkan; histori uang tetap disimpan.');
            }
        }

        return $count;
    }

    private function notice(BillingSubject $subject, string $type, string $period, string $message): int
    {
        $community = Community::query()->findOrFail($subject->community_id);
        if ($type === 'inactive_review') {
            $users = $subject->kind === 'platform'
                ? User::query()->role('super-admin')->pluck('id')->all()
                : $community->members()->where('status', CommunityMemberStatus::Active)->whereIn('role', ['creator', 'admin'])->whereNotNull('user_id')->pluck('user_id')->all();
        } else {
            $userId = $subject->member_id === null ? $community->user_id : CommunityMember::query()->findOrFail($subject->member_id)->user_id;
            $users = $userId === null ? [] : [$userId];
        }
        $count = 0;
        foreach ($users as $id) {
            $count += DB::table('billing_notices')->insertOrIgnore(['subject_id' => $subject->id, 'user_id' => $id, 'deduplication_key' => $subject->id.':'.$type.':'.$period.':'.$id, 'type' => $type, 'message' => $message, 'created_at' => now()]);
        }

        return $count;
    }

    /** @return array<string, mixed> */
    public function invoiceData(BillingInvoice $invoice): array
    {
        return ['id' => $invoice->id, 'kind' => $invoice->kind, 'period_start' => $invoice->period_start->toDateString(), 'period_end' => $invoice->period_end->toDateString(), 'amount' => $invoice->amount, 'paid_amount' => $invoice->paid_amount, 'outstanding' => $invoice->status === 'void' ? 0 : max(0, $invoice->amount - $invoice->paid_amount), 'status' => $invoice->status, 'snapshot' => $invoice->snapshot];
    }

    /** @param array<string, mixed> $snapshot */
    private function invoice(BillingSubject $subject, string $kind, CarbonImmutable $start, CarbonImmutable $end, int $amount, bool $paid, ?string $legacyMonth, array $snapshot): void
    {
        BillingInvoice::query()->firstOrCreate(['source_key' => $subject->subject_key.':'.$kind.':'.($legacyMonth ?? $start->toDateString())], [
            'id' => (string) Str::uuid(), 'subject_id' => $subject->id, 'community_id' => $subject->community_id,
            'member_identity_id' => $subject->member_identity_id, 'kind' => $kind,
            'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(),
            'amount' => $amount, 'paid_amount' => $paid ? $amount : 0, 'status' => $paid ? 'paid' : 'open',
            'snapshot' => $snapshot, 'legacy_month' => $legacyMonth, 'paid_at' => $paid ? now() : null,
        ]);
    }

    public function pauseMembership(CommunityMember $member): void
    {
        $subject = $this->refresh($this->memberSubject($member));
        $subject->update(['paused_at' => $this->today()->toDateString()]);
    }

    /** @return list<int> */
    private function subjectIds(BillingSubject $subject): array
    {
        return [$subject->id, ...BillingSubject::query()->where('merged_into_subject_id', $subject->id)->pluck('id')->all()];
    }

    public function mergeIdentity(MemberIdentity $source, MemberIdentity $target, User $actor): void
    {
        foreach ($source->memberships()->whereNull('merged_into_member_id')->get() as $sourceMember) {
            $sourceSubject = $this->refresh($this->memberSubject($sourceMember));
            $canonicalMember = $target->memberships()->where('community_id', $sourceMember->community_id)->first();
            $targetSubject = $canonicalMember === null ? null : $this->refresh($this->memberSubject($canonicalMember));
            $primary = $targetSubject !== null && $this->date($targetSubject->trial_start ?? $targetSubject->anchor_date)->lessThan($this->date($sourceSubject->trial_start ?? $sourceSubject->anchor_date)) ? $targetSubject : $sourceSubject;
            $secondary = $targetSubject === null ? null : ($primary->id === $sourceSubject->id ? $targetSubject : $sourceSubject);
            if ($secondary !== null) {
                $secondary->update(['subject_key' => 'merged:'.$secondary->id.':'.$secondary->subject_key, 'merged_into_subject_id' => $primary->id]);
                BillingSubject::query()->where('merged_into_subject_id', $secondary->id)->update(['merged_into_subject_id' => $primary->id]);
            }
            $primary->update(['subject_key' => 'member:'.$sourceMember->community_id.':'.$target->id, 'member_identity_id' => $target->id, 'member_id' => $canonicalMember === null ? $sourceMember->id : $canonicalMember->id]);
            activity('billing')->causedBy($actor)->performedOn($sourceMember->community)->event('billing_identity_merged')->withProperties(['source_identity_id' => $source->id, 'target_identity_id' => $target->id, 'subject_id' => $primary->id, 'merged_subject_id' => $secondary?->id, 'trial_preserved' => true, 'historical_obligations_preserved' => true])->log('billing_identity_merged');
        }
    }

    private function restoreLegacyRows(BillingSubject $subject, Community $community, ?CommunityMember $member): void
    {
        if ($subject->scheme !== 'legacy_calendar' || $member?->user_id === null) {
            return;
        }
        foreach (DuesPayment::query()->where('community_id', $community->id)->where('user_id', $member->user_id)->whereIn('status', ['pending', 'approved'])->get() as $payment) {
            foreach ($payment->months as $month) {
                if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
                    continue;
                }
                $community->duesMonths()->firstOrCreate(['user_id' => $member->user_id, 'month' => $month.'-01'], ['status' => $payment->status === DuesPaymentStatus::Approved ? DuesMonthStatus::Paid : DuesMonthStatus::Pending, 'dues_payment_id' => $payment->id, 'updated_by' => $payment->reviewed_by]);
            }
            if ($payment->status === DuesPaymentStatus::Approved && $payment->entry_fee > 0 && $member->entry_fee_paid_at === null) {
                $member->forceFill(['entry_fee_paid_at' => $payment->reviewed_at ?? $payment->created_at ?? now()])->save();
            }
        }
    }

    private function importLegacyPaid(BillingSubject $subject, Community $community, ?CommunityMember $member): void
    {
        if ($subject->scheme !== 'legacy_calendar' || $member?->user_id === null) {
            return;
        }
        $rows = DuesMonth::query()->where('community_id', $community->id)->where('user_id', $member->user_id)->where('status', DuesMonthStatus::Paid)->with('payment')->get();
        foreach ($rows as $row) {
            $month = $this->date($row->month)->startOfMonth();
            if ($month->lessThan($this->date($subject->anchor_date)->startOfMonth())) {
                continue;
            }
            $day = $subject->due_day ?? ($community->dues_start_date === null ? 1 : $community->dues_start_date->day);
            $start = $month->setDay(min($day, $month->daysInMonth))->addDay();
            $next = $month->addMonth();
            $end = $next->setDay(min($day, $next->daysInMonth))->addDay();
            $amount = $row->payment === null ? 0 : $row->payment->monthly_amount;
            $this->invoice($subject, 'member', $start, $end, $amount, true, $month->format('Y-m'), ['legacy_paid' => true, 'legacy_nominal_unrecorded' => $row->payment === null, 'legacy_dues_payment_id' => $row->dues_payment_id, 'period_months' => 1, 'exempt' => false]);
        }
    }

    /** @return array<string, mixed> */
    public function renew(User $actor, Community $community, string $scope, ?int $targetMemberId = null): array
    {
        $subject = $this->subjectFor($actor, $community, $scope, $targetMemberId);
        $this->refresh($subject);
        $this->refresh($subject->refresh(), true);

        return $this->summary($actor, $community, $scope, $targetMemberId);
    }

    private function legacyAnchor(Community $community, CommunityMember $member): CarbonImmutable
    {
        $start = $community->dues_start_date === null ? $this->today()->startOfMonth() : $this->date($community->dues_start_date)->startOfMonth();
        $joined = $this->date($member->joined_at ?? $member->created_at ?? $this->today())->startOfMonth();

        return $joined->greaterThan($start) ? $joined : $start;
    }

    private function exemptRole(CommunityMemberRole $role): bool
    {
        return in_array($role, [CommunityMemberRole::Creator, CommunityMemberRole::Admin, CommunityMemberRole::Coach], true);
    }

    private function date(mixed $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date instanceof \DateTimeInterface ? CarbonImmutable::instance($date)->setTimezone('Asia/Jakarta')->toDateString() : (string) $date, 'Asia/Jakarta')->startOfDay();
    }
}
