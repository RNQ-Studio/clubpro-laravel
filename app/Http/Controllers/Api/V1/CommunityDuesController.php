<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RejectDuesPaymentRequest;
use App\Http\Requests\Api\V1\SetDuesStatusRequest;
use App\Http\Requests\Api\V1\StoreDuesPaymentRequest;
use App\Http\Requests\Api\V1\UpdateDuesSettingsRequest;
use App\Http\Resources\Api\V1\DuesPaymentResource;
use App\Http\Resources\Api\V1\MemberDuesResource;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\DuesPayment;
use App\Models\User;
use App\Services\DuesService;
use App\Support\ApiResponse;
use App\Support\Enums\CommunityMemberStatus;
use App\Support\Enums\DuesPaymentStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Iuran komunitas: anggota melihat dan membayar iurannya; creator/admin mengatur iuran,
 * memverifikasi bukti transfer, dan mengubah status per bulan di kalender anggota.
 */
class CommunityDuesController extends Controller
{
    /** Riwayat pembayaran sendiri yang ikut dikirim. */
    private const MY_PAYMENTS = 10;

    public function __construct(private readonly DuesService $dues) {}

    /**
     * Setelan iuran, ringkasan dan kalender [year] pengguna yang login, serta riwayat
     * pembayarannya.
     */
    public function show(Request $request, Community $community): JsonResponse
    {
        $this->authorize('dues', $community);

        /** @var User $user */
        $user = $request->user();
        $year = $this->year($request);
        $member = $this->activeMember($community, $user);
        $canManage = $user->can('manage', $community);

        $payments = $community->duesPayments()
            ->where('user_id', $user->getKey())
            ->with(['user', 'proof', 'reviewer'])
            ->latest('id')
            ->limit(self::MY_PAYMENTS)
            ->get();

        $data = [
            'settings' => $this->settings($community),
            'can_manage' => $canManage,
            'year' => $year,
            'me' => $member === null ? null : [
                ...$this->dues->memberCalendar($community, (int) $user->getKey(), $member, $year),
                'entry_fee_due' => $this->dues->entryFeeDue($community, $member),
                'next_unpaid_months' => $this->dues->nextUnpaidMonths($community, (int) $user->getKey(), $member),
            ],
            'payments' => DuesPaymentResource::collection($payments),
        ];
        if ($canManage) {
            $data['pending_payments_count'] = $community->duesPayments()->where('status', DuesPaymentStatus::Pending)->count();
        }

        return ApiResponse::success($data);
    }

    public function updateSettings(UpdateDuesSettingsRequest $request, Community $community): JsonResponse
    {
        $this->authorize('manage', $community);

        /** @var array{dues_amount: int, entry_fee: int, dues_start_date: string, dues_bank_name: string, dues_account_number: string, dues_account_name: string} $data */
        $data = $request->validated();

        return ApiResponse::success(
            $this->settings($this->dues->updateSettings($community, $data)),
            'Pengaturan iuran disimpan',
        );
    }

    /** Bayar iuran: bukti transfer menunggu verifikasi creator/admin. */
    public function storePayment(StoreDuesPaymentRequest $request, Community $community): JsonResponse
    {
        $this->authorize('dues', $community);

        /** @var User $user */
        $user = $request->user();

        $payment = $this->dues->submitPayment(
            $user,
            $community,
            $request->integer('months_count'),
            (string) $request->string('proof_asset_id'),
            $request->filled('note') ? (string) $request->string('note') : null,
        );

        return ApiResponse::success(
            new DuesPaymentResource($payment->load(['user', 'proof'])),
            'Bukti transfer terkirim dan menunggu verifikasi',
            201,
        );
    }

    /** Pembayaran untuk diverifikasi: yang menunggu terlama dulu, riwayat terbaru dulu. */
    public function payments(Request $request, Community $community): JsonResponse
    {
        $this->authorize('manage', $community);

        $request->validate(['status' => ['sometimes', 'nullable', Rule::enum(DuesPaymentStatus::class)]]);
        $status = DuesPaymentStatus::tryFrom((string) $request->query('status', ''));

        $payments = $community->duesPayments()
            ->with(['user', 'proof', 'reviewer'])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when(
                $status === DuesPaymentStatus::Pending,
                fn ($query) => $query->oldest('id'),
                fn ($query) => $query->latest('id'),
            )
            ->paginate(20);

        return ApiResponse::success(DuesPaymentResource::collection($payments));
    }

    public function approve(Request $request, Community $community, DuesPayment $duesPayment): JsonResponse
    {
        $this->authorize('manage', $community);

        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success(
            new DuesPaymentResource($this->dues->approve($user, $duesPayment)->load(['user', 'proof', 'reviewer'])),
            'Pembayaran diterima',
        );
    }

    public function reject(RejectDuesPaymentRequest $request, Community $community, DuesPayment $duesPayment): JsonResponse
    {
        $this->authorize('manage', $community);

        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success(
            new DuesPaymentResource($this->dues->rejectPayment($user, $duesPayment, (string) $request->string('note'))->load(['user', 'proof', 'reviewer'])),
            'Pembayaran ditolak',
        );
    }

    /** Kalender iuran semua anggota aktif untuk [year]. */
    public function members(Request $request, Community $community): JsonResponse
    {
        $this->authorize('manage', $community);

        $request->validate(['search' => ['sometimes', 'nullable', 'string', 'max:100']]);

        return ApiResponse::success(MemberDuesResource::collection(
            $this->dues->membersOverview($community, $this->year($request), trim((string) $request->query('search', '')), 30),
        ));
    }

    /** Kalender satu anggota beserta riwayat pembayarannya. */
    public function member(Request $request, Community $community, User $user): JsonResponse
    {
        $this->authorize('manage', $community);

        $member = $this->activeMember($community, $user);
        abort_if($member === null, 404, 'Anggota ini tidak aktif di komunitas ini.');

        $year = $this->year($request);
        $member->setRelation('user', $user);
        $member->setAttribute('dues', [
            ...$this->dues->memberCalendar($community, (int) $user->getKey(), $member, $year),
            'entry_fee_due' => $this->dues->entryFeeDue($community, $member),
        ]);

        $payments = $community->duesPayments()
            ->where('user_id', $user->getKey())
            ->with(['user', 'proof', 'reviewer'])
            ->latest('id')
            ->limit(20)
            ->get();

        return ApiResponse::success([
            'settings' => $this->settings($community),
            'year' => $year,
            'member' => new MemberDuesResource($member),
            'payments' => DuesPaymentResource::collection($payments),
        ]);
    }

    public function setMonth(SetDuesStatusRequest $request, Community $community, User $user, string $month): JsonResponse
    {
        $this->authorize('manage', $community);
        abort_if($this->activeMember($community, $user) === null, 404, 'Anggota ini tidak aktif di komunitas ini.');

        /** @var User $actor */
        $actor = $request->user();
        $this->dues->setMonth($actor, $community, $user, $month, $request->boolean('paid'));

        return ApiResponse::success(null, $request->boolean('paid') ? 'Ditandai lunas' : 'Ditandai belum lunas');
    }

    public function setEntryFee(SetDuesStatusRequest $request, Community $community, User $user): JsonResponse
    {
        $this->authorize('manage', $community);
        $member = $this->activeMember($community, $user);
        abort_if($member === null, 404, 'Anggota ini tidak aktif di komunitas ini.');

        /** @var User $actor */
        $actor = $request->user();
        $this->dues->setEntryFee($actor, $community, $member, $request->boolean('paid'));

        return ApiResponse::success(null, $request->boolean('paid') ? 'Uang masuk ditandai lunas' : 'Uang masuk ditandai belum lunas');
    }

    /** @return array<string, mixed> */
    private function settings(Community $community): array
    {
        return [
            'configured' => $this->dues->isConfigured($community),
            'monthly_amount' => $community->dues_amount,
            'entry_fee' => $community->entry_fee,
            'start_date' => $community->dues_start_date?->toDateString(),
            'due_day' => $community->dues_start_date?->day,
            'bank_name' => $community->dues_bank_name,
            'account_number' => $community->dues_account_number,
            'account_name' => $community->dues_account_name,
        ];
    }

    private function activeMember(Community $community, User $user): ?CommunityMember
    {
        $member = $community->membershipOf($user);

        return $member?->status === CommunityMemberStatus::Active ? $member : null;
    }

    private function year(Request $request): int
    {
        $request->validate(['year' => ['sometimes', 'integer', 'min:2000', 'max:2099']]);

        return $request->integer('year', $this->dues->today()->year);
    }
}
