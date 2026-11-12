<?php

namespace App\Services;

use App\Models\Asset;
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
        $community->forceFill($data)->save();

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
     * @return array{billable_from: string|null, arrears_count: int, paid_until: string|null, entry_fee_paid: bool, months: list<array{month: string, status: string}>}
     */
    public function memberCalendar(Community $community, int $userId, ?CommunityMember $member, int $year, ?array $rows = null): array
    {
        $rows ??= $this->rowsOf($community, [$userId])[$userId] ?? [];
        $from = $this->billableFrom($community, $member);
        $today = $this->today();

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

        try {
            return DB::transaction(function () use ($payer, $community, $monthsCount, $proofAssetId, $note): DuesPayment {
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

                return $payment;
            });
        } catch (UniqueConstraintViolationException) {
            $this->reject('months_count', 'Bulan yang sama sudah diajukan. Muat ulang lalu coba lagi.');
        }
    }

    /** Terima pembayaran: bulannya lunas, begitu juga uang masuk yang ikut dibayar. */
    public function approve(User $actor, DuesPayment $payment): DuesPayment
    {
        DB::transaction(function () use ($actor, $payment): void {
            $this->markReviewed($actor, $payment, DuesPaymentStatus::Approved, null);

            DuesMonth::query()
                ->where('dues_payment_id', $payment->getKey())
                ->update(['status' => DuesMonthStatus::Paid, 'updated_by' => $actor->getKey(), 'updated_at' => now()]);

            if ($payment->entry_fee > 0) {
                CommunityMember::query()
                    ->where('community_id', $payment->community_id)
                    ->where('user_id', $payment->user_id)
                    ->whereNull('entry_fee_paid_at')
                    ->update(['entry_fee_paid_at' => now()]);
            }
        });

        $this->log($actor, $payment->community, 'dues_payment_approved', ['payment_id' => $payment->getKey(), 'user_id' => $payment->user_id, 'months' => $payment->months]);

        return $payment->refresh();
    }

    /** Tolak pembayaran: bulannya kembali belum lunas. */
    public function rejectPayment(User $actor, DuesPayment $payment, string $note): DuesPayment
    {
        DB::transaction(function () use ($actor, $payment, $note): void {
            $this->markReviewed($actor, $payment, DuesPaymentStatus::Rejected, $note);

            DuesMonth::query()
                ->where('dues_payment_id', $payment->getKey())
                ->where('status', DuesMonthStatus::Pending)
                ->delete();
        });

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
        $date = $month.'-01';
        $existing = $community->duesMonths()->where('user_id', $member->getKey())->whereDate('month', $date)->first();

        if ($existing?->status === DuesMonthStatus::Pending) {
            $this->reject('month', 'Bulan ini sedang menunggu verifikasi pembayaran. Terima atau tolak pembayarannya dulu.');
        }

        if ($paid && $existing === null) {
            try {
                $community->duesMonths()->create([
                    'user_id' => $member->getKey(),
                    'month' => $date,
                    'status' => DuesMonthStatus::Paid,
                    'updated_by' => $actor->getKey(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Ketuk ganda bersamaan: sudah lunas.
            }
        } elseif (! $paid && $existing !== null) {
            $existing->delete();
        } else {
            return;
        }

        $this->log($actor, $community, $paid ? 'dues_month_paid' : 'dues_month_unpaid', ['user_id' => $member->getKey(), 'month' => $month]);
    }

    public function setEntryFee(User $actor, Community $community, CommunityMember $member, bool $paid): void
    {
        if (($member->entry_fee_paid_at !== null) === $paid) {
            return;
        }

        $member->forceFill(['entry_fee_paid_at' => $paid ? now() : null])->save();

        $this->log($actor, $community, $paid ? 'dues_entry_fee_paid' : 'dues_entry_fee_unpaid', ['user_id' => $member->user_id]);
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
