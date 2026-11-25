<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UploadAssetRequest;
use App\Http\Resources\Api\V1\AssetResource;
use App\Models\Asset;
use App\Services\AssetUploadService;
use App\Support\ApiResponse;
use App\Support\Enums\AssetStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    public function file(Request $request, Asset $asset): StreamedResponse
    {
        if ($asset->isSensitive()) {
            abort_unless($request->hasValidSignature(), 403);
            abort_unless($asset->status === AssetStatus::Active, 404);
            abort_unless(in_array($asset->mime_type, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true), 404);

            return Storage::disk($asset->storage_type->disk())->response(
                $asset->path,
                headers: ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer'],
            );
        }
        abort_unless($asset->isServableImage(), 404);

        return Storage::disk($asset->storage_type->disk())->response(
            $asset->path,
            headers: ['Cache-Control' => 'public, max-age=31536000, immutable'],
        );
    }
}
