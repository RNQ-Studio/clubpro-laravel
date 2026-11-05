<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ListUsersRequest;
use App\Http\Requests\Api\V1\Admin\UpdateSuperAdminRequest;
use App\Http\Requests\Api\V1\Admin\UpdateUserStatusRequest;
use App\Http\Resources\Api\V1\Admin\AdminUserDetailResource;
use App\Http\Resources\Api\V1\Admin\AdminUserResource;
use App\Models\User;
use App\Services\Admin\UserAdminService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function __construct(private readonly UserAdminService $users) {}

    /**
     * Daftar pengguna terdaftar, paling baru mengakses di atas, beserta ringkasannya.
     */
    public function index(ListUsersRequest $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        return ApiResponse::success(
            AdminUserResource::collection($this->users->paginate($request->validated(), $perPage)),
            meta: ['summary' => $this->users->summary()],
        );
    }

    public function show(User $user): JsonResponse
    {
        return ApiResponse::success(new AdminUserDetailResource($this->users->detail($user)));
    }

    /**
     * Beri atau cabut akses super admin.
     */
    public function updateSuperAdmin(UpdateSuperAdminRequest $request, User $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return ApiResponse::success(
            new AdminUserDetailResource($this->users->setSuperAdmin($actor, $user, $request->boolean('enabled'))),
            'Akses super admin diperbarui',
        );
    }

    /**
     * Aktifkan atau nonaktifkan akun. Akun nonaktif langsung keluar dari semua perangkat.
     */
    public function updateStatus(UpdateUserStatusRequest $request, User $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return ApiResponse::success(
            new AdminUserDetailResource($this->users->setActive($actor, $user, $request->boolean('is_active'))),
            'Status akun diperbarui',
        );
    }
}
