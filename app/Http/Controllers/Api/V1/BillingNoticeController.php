<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Recipient-bound in-app reminders, including admin reviews for other subjects. */
class BillingNoticeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()?->getAuthIdentifier();
        $rows = DB::table('billing_notices')
            ->join('billing_subjects', 'billing_subjects.id', '=', 'billing_notices.subject_id')
            ->join('communities', 'communities.id', '=', 'billing_subjects.community_id')
            ->where('billing_notices.user_id', $userId)
            ->orderByRaw('billing_notices.read_at IS NOT NULL')
            ->orderByDesc('billing_notices.id')
            ->limit(100)
            ->get([
                'billing_notices.id', 'billing_notices.type', 'billing_notices.message',
                'billing_notices.created_at', 'billing_notices.read_at',
                'billing_subjects.community_id', 'billing_subjects.kind as subject_kind',
                'billing_subjects.member_id', 'communities.name as community_name',
            ])->map(function (object $row): array {
                $data = (array) $row;
                $data['created_at'] = CarbonImmutable::parse((string) $data['created_at'], 'UTC')->toIso8601String();
                $data['read_at'] = $data['read_at'] === null ? null : CarbonImmutable::parse((string) $data['read_at'], 'UTC')->toIso8601String();

                return $data;
            });

        return ApiResponse::success([
            'items' => $rows,
            'unread_count' => DB::table('billing_notices')->where('user_id', $userId)->whereNull('read_at')->count(),
        ]);
    }

    public function read(Request $request, int $notice): JsonResponse
    {
        $query = DB::table('billing_notices')->where('id', $notice)->where('user_id', $request->user()?->getAuthIdentifier());
        abort_unless($query->exists(), 404);
        $query->whereNull('read_at')->update(['read_at' => now()]);

        return ApiResponse::success(null, 'Pengingat ditandai dibaca.');
    }
}
