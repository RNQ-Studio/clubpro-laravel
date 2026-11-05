<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListCommunitiesRequest;
use App\Http\Requests\Api\V1\StoreCommunityRequest;
use App\Http\Resources\Api\V1\CommunityDetailResource;
use App\Http\Resources\Api\V1\CommunityResource;
use App\Models\Community;
use App\Models\User;
use App\Services\CommunityService;
use App\Support\ApiResponse;
use App\Support\Enums\CommunityType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommunityController extends Controller
{
    public function __construct(private readonly CommunityService $communities) {}

    /**
     * Daftar komunitas yang sudah lolos verifikasi.
     */
    public function index(ListCommunitiesRequest $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 20), 1), 50);

        return ApiResponse::success(
            CommunityResource::collection($this->communities->paginateApproved($request->validated(), $perPage)),
        );
    }

    /**
     * Pengajuan milik pengguna yang login, apa pun statusnya.
     */
    public function mine(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $communities = Community::query()
            ->where('user_id', $user->getKey())
            ->with('logo')
            ->latest()
            ->limit(50)
            ->get();

        return ApiResponse::success(CommunityResource::collection($communities));
    }

    /**
     * Komunitas yang diikuti pengguna yang login, untuk Beranda.
     */
    public function joined(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success(CommunityResource::collection($this->communities->joined($user)));
    }

    public function show(Request $request, Community $community): JsonResponse
    {
        $this->authorize('view', $community);

        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success(new CommunityDetailResource($this->communities->detail($user, $community)));
    }

    /**
     * Ajukan komunitas baru. Statusnya menunggu sampai diverifikasi super admin.
     */
    public function store(StoreCommunityRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{name: string, short_name: string, type: string, type_other?: string|null, description: string, address: string, province_code: string, city_code: string, contact_name: string, contact_phone: string, contact_email?: string|null, logo_asset_id: string, photo_asset_ids: list<string>} $data */
        $data = $request->validated();

        return ApiResponse::success(
            new CommunityDetailResource($this->communities->detail($user, $this->communities->submit($user, $data))),
            'Komunitas diajukan dan menunggu verifikasi',
            201,
        );
    }

    /**
     * Pilihan jenis komunitas untuk form.
     */
    public function types(): JsonResponse
    {
        return ApiResponse::success(array_map(
            fn (CommunityType $type): array => ['value' => $type->value, 'label' => $type->label()],
            CommunityType::cases(),
        ));
    }
}
