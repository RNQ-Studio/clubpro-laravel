<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StartAppReleaseUploadRequest;
use App\Http\Resources\Api\V1\AppReleaseResource;
use App\Models\AppRelease;
use App\Models\User;
use App\Services\AppReleaseService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppReleaseController extends Controller
{
    public function __construct(
        private readonly AppReleaseService $releases,
    ) {}

    /**
     * Rilis APK terbaru untuk satu app+channel (dipakai skrip rilis untuk
     * menentukan nomor build berikutnya dan memverifikasi hasil upload).
     *
     * @unauthenticated
     */
    public function latest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'app' => ['required', 'string', Rule::in(array_keys((array) config('app_release.apps')))],
            'channel' => ['sometimes', 'string', Rule::in((array) config('app_release.channels'))],
        ]);

        $release = $this->releases->latest($validated['app'], $validated['channel'] ?? 'dev');

        if ($release === null) {
            return ApiResponse::error('Release not found.', 404, code: 'NOT_FOUND');
        }

        return ApiResponse::success(new AppReleaseResource($release));
    }

    /**
     * Mulai sesi upload. Kirim chunk ke `uploads/{upload}/chunks?offset=N` sebagai
     * body mentah (application/octet-stream), lalu panggil `uploads/{upload}/complete`.
     */
    public function startUpload(StartAppReleaseUploadRequest $request): JsonResponse
    {
        $session = $this->releases->startUpload($request->validated(), $this->uploader($request));

        return ApiResponse::success($session, 'Upload session started', 201);
    }

    /**
     * Status sesi upload — `received_bytes` adalah offset untuk melanjutkan.
     */
    public function showUpload(Request $request, string $upload): JsonResponse
    {
        $this->authorize('create', AppRelease::class);

        return ApiResponse::success($this->releases->uploadStatus($upload, $this->uploader($request)));
    }

    public function uploadChunk(Request $request, string $upload): JsonResponse
    {
        $this->authorize('create', AppRelease::class);

        $validated = $request->validate([
            'offset' => ['required', 'integer', 'min:0'],
        ]);

        $session = $this->releases->appendChunk(
            $upload,
            $this->uploader($request),
            (int) $validated['offset'],
            $request->getContent(),
        );

        return ApiResponse::success($session, 'Chunk received');
    }

    public function completeUpload(Request $request, string $upload): JsonResponse
    {
        $this->authorize('create', AppRelease::class);

        $release = $this->releases->completeUpload($upload, $this->uploader($request));

        return ApiResponse::success(new AppReleaseResource($release), 'Release published', 201);
    }

    public function abortUpload(Request $request, string $upload): JsonResponse
    {
        $this->authorize('create', AppRelease::class);

        $this->releases->abortUpload($upload, $this->uploader($request));

        return ApiResponse::success(null, 'Upload aborted');
    }

    private function uploader(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
