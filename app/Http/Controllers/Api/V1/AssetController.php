<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UploadAssetRequest;
use App\Http\Resources\Api\V1\AssetResource;
use App\Models\Asset;
use App\Services\AssetUploadService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssetController extends Controller
{
    public function __construct(
        private readonly AssetUploadService $uploadService,
    ) {}

    public function upload(UploadAssetRequest $request): JsonResponse
    {
        /** @var UploadedFile $file */
        $file = $request->file('file');

        $asset = $this->uploadService->upload(
            file: $file,
            type: $request->string('type')->toString(),
            userId: $request->user()?->getKey(),
            retainUntil: $request->date('retain_until'),
            isProtected: $request->boolean('is_protected'),
        );

        return ApiResponse::success(new AssetResource($asset), 'Asset uploaded', 201);
    }

    /**
     * Gambar yang tersimpan di server. Nama berkasnya UUID, seperti URL GCS publik.
     *
     * @unauthenticated
     */
    public function file(Asset $asset): StreamedResponse
    {
        abort_unless($asset->isServableImage(), 404);

        return Storage::disk($asset->storage_type->disk())->response(
            $asset->path,
            headers: ['Cache-Control' => 'public, max-age=31536000, immutable'],
        );
    }
}
