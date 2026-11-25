<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\BillingInvoice;
use App\Models\BillingSubject;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\DuesMonth;
use App\Models\DuesPayment;
use App\Models\User;
use App\Support\Enums\CommunityMemberStatus;
use App\Support\Enums\DuesMonthStatus;
use App\Support\Enums\DuesPaymentStatus;
use App\Support\Enums\MemberCardStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Iuran bulanan komunitas. Tagihan anggota mulai dari bulan yang lebih akhir antara
 * mulai iuran dan bulan bergabung; jatuh tempo tiap bulan pada tanggal mulai iuran.
 * Bulan hanya tersimpan saat lunas atau sedang diajukan, sisanya dihitung.
 */
class DuesService
{
    /** Tanggal jatuh tempo dan "hari ini" mengikuti waktu Indonesia barat. */
    public const TIMEZONE = 'Asia/Jakarta';

    public const MAX_MONTHS_PER_PAYMENT = 12;

    /** Batas perulangan bulan; jauh di atas umur komunitas mana pun. */
    private const MAX_SPAN_MONTHS = 1200;

    public function __construct(private readonly ImageAssetGuard $images) {}

    public function isConfigured(Community $community): bool
    {
        return ($community->dues_amount ?? 0) > 0 && $community->dues_start_date !== null;
    }

    /** Hari ini menurut [TIMEZONE], sebagai tanggal tanpa jam. */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now(self::TIMEZONE)->toDateString());
    }

    /**
     * @param  array{dues_amount: int, entry_fee: int, dues_start_date: string, dues_bank_name: string, dues_account_number: string, dues_account_name: string}  $data
     */
    public function updateSettings(Community $community, array $data): Community
    {
        $actor = auth('api')->user();
        if ($actor instanceof User) {
            app(BillingService::class)->settings($actor, $community, $data);
        } else {
            foreach ($community->members()->whereNull('merged_into_member_id')->get() as $member) {
                app(BillingService::class)->refresh(app(BillingService::class)->memberSubject($member));
            }
            $community->forceFill($data)->save();
        }

        return $community;
    }

    /** Bulan pertama yang ditagihkan ke [member]; null bila iuran belum diatur. */
    public function billableFrom(Community $community, ?CommunityMember $member): ?CarbonImmutable
    {
        if (! $this->isConfigured($community)) {
            return null;
        }

        $start = $this->monthOf($community->dues_start_date?->toDateString() ?? '');
        $joined = $member === null ? null : ($member->joined_at ?? $member->created_at);
        if ($joined === null) {
            return $start;
        }

        $joinedMonth = $this->monthOf(CarbonImmutable::parse($joined)->setTimezone(self::TIMEZONE)->toDateString());

        return $joinedMonth->greaterThan($start) ? $joinedMonth : $start;
    }

    /**
     * Ringkasan iuran satu anggota beserta kalender [year].
     *
     * @param  array<string, DuesMonthStatus>|null  $rows  bulan tersimpan; null = dimuat di sini
     * @return array{billable_from: string|null, arrears_count: int, paid_until: string|null, entry_fee_paid: bool, months: list<array{month: string, status: string}>, billing_scheme?: string, calendar_read_only?: bool, calendar_label?: string, billing?: array<string, mixed>}
     */
    public function memberCalendar(Community $community, int $userId, ?CommunityMember $member, int $year, ?array $rows = null): array
    {
        $rows ??= $this->rowsOf($community, [$userId])[$userId] ?? [];
        $from = $this->billableFrom($community, $member);
        $today = $this->today();
        if ($member !== null && $member->billing_scheme === 'anniversary') {
            $billing = app(BillingService::class);
            $subject = $billing->memberSubject($member);
            $entitlement = $billing->entitlement($subject);
            $months = [];
            for ($m = 1; $m <= 12; $m++) {
                $month = sprintf('%04d-%02d', $year, $m);
                $months[] = ['month' => $month, 'status' => ($rows[$month] ?? DuesMonthStatus::None)->value];
            }

            return ['billable_from' => $from?->format('Y-m'), 'arrears_count' => BillingInvoice::query()->whereIn('subject_id', [$subject->id, ...BillingSubject::query()->where('merged_into_subject_id', $subject->id)->pluck('id')->all()])->whereIn('status', ['open', 'partially_paid'])->where('kind', 'member')->whereDate('period_start', '<=', $today->toDateString())->count(), 'paid_until' => null, 'entry_fee_paid' => $member->entry_fee_paid_at !== null, 'months' => $months, 'billing_scheme' => 'anniversary', 'calendar_read_only' => true, 'calendar_label' => 'Riwayat kalender lama; masa aktif mengikuti periode pembayaran.', 'billing' => $entitlement];
        }

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $month = CarbonImmutable::create($year, $m, 1);
            $months[] = ['month' => $month->format('Y-m'), 'status' => $this->statusOf($community, $month, $rows, $from, $today)->value];
        }

        [$arrears, $paidUntil] = $this->arrears($community, $rows, $from, $today);

        return [
            'billable_from' => $from?->format('Y-m'),
            'arrears_count' => $arrears,
            'paid_until' => $paidUntil?->format('Y-m'),
            'entry_fee_paid' => $member?->entry_fee_paid_at !== null,
            'months' => $months,
        ];
    }

    /**
     * Bulan yang akan dibayar berikutnya, tunggakan paling awal dulu.
     *
     * @return list<string>
     */
    public function nextUnpaidMonths(Community $community, int $userId, ?CommunityMember $member, int $limit = self::MAX_MONTHS_PER_PAYMENT): array
    {
        if ($member?->billing_scheme === 'anniversary') {
            return [];
        }
        $from = $this->billableFrom($community, $member);
        if ($from === null) {
            return [];
        }

        $rows = $this->rowsOf($community, [$userId])[$userId] ?? [];
        $months = [];
        for ($i = 0, $month = $from; count($months) < $limit && $i < self::MAX_SPAN_MONTHS; $i++, $month = $month->addMonthNoOverflow()) {
            if (! isset($rows[$month->format('Y-m')])) {
                $months[] = $month->format('Y-m');
            }
        }

        return $months;
    }

    /**
     * Masa berlaku kartu anggota: bulan terakhir yang lunas berturut-turut sejak awal tagihan
     * (`paid_until`, sama dengan halaman Iuran) berlaku sampai jatuh tempo bulan sesudahnya.
     * Pada hari itu tagihan berikutnya belum menunggak; esoknya kartu kedaluwarsa.
     *
     * @return array{status: MemberCardStatus, valid_until: CarbonImmutable|null, paid_until: CarbonImmutable|null}
     */
    public function cardValidity(Community $community, CommunityMember $member): array
    {
        $subject = app(BillingService::class)->memberSubject($member);
        $entitlement = app(BillingService::class)->entitlement($subject);
        if ($subject->scheme === 'legacy_calendar' && ! $this->isConfigured($community) && $entitlement['arrears_amount'] === 0) {
            return ['status' => MemberCardStatus::NoDues, 'valid_until' => null, 'paid_until' => null];
        }
        $until = $entitlement['valid_until'] === null ? null : CarbonImmutable::parse($entitlement['valid_until'], self::TIMEZONE)->subDay();
        $paidUntil = $subject->scheme === 'legacy_calendar' && $member->user_id !== null ? $this->legacyCardValidity($community, $member)['paid_until'] : null;

        return ['status' => $entitlement['active'] ? MemberCardStatus::Active : ($until === null && $subject->scheme === 'legacy_calendar' ? MemberCardStatus::Inactive : MemberCardStatus::Expired), 'valid_until' => $until, 'paid_until' => $paidUntil];
    }

    /** @return array{status: MemberCardStatus, valid_until: CarbonImmutable|null, paid_until: CarbonImmutable|null} */
    public function legacyCardValidity(Community $community, CommunityMember $member): array
    {
        $from = $this->billableFrom($community, $member);
        if ($from === null) {
            return ['status' => MemberCardStatus::NoDues, 'valid_until' => null, 'paid_until' => null];
        }

        $today = $this->today();
        $rows = $this->rowsOf($community, [$member->user_id])[$member->user_id] ?? [];
        [, $paidUntil] = $this->arrears($community, $rows, $from, $today);
        if ($paidUntil === null) {
            return ['status' => MemberCardStatus::Inactive, 'valid_until' => null, 'paid_until' => null];
        }

        $validUntil = $this->dueDate($community, $paidUntil->addMonthNoOverflow());

        return [
            'status' => $validUntil->lessThan($today) ? MemberCardStatus::Expired : MemberCardStatus::Active,
            'valid_until' => $validUntil,
            'paid_until' => $paidUntil,
        ];
    }

    /** Uang masuk yang masih harus dibayar [member]: 0 bila lunas atau sedang diajukan. */
    public function entryFeeDue(Community $community, CommunityMember $member): int
    {
        if ($member->billing_scheme === 'anniversary') {
            $billing = app(BillingService::class);
            $subject = $billing->refresh($billing->memberSubject($member));

            return (int) BillingInvoice::query()->whereIn('subject_id', [$subject->id, ...BillingSubject::query()->where('merged_into_subject_id', $subject->id)->pluck('id')->all()])->where('kind', 'entry')->whereIn('status', ['open', 'partially_paid'])->sum(DB::raw('amount - paid_amount'));
        }
        if ($community->entry_fee <= 0 || $member->entry_fee_paid_at !== null) {
            return 0;
        }

        $pending = $community->duesPayments()
            ->where('user_id', $member->user_id)
            ->where('status', DuesPaymentStatus::Pending)
            ->where('entry_fee', '>', 0)
            ->exists();

        return $pending ? 0 : $community->entry_fee;
    }

    /**
     * Ajukan pembayaran [monthsCount] bulan (tunggakan dulu) beserta bukti transfer. Bulannya
     * berstatus menunggu sampai diverifikasi creator/admin.
     *
     * @throws ValidationException
     */
    public function submitPayment(User $payer, Community $community, int $monthsCount, string $proofAssetId, ?string $note): DuesPayment
    {
        if (! $this->isConfigured($community)) {
            $this->reject('months_count', 'Iuran komunitas ini belum diatur pengurus.');
        }

        $this->images->assertUsable($payer, [$proofAssetId], 'proof_asset_id');
        app(SensitiveAssetService::class)->privatize(Asset::query()->findOrFail($proofAssetId));

        try {
            return DB::transaction(function () use ($payer, $community, $monthsCount, $proofAssetId, $note): DuesPayment {
                $community = Community::query()->whereKey($community->id)->lockForUpdate()->firstOrFail();
                // Satu pengajuan per anggota pada satu waktu: bulan yang dipilih tidak berubah di tengah jalan.
                $member = CommunityMember::query()
                    ->where('community_id', $community->getKey())
                    ->where('user_id', $payer->getKey())
                    ->where('status', CommunityMemberStatus::Active)
                    ->lockForUpdate()
                    ->first();
                if ($member === null) {
                    $this->reject('months_count', 'Hanya anggota komunitas ini yang bisa membayar iuran.');
                }

                $subject = app(BillingService::class)->memberSubject($member);
                if ($subject->scheme !== 'legacy_calendar') {
                    $this->reject('months_count', 'Gunakan menu Pembayaran keanggotaan untuk tagihan berdasarkan masa aktif.');
                }
                $months = $this->nextUnpaidMonths($community, (int) $payer->getKey(), $member, $monthsCount);
                $entryFee = $this->entryFeeDue($community, $member);
                $monthly = (int) $community->dues_amount;

                $payment = new DuesPayment([
                    'months_count' => count($months),
                    'months' => $months,
                    'monthly_amount' => $monthly,
                    'entry_fee' => $entryFee,
                    'total_amount' => $monthly * count($months) + $entryFee,
                    'proof_asset_id' => $proofAssetId,
                    'note' => $note,
                ]);
                $payment->user_id = $payer->getKey();
                $payment->status = DuesPaymentStatus::Pending;
                $community->duesPayments()->save($payment);

                foreach ($months as $month) {
                    $community->duesMonths()->create([
                        'user_id' => $payer->getKey(),
                        'month' => $month.'-01',
                        'status' => DuesMonthStatus::Pending,
                        'dues_payment_id' => $payment->getKey(),
                    ]);
                }

                Asset::query()->whereKey($proofAssetId)->update(['retain_until' => null]);

                app(IncomingReceiptService::class)->registerLegacyProof($payment, false, false);

                return $payment;
            });
        } catch (UniqueConstraintViolationException) {
            $this->reject('months_count', 'Bulan yang sama sudah diajukan. Muat ulang lalu coba lagi.');
        }
    }

    /** Terima pembayaran: bulannya lunas, begitu juga uang masuk yang ikut dibayar. */
    public function approve(User $actor, DuesPayment $payment, ?int $verifiedAmount = null, ?string $bankReference = null): DuesPayment
    {
        DB::transaction(function () use ($actor, $payment, $verifiedAmount, $bankReference): void {
            $snapshot = DuesPayment::query()->findOrFail($payment->id);
            Community::query()->whereKey($snapshot->community_id)->lockForUpdate()->firstOrFail();
            $payment = DuesPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            abort_if($payment->community_id !== $snapshot->community_id, 409, 'Konteks pembayaran berubah. Muat ulang.');
            abort_unless($payment->status === DuesPaymentStatus::Pending, 409, 'Pembayaran ini sudah diverifikasi.');
            abort_if($verifiedAmount === null || $bankReference === null, 422, 'Masukkan nominal terverifikasi dan referensi bank melalui formulir pembayaran.');
            $community = $payment->community;
            $member = $community->membershipOf(User::query()->findOrFail($payment->user_id));
            abort_if($member === null, 422, 'Keanggotaan pembayaran tidak ditemukan.');
            $billing = app(BillingService::class);
            $subject = $billing->refresh($billing->memberSubject($member));
            $ids = [];
            foreach ($payment->months as $month) {
                $date = CarbonImmutable::parse($month.'-01', self::TIMEZONE);
                $day = $subject->due_day ?? ($community->dues_start_date === null ? 1 : $community->dues_start_date->day);
                $start = $date->setDay(min($day, $date->daysInMonth))->addDay();
                $next = $date->addMonth();
                $end = $next->setDay(min($day, $next->daysInMonth))->addDay();
                $invoice = BillingInvoice::query()->firstOrCreate(['source_key' => $subject->subject_key.':member:'.$month], [
                    'id' => (string) Str::uuid(), 'subject_id' => $subject->id, 'community_id' => $community->id, 'member_identity_id' => $member->member_identity_id,
                    'kind' => 'member', 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(), 'amount' => $payment->monthly_amount,
                    'paid_amount' => 0, 'status' => 'open', 'legacy_month' => $month, 'snapshot' => ['legacy_dues_payment_id' => $payment->id, 'period_months' => 1, 'role' => $member->role->value],
                ]);
                $ids[] = $invoice->id;
            }
            if ($payment->entry_fee > 0) {
                $invoice = BillingInvoice::query()->firstOrCreate(['source_key' => $subject->subject_key.':entry'], [
                    'id' => (string) Str::uuid(), 'subject_id' => $subject->id, 'community_id' => $community->id, 'member_identity_id' => $member->member_identity_id,
                    'kind' => 'entry', 'period_start' => $subject->anchor_date->toDateString(), 'period_end' => $subject->anchor_date->copy()->addMonthNoOverflow()->toDateString(), 'amount' => $payment->entry_fee,
                    'paid_amount' => 0, 'status' => 'open', 'snapshot' => ['legacy_dues_payment_id' => $payment->id],
                ]);
                $ids[] = $invoice->id;
            }
            $receipts = app(IncomingReceiptService::class);
            $receipt = $receipts->registerLegacyProof($payment);
            abort_unless($receipt->status === 'legacy_pending', 409, 'Bukti sudah diproses.');
            $receipt->forceFill(['purpose' => 'member', 'status' => 'pending', 'invoice_ids' => $ids])->save();
            $receipts->verify($actor, $receipt, $verifiedAmount, $bankReference, 'legacy-approve:'.$payment->id);
            $allPaid = BillingInvoice::query()->whereIn('id', $ids)->where('status', '!=', 'paid')->doesntExist();
            // Partial receipt is final money, while the legacy request stays pending until its invoices are fully paid.
            if ($allPaid) {
                $this->markReviewed($actor, $payment, DuesPaymentStatus::Approved, null);
                DuesMonth::query()->where('dues_payment_id', $payment->id)->update(['status' => DuesMonthStatus::Paid, 'updated_by' => $actor->id, 'updated_at' => now()]);
            }
        }, 3);
        $this->log($actor, $payment->community, 'dues_payment_approved', ['payment_id' => $payment->id, 'verified_amount' => $verifiedAmount]);

        return $payment->refresh();
    }

    /** Tolak pembayaran: bulannya kembali belum lunas. */
    public function rejectPayment(User $actor, DuesPayment $payment, string $note): DuesPayment
    {
        DB::transaction(function () use ($actor, $payment, $note): void {
            $snapshot = DuesPayment::query()->findOrFail($payment->id);
            Community::query()->whereKey($snapshot->community_id)->lockForUpdate()->firstOrFail();
            $payment = DuesPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            abort_if($payment->community_id !== $snapshot->community_id, 409, 'Konteks pembayaran berubah. Muat ulang.');
            $receipt = app(IncomingReceiptService::class)->registerLegacyProof($payment);
            abort_if($receipt->journal_id !== null, 409, 'Pembayaran sudah diterima sebagian. Lunasi sisa melalui menu pembayaran; uang yang sah tidak dibatalkan.');
            $receipt->forceFill(['status' => 'rejected', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $note])->save();
            $this->markReviewed($actor, $payment, DuesPaymentStatus::Rejected, $note);

            DuesMonth::query()
                ->where('dues_payment_id', $payment->getKey())
                ->where('status', DuesMonthStatus::Pending)
                ->delete();
        }, 3);

        $this->log($actor, $payment->community, 'dues_payment_rejected', ['payment_id' => $payment->getKey(), 'user_id' => $payment->user_id, 'note' => $note]);

        return $payment->refresh();
    }

    /**
     * Ubah manual status iuran satu bulan, mis. dibayar tunai.
     *
     * @throws ValidationException
     */
    public function setMonth(User $actor, Community $community, User $member, string $month, bool $paid): void
    {
        abort(422, 'Gunakan pembayaran saldo atau unggah bukti dengan nominal terverifikasi. Status uang tidak dapat diubah tanpa transaksi.');
    }

    public function setEntryFee(User $actor, Community $community, CommunityMember $member, bool $paid): void
    {
        abort(422, 'Gunakan pembayaran saldo atau bukti terverifikasi untuk uang masuk.');
    }

    /**
     * Anggota aktif beserta kalender iuran [year] dan tunggakannya.
     *
     * @return LengthAwarePaginator<int, CommunityMember>
     */
    public function membersOverview(Community $community, int $year, string $search, int $perPage): LengthAwarePaginator
    {
        $members = $community->members()
            ->select('community_members.*')
            ->join('users', 'users.id', '=', 'community_members.user_id')
            ->with('user')
            ->where('community_members.status', CommunityMemberStatus::Active)
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->whereRaw('lower(users.name) like ?', ['%'.addcslashes(mb_strtolower($search), '\\%_').'%']);
            })
            ->orderBy('users.name')
            ->orderBy('community_members.id')
            ->paginate($perPage);

        $userIds = array_map(fn (CommunityMember $member): int => $member->user_id, $members->items());
        $rows = $this->rowsOf($community, $userIds);

        foreach ($members->items() as $member) {
            $member->setAttribute('dues', $this->memberCalendar($community, $member->user_id, $member, $year, $rows[$member->user_id] ?? []));
        }

        return $members;
    }

    /**
     * Bulan tersimpan per pengguna, satu query.
     *
     * @param  list<int>  $userIds
     * @return array<int, array<string, DuesMonthStatus>>
     */
    private function rowsOf(Community $community, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = [];
        DuesMonth::query()
            ->where('community_id', $community->getKey())
            ->whereIn('user_id', $userIds)
            ->get(['user_id', 'month', 'status'])
            ->each(function (DuesMonth $row) use (&$rows): void {
                $rows[$row->user_id][$row->month->format('Y-m')] = $row->status;
            });

        return $rows;
    }

    /** @param  array<string, DuesMonthStatus>  $rows */
    private function statusOf(Community $community, CarbonImmutable $month, array $rows, ?CarbonImmutable $from, CarbonImmutable $today): DuesMonthStatus
    {
        $stored = $rows[$month->format('Y-m')] ?? null;
        if ($stored !== null) {
            return $stored;
        }
        if ($from === null || $month->lessThan($from)) {
            return DuesMonthStatus::None;
        }
        if ($this->dueDate($community, $month)->lessThan($today)) {
            return DuesMonthStatus::Overdue;
        }

        return $month->format('Y-m') === $today->format('Y-m') ? DuesMonthStatus::Due : DuesMonthStatus::Upcoming;
    }

    /**
     * Jumlah bulan menunggak dan bulan terakhir yang lunas berturut-turut sejak awal tagihan.
     *
     * @param  array<string, DuesMonthStatus>  $rows
     * @return array{int, CarbonImmutable|null}
     */
    private function arrears(Community $community, array $rows, ?CarbonImmutable $from, CarbonImmutable $today): array
    {
        if ($from === null) {
            return [0, null];
        }

        $arrears = 0;
        $paidUntil = null;
        $streak = true;
        $last = $today->startOfMonth();
        for ($i = 0, $month = $from; $i < self::MAX_SPAN_MONTHS; $i++, $month = $month->addMonthNoOverflow()) {
            $paid = ($rows[$month->format('Y-m')] ?? null) === DuesMonthStatus::Paid;
            if ($streak && $paid) {
                $paidUntil = $month;
            } elseif (! $paid) {
                $streak = false;
            }

            if ($month->lessThanOrEqualTo($last) && $this->statusOf($community, $month, $rows, $from, $today) === DuesMonthStatus::Overdue) {
                $arrears++;
            }
            if ($month->greaterThanOrEqualTo($last) && ! $streak) {
                break;
            }
        }

        return [$arrears, $paidUntil];
    }

    /** Jatuh tempo [month]: tanggal mulai iuran, atau hari terakhir bulan yang lebih pendek. */
    private function dueDate(Community $community, CarbonImmutable $month): CarbonImmutable
    {
        $day = $community->dues_start_date === null ? 1 : $community->dues_start_date->day;

        return $month->setDay(min($day, $month->daysInMonth));
    }

    private function monthOf(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date)->startOfMonth();
    }

    private function markReviewed(User $actor, DuesPayment $payment, DuesPaymentStatus $status, ?string $note): void
    {
        $updated = DuesPayment::query()
            ->whereKey($payment->getKey())
            ->where('status', DuesPaymentStatus::Pending)
            ->update([
                'status' => $status,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

        abort_if($updated === 0, 409, 'Pembayaran ini sudah diverifikasi. Muat ulang untuk melihat yang terbaru.');
    }

    /** @param  array<string, mixed>  $properties */
    private function log(User $actor, Community $community, string $event, array $properties): void
    {
        activity('community-dues')
            ->causedBy($actor)
            ->performedOn($community)
            ->event($event)
            ->withProperties($properties)
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
