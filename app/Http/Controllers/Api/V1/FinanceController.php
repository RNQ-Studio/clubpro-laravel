<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreIncomingReceiptRequest;
use App\Http\Requests\Api\V1\VerifyIncomingReceiptRequest;
use App\Http\Resources\Api\V1\IncomingReceiptResource;
use App\Models\Community;
use App\Models\IncomingReceipt;
use App\Models\LedgerAccount;
use App\Models\LedgerJournal;
use App\Models\MemberIdentity;
use App\Models\User;
use App\Services\IncomingReceiptService;
use App\Services\LedgerService;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class FinanceController extends Controller
{
    public function __construct(private readonly LedgerService $ledger, private readonly IncomingReceiptService $receipts) {}

    public function options(): JsonResponse
    {
        $engine = DB::getDriverName();
        $transitionApplied = $engine === 'pgsql' && Schema::hasTable('migrations') && DB::table('migrations')->where('migration', '2026_11_26_040002_transition_legacy_billing_periods')->exists();

        return ApiResponse::success(['database_engine' => $engine, 'ready' => $engine === 'pgsql' && Schema::hasColumns('ledger_accounts', ['balance', 'member_identity_id']) && Schema::hasColumns('incoming_receipts', ['collector_snapshot', 'target_member_id']) && Schema::hasColumns('billing_subjects', ['due_day']) && Schema::hasColumns('billing_notices', ['read_at']) && $transitionApplied, 'training_version' => 1, 'training_ready' => $engine === 'pgsql' && Schema::hasColumns('training_catalogs', ['packages', 'version']) && Schema::hasColumns('training_locations', ['manager_member_ids', 'splits', 'version']) && Schema::hasColumns('training_sessions', ['starts_at', 'ends_at']) && Schema::hasColumns('training_purchases', ['reservation_id', 'remaining_amount', 'snapshot', 'purchase_order']) && Schema::hasColumns('training_attendances', ['correction_journal_id', 'confirmation_key', 'corrected_by_name']) && Schema::hasTable('training_final_events') && DB::table('migrations')->where('migration', '2026_11_26_050001_create_training_module')->exists(), 'billing_version' => 2, 'period_transition_version' => $transitionApplied ? 2 : 0, 'ledger_version' => 1, 'currency' => 'IDR', 'integer_amounts' => true, 'max_transaction' => (int) config('finance.max_transaction'), 'idempotency' => 'actor_operation_payload', 'payment_gateway' => false, 'payout_version' => 1, 'payout_mode' => 'manual', 'payout_ready' => $engine === 'pgsql' && Schema::hasColumns('payouts', ['bank_snapshot', 'verified_amount', 'version']) && Schema::hasColumns('payout_parts', ['custodian_key', 'allocations', 'reservation_id']) && Schema::hasColumns('payout_fragments', ['proof_digest', 'reference_digest', 'verified_at']) && Schema::hasTable('primary_bank_accounts') && Schema::hasTable('member_transfers') && Schema::hasTable('payout_events') && DB::table('migrations')->where('migration', '2026_11_26_060001_create_money_movements')->exists()]);
    }

    public function wallet(Request $request): JsonResponse
    {
        if ($request->query('scope', 'identity') !== 'identity') {
            $ids = $this->ownAccounts($request);
            $balance = (int) LedgerAccount::query()->whereKey($ids)->sum('balance');

            return ApiResponse::success(['available' => $balance, 'reserved' => 0, 'held' => 0, 'total' => $balance, 'currency' => 'IDR']);
        }

        return ApiResponse::success($this->ledger->wallet($this->user($request)));
    }

    public function communityWallet(Request $request, Community $community): JsonResponse
    {
        $this->receipts->assertReviewer($this->user($request), $community, 'topup');
        $account = $this->ledger->communityAccount($community);

        return ApiResponse::success(['available' => $account->balance, 'total' => $account->balance, 'currency' => 'IDR']);
    }

    public function transactions(Request $request): JsonResponse
    {
        $request->validate([
            'scope' => ['sometimes', Rule::in(['identity', 'community', 'platform'])], 'direction' => ['nullable', Rule::in(['in', 'out'])], 'type' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'community_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $accountIds = $this->ownAccounts($request);
        $query = LedgerJournal::query()->whereIn('id', DB::table('ledger_entries')->select('journal_id')->whereIn('account_id', $accountIds))
            ->when($request->filled('direction'), fn ($q) => $q->whereIn('id', DB::table('ledger_entries')->select('journal_id')->whereIn('account_id', $accountIds)->where('amount', $request->query('direction') === 'in' ? '>' : '<', 0)))
            ->when($request->filled('type'), fn ($q) => $q->where('operation', $request->query('type')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', CarbonImmutable::parse((string) $request->query('from'), 'Asia/Jakarta')->utc()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<', CarbonImmutable::parse((string) $request->query('to'), 'Asia/Jakarta')->addDay()->utc()))
            ->when($request->filled('community_id'), fn ($q) => $q->where('community_id', $request->integer('community_id')))
            ->latest('created_at')->orderByDesc('id')->paginate(20);

        return ApiResponse::success(['items' => collect($query->items())->map(fn (LedgerJournal $journal): array => $this->journalData($journal, $accountIds))->all(), 'current_page' => $query->currentPage(), 'last_page' => $query->lastPage(), 'total' => $query->total()]);
    }

    public function transaction(Request $request, LedgerJournal $ledgerJournal): JsonResponse
    {
        $ids = $this->ownAccounts($request);
        abort_unless(DB::table('ledger_entries')->where('journal_id', $ledgerJournal->id)->whereIn('account_id', $ids)->exists(), 404);

        return ApiResponse::success($this->journalData($ledgerJournal, $ids, true));
    }

    public function index(Request $request, Community $community): JsonResponse
    {
        $user = $this->user($request);
        $manage = $this->receipts->canReview($user, $community);
        abort_unless($manage || $community->membershipOf($user) !== null || $community->user_id === $user->id, 403);
        $request->validate(['status' => ['nullable', Rule::in(['pending', 'verified', 'rejected'])]]);
        $query = IncomingReceipt::query()->where('community_id', $community->id)
            ->where(function ($query) use ($manage, $user): void {
                $query->where('user_id', $user->id);
                if ($manage) {
                    $query->orWhere('purpose', '<>', 'platform');
                }
                if ($user->hasRole('super-admin')) {
                    $query->orWhere('purpose', 'platform');
                }
            })
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', $this->receiptStatuses((string) $request->query('status'))))
            ->latest('created_at')->paginate(20);

        return ApiResponse::success([
            'can_manage' => $manage, 'instructions' => $this->receipts->collectorSnapshot($community),
            'items' => IncomingReceiptResource::collection($query->items())->resolve($request), 'current_page' => $query->currentPage(), 'last_page' => $query->lastPage(), 'total' => $query->total(),
        ]);
    }

    public function ownReceipts(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(['pending', 'verified', 'rejected'])]]);
        $query = IncomingReceipt::query()->where('user_id', $this->user($request)->id)->when($request->filled('status'), fn ($q) => $q->whereIn('status', $this->receiptStatuses((string) $request->query('status'))))->latest('created_at')->paginate(20);

        return ApiResponse::success(['items' => IncomingReceiptResource::collection($query->items())->resolve($request), 'current_page' => $query->currentPage(), 'last_page' => $query->lastPage(), 'total' => $query->total()]);
    }

    public function show(Request $request, Community $community, IncomingReceipt $incomingReceipt): JsonResponse
    {
        $this->scopeReceipt($request, $community, $incomingReceipt);

        return ApiResponse::success(new IncomingReceiptResource($incomingReceipt));
    }

    public function store(StoreIncomingReceiptRequest $request, Community $community): JsonResponse
    {
        $data = $request->validated();
        $data['claimed_amount'] = $request->integer('claimed_amount');
        $data['purpose'] = str_contains($request->path(), '/topups') ? 'topup' : $request->input('purpose', 'topup');

        return ApiResponse::success(new IncomingReceiptResource($this->receipts->submit($this->user($request), $community, $data)), 'Pengajuan menunggu verifikasi; saldo belum bertambah.', 201);
    }

    public function verify(VerifyIncomingReceiptRequest $request, Community $community, IncomingReceipt $incomingReceipt): JsonResponse
    {
        abort_unless($incomingReceipt->community_id === $community->id, 404);

        return ApiResponse::success(new IncomingReceiptResource($this->receipts->verify($this->user($request), $incomingReceipt, $request->integer('verified_amount'), (string) $request->string('bank_reference'), (string) $request->string('idempotency_key'), null, $request->input('note'))), 'Uang diterima dan dicatat tepat sekali.');
    }

    public function reject(Request $request, Community $community, IncomingReceipt $incomingReceipt): JsonResponse
    {
        abort_unless($incomingReceipt->community_id === $community->id, 404);
        $request->validate(['note' => ['required', 'string', 'max:500']]);

        return ApiResponse::success(new IncomingReceiptResource($this->receipts->reject($this->user($request), $incomingReceipt, (string) $request->string('note'))), 'Pengajuan ditolak.');
    }

    private function scopeReceipt(Request $request, Community $community, IncomingReceipt $receipt): void
    {
        abort_unless($receipt->community_id === $community->id, 404);
        $user = $this->user($request);
        abort_unless($receipt->user_id === $user->id || $this->receipts->canReview($user, $community, $receipt->purpose), 403);
    }

    /** @return list<int> */
    private function ownAccounts(Request $request): array
    {
        $scope = (string) $request->query('scope', 'identity');
        if ($scope === 'community') {
            $request->validate(['community_id' => ['required', 'integer', 'min:1']]);
            $community = Community::query()->findOrFail($request->integer('community_id'));
            $this->receipts->assertReviewer($this->user($request), $community, 'topup');

            return [$this->ledger->communityAccount($community)->id];
        }
        if ($scope === 'platform') {
            abort_unless($this->user($request)->hasRole('super-admin'), 403);

            return [$this->ledger->platformAccount()->id];
        }
        abort_unless($scope === 'identity', 422, 'Cakupan saldo tidak valid.');

        return LedgerAccount::query()->where('member_identity_id', MemberIdentity::forUser($this->user($request))->id)->pluck('id')->all();
    }

    /** @param list<int> $ownIds @return array<string,mixed> */
    private function journalData(LedgerJournal $journal, array $ownIds, bool $detail = false): array
    {
        $actor = User::query()->find($journal->actor_id);
        $entries = DB::table('ledger_entries')->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
            ->where('journal_id', $journal->id)->whereIn('account_id', $ownIds)->get(['account_id', 'amount', 'bucket']);
        // Do not expose the other payer's bank reference, proof, IDs, or allocations.
        $metadata = array_intersect_key($journal->metadata, array_flip(['purpose', 'reason', 'reservation_id']));
        $data = ['id' => $journal->id, 'operation' => $journal->operation, 'amount' => (int) $entries->sum('amount'), 'community_id' => $journal->community_id,
            'reference' => $journal->reference, 'actor' => $actor === null ? null : ['id' => $actor->id, 'name' => $actor->name], 'created_at' => $journal->created_at->toIso8601String(), 'metadata' => $metadata];
        if ($detail) {
            $data['postings'] = $entries->map(fn ($entry): array => ['bucket' => $entry->bucket, 'amount' => (int) $entry->amount])->all();
        }

        return $data;
    }

    /** @return list<string> */
    private function receiptStatuses(string $status): array
    {
        return match ($status) {
            'pending' => ['pending', 'legacy_pending'],
            'verified' => ['verified', 'legacy_verified'],
            'rejected' => ['rejected', 'legacy_rejected'],
            default => [$status],
        };
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
