<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MoneyMovementRequest;
use App\Http\Resources\Api\V1\PayoutResource;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\MemberIdentity;
use App\Models\MemberTransfer;
use App\Models\Payout;
use App\Models\PayoutFragment;
use App\Models\PayoutPart;
use App\Models\User;
use App\Policies\MemberTransferPolicy;
use App\Services\MoneyMovementService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MoneyMovementController extends Controller
{
    public function __construct(private readonly MoneyMovementService $money) {}

    public function primary(Request $request): JsonResponse
    {
        return ApiResponse::success($this->money->primaryData($this->user($request)));
    }

    public function savePrimary(MoneyMovementRequest $request): JsonResponse
    {
        $this->money->savePrimary($this->user($request), $this->input($request));

        return $this->primary($request);
    }

    public function recipients(Request $request): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'min:2', 'max:100'], 'community_id' => ['required', 'integer', 'min:1']]);
        $actor = $this->user($request);
        $community = Community::query()->findOrFail($request->integer('community_id'));
        $this->money->requireMember($actor, $community, true);
        $identity = MemberIdentity::forUser($actor);
        $items = CommunityMember::query()->with(['identity.user', 'community'])->where('status', 'active')->whereNull('merged_into_member_id')->where('member_identity_id', '<>', $identity->id)
            ->whereHas('community', fn ($q) => $q->where('status', 'approved'))
            ->where(fn ($q) => $q->where('community_id', $community->id)->orWhereHas('identity', fn ($q) => $q->where('is_public', true)))
            ->whereHas('identity', fn ($q) => $q->whereNull('merged_into_id')->where('name', 'ilike', '%'.addcslashes((string) $request->query('q'), '%_\\').'%')->whereHas('user', fn ($q) => $q->where('is_active', true)))
            ->orderBy('id')->get()->filter(fn (CommunityMember $member): bool => $this->money->activeMember($member))
            ->map(fn (CommunityMember $member): array => ['identity_id' => $member->member_identity_id, 'member_id' => $member->id, 'name' => $member->identity?->name, 'community_id' => $member->community_id, 'community_name' => $member->community->name])->values();
        $page = max(1, $request->integer('page', 1));
        $total = $items->count();

        return ApiResponse::success(['items' => $items->slice(($page - 1) * 20, 20)->values()->all(), 'current_page' => $page, 'last_page' => max(1, (int) ceil($total / 20)), 'total' => $total]);
    }

    public function transfer(MoneyMovementRequest $request): JsonResponse
    {
        return ApiResponse::success($this->transferData($this->money->transfer($this->user($request), $this->input($request)), $this->user($request)), 'Transfer saldo berhasil dicatat.', 201);
    }

    public function transfers(Request $request): JsonResponse
    {
        $identity = MemberIdentity::forUser($this->user($request));
        $query = MemberTransfer::query()->where(fn ($q) => $q->where('sender_identity_id', $identity->id)->orWhere('recipient_identity_id', $identity->id))->latest()->orderByDesc('id')->paginate(20);

        return ApiResponse::success(['items' => collect($query->items())->map(fn (MemberTransfer $transfer): array => $this->transferData($transfer, $this->user($request)))->all(), 'current_page' => $query->currentPage(), 'last_page' => $query->lastPage(), 'total' => $query->total()]);
    }

    public function transferDetail(Request $request, MemberTransfer $memberTransfer): JsonResponse
    {
        abort_unless(app(MemberTransferPolicy::class)->view($this->user($request), $memberTransfer), 404);

        return ApiResponse::success($this->transferData($memberTransfer, $this->user($request)));
    }

    public function createPayout(MoneyMovementRequest $request): JsonResponse
    {
        return ApiResponse::success(new PayoutResource($this->money->createPayout($this->user($request), $this->input($request))), 'Permintaan dibuat; saldo ditahan, belum ada transfer bank.', 201);
    }

    public function payouts(Request $request): JsonResponse
    {
        $request->validate(['scope' => ['sometimes', Rule::in(['mine', 'manage'])], 'community_id' => ['nullable', 'integer', 'min:1'], 'q' => ['nullable', 'string', 'max:100'], 'handler' => ['sometimes', Rule::in(['community', 'platform'])], 'status' => ['nullable', Rule::in(['requested', 'processing', 'uncertain', 'succeeded', 'failed', 'cancelled'])]]);
        $actor = $this->user($request);
        $manage = $request->query('scope', 'mine') === 'manage';
        $query = Payout::query();
        $platform = $manage && $request->query('handler') === 'platform';
        if ($manage) {
            if ($platform) {
                abort_unless($actor->hasRole('super-admin'), 403);
                $partScope = PayoutPart::query()->whereNull('community_id');
            } else {
                $request->validate(['community_id' => ['required', 'integer', 'min:1']]);
                $probe = new PayoutPart(['community_id' => $request->integer('community_id')]);
                $this->money->assertManage($actor, $probe);
                $partScope = PayoutPart::query()->where('community_id', $request->integer('community_id'));
            }
            $partScope->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')));
            $query->whereIn('id', $partScope->select('payout_id'));
        } else {
            $query->where('user_id', $actor->id)->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')));
        }
        $query->when($request->filled('q'), function ($q) use ($request): void {
            $term = '%'.addcslashes((string) $request->query('q'), '%_\\').'%';
            $q->where(fn ($q) => $q->where('member_name', 'ilike', $term)->orWhereRaw('CAST(id AS TEXT) ILIKE ?', [$term]));
        });
        $result = $query->latest()->orderByDesc('id')->paginate(20);

        return ApiResponse::success(['items' => collect($result->items())->map(fn (Payout $payout): array => (new PayoutResource($payout, false, $manage ? ($platform ? -1 : $request->integer('community_id')) : null))->resolve($request))->all(), 'can_manage' => $manage, 'current_page' => $result->currentPage(), 'last_page' => $result->lastPage(), 'total' => $result->total()]);
    }

    public function payout(Request $request, Payout $payout): JsonResponse
    {
        return ApiResponse::success(new PayoutResource($payout));
    }

    public function process(MoneyMovementRequest $request, PayoutPart $part): JsonResponse
    {
        return $this->change($request, $part, 'process');
    }

    public function uncertain(MoneyMovementRequest $request, PayoutPart $part): JsonResponse
    {
        return $this->change($request, $part, 'uncertain');
    }

    public function fail(MoneyMovementRequest $request, PayoutPart $part): JsonResponse
    {
        return $this->change($request, $part, 'fail');
    }

    public function cancel(MoneyMovementRequest $request, PayoutPart $part): JsonResponse
    {
        return $this->change($request, $part, 'cancel');
    }

    public function submitProof(MoneyMovementRequest $request, PayoutPart $part): JsonResponse
    {
        return ApiResponse::success(new PayoutResource($this->money->submitProof($this->user($request), $part, $this->input($request))), 'Bukti dicatat; menunggu verifikasi nominal.');
    }

    public function correctFragment(MoneyMovementRequest $request, PayoutPart $part, PayoutFragment $fragment): JsonResponse
    {
        return ApiResponse::success(new PayoutResource($this->money->correctFragment($this->user($request), $part, $fragment, $this->input($request))), 'Input bukti diperbaiki dan diaudit.');
    }

    public function verifyFragment(MoneyMovementRequest $request, PayoutPart $part, PayoutFragment $fragment): JsonResponse
    {
        return ApiResponse::success(new PayoutResource($this->money->verifyFragment($this->user($request), $part, $fragment, $this->input($request))), 'Nominal transfer diverifikasi dan dicatat tepat sekali.');
    }

    private function change(MoneyMovementRequest $request, PayoutPart $part, string $action): JsonResponse
    {
        return ApiResponse::success(new PayoutResource($this->money->transition($this->user($request), $part, $action, $this->input($request))));
    }

    public function reconciliation(Request $request, Community $community): JsonResponse
    {
        $this->money->assertManage($this->user($request), new PayoutPart(['community_id' => $community->id]));

        return ApiResponse::success($this->money->reconciliation($community->id));
    }

    public function platformReconciliation(Request $request): JsonResponse
    {
        abort_unless($this->user($request)->hasRole('super-admin'), 403);

        return ApiResponse::success($this->money->reconciliation(null));
    }

    /** @return array<string,mixed> */
    private function transferData(MemberTransfer $transfer, User $viewer): array
    {
        return ['id' => $transfer->id, 'reference' => $transfer->id, 'amount' => $transfer->amount, 'status' => 'succeeded', 'community_id' => $transfer->community_id, 'recipient_community_id' => $transfer->recipient_community_id, ...$transfer->snapshot, 'created_at' => $transfer->created_at->toIso8601String(), 'journal_id' => $transfer->journal_id, 'direction' => $transfer->sender_identity_id === MemberIdentity::forUser($viewer)->id ? 'out' : 'in'];
    }

    /** @return array<string,mixed> */
    private function input(MoneyMovementRequest $request): array
    {
        $data = $request->validated();
        foreach (['amount', 'community_id', 'recipient_identity_id', 'recipient_community_id', 'bank_account_version', 'expected_version'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = (int) $data[$field];
            }
        }
        foreach (['acknowledged', 'confirmed_unsent'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = $request->boolean($field);
            }
        }

        return $data;
    }

    private function user(Request $request): User
    { /** @var User $user */ $user = $request->user();

        return $user;
    }
}
