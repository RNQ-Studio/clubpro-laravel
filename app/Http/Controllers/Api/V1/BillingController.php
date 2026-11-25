<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BillingLifecycleRequest;
use App\Http\Requests\Api\V1\BillingPaymentRequest;
use App\Http\Requests\Api\V1\BillingSettingsRequest;
use App\Models\Community;
use App\Models\User;
use App\Services\BillingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __construct(private readonly BillingService $billing) {}

    public function show(Request $request, Community $community): JsonResponse
    {
        $request->validate(['scope' => ['sometimes', 'in:member,platform'], 'target_member_id' => ['sometimes', 'nullable', 'integer']]);
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success($this->billing->summary($user, $community, $request->string('scope', 'member')->toString(), $request->filled('target_member_id') ? $request->integer('target_member_id') : null));
    }

    public function pay(BillingPaymentRequest $request, Community $community): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var list<string> $ids */
        $ids = $request->input('invoice_ids', []);

        return ApiResponse::success($this->billing->pay($user, $community, $request->string('scope')->toString(), $ids, $request->string('idempotency_key')->toString(), $request->filled('target_member_id') ? $request->integer('target_member_id') : null, $request->has('expected_amount') ? $request->integer('expected_amount') : null), 'Pembayaran berhasil dicatat.');
    }

    public function renew(Request $request, Community $community): JsonResponse
    {
        $request->validate(['scope' => ['required', 'in:member,platform'], 'target_member_id' => ['sometimes', 'nullable', 'integer']]);
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success($this->billing->renew($user, $community, $request->string('scope')->toString(), $request->filled('target_member_id') ? $request->integer('target_member_id') : null), 'Tagihan perpanjangan disiapkan.');
    }

    public function settings(BillingSettingsRequest $request, Community $community): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->safe()->except('period_months');
        $data['dues_period_months'] = $request->integer('period_months');
        $this->billing->settings($user, $community, $data);

        return ApiResponse::success(null, 'Tarif disimpan dan berlaku mulai periode berikutnya.');
    }

    public function inactive(Request $request, Community $community): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success($this->billing->inactive($user, $community));
    }

    public function lifecycle(BillingLifecycleRequest $request, Community $community): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->billing->decide($user, $community, $request->string('subject')->toString(), $request->filled('member_id') ? $request->integer('member_id') : null, $request->string('action')->toString(), $request->string('reason')->toString());

        return ApiResponse::success(null, $request->input('action') === 'retain' ? 'Dipertahankan; kewajiban tetap berlaku.' : 'Diarsipkan; histori transaksi tetap disimpan.');
    }
}
