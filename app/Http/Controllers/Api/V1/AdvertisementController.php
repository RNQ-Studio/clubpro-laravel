<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AdvertisementRequest;
use App\Models\Community;
use App\Models\MemberAdvertisement;
use App\Models\User;
use App\Services\MemberAdvertisementService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdvertisementController extends Controller
{
    public function __construct(private readonly MemberAdvertisementService $ads) {}

    public function catalogue(Request $request): JsonResponse
    {
        return ApiResponse::success($this->ads->catalogue($this->filters($request)));
    }

    public function own(Request $request): JsonResponse
    {
        return ApiResponse::success($this->ads->catalogue($this->filters($request), $this->actor($request)));
    }

    public function show(Request $request, MemberAdvertisement $advertisement): JsonResponse
    {
        $this->ads->assertOwner($this->actor($request), $advertisement);

        return ApiResponse::success($this->ads->data($advertisement));
    }

    public function store(AdvertisementRequest $request): JsonResponse
    {
        return ApiResponse::success($this->ads->data($this->ads->save($this->actor($request), $this->input($request))), 'Iklan disimpan.', 201);
    }

    public function update(AdvertisementRequest $request, MemberAdvertisement $advertisement): JsonResponse
    {
        return ApiResponse::success($this->ads->data($this->ads->save($this->actor($request), $this->input($request), $advertisement)), 'Iklan diperbarui.');
    }

    public function destroy(Request $request, MemberAdvertisement $advertisement): JsonResponse
    {
        $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($request, $advertisement): void {
            $ad = MemberAdvertisement::query()->lockForUpdate()->findOrFail($advertisement->id);
            $this->ads->assertOwner($this->actor($request), $ad);
            abort_unless($ad->version === $request->integer('expected_version'), 409, 'Iklan berubah. Muat ulang.');
            $ad->update(['is_active' => false, 'version' => $ad->version + 1]);
            activity('advertisement')->causedBy($this->actor($request))->performedOn(Community::query()->findOrFail($ad->community_id))->withProperties(['advertisement_id' => $ad->id])->event('withdrawn')->log('Iklan ditarik');
        });

        return ApiResponse::success(null, 'Iklan ditarik dari publik.');
    }

    /** @return array<string,mixed> */
    private function filters(Request $request): array
    {
        return $request->validate(['community_id' => ['nullable', 'integer', 'min:1'], 'seller_identity_id' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:100'], 'page' => ['integer', 'min:1'], 'per_page' => ['integer', 'min:1', 'max:100']]);
    }

    /** @return array<string,mixed> */
    private function input(AdvertisementRequest $request): array
    {
        $data = $request->validated();
        $data['community_id'] = $request->integer('community_id');
        $data['is_active'] = $request->boolean('is_active');
        if ($request->has('expected_version')) {
            $data['expected_version'] = $request->integer('expected_version');
        }

        return $data;
    }

    private function actor(Request $request): User
    {/** @var User $actor */ $actor = $request->user();

        return $actor;
    }
}
