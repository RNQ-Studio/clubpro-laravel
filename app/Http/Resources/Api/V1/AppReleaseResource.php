<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AppRelease;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AppRelease
 */
class AppReleaseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'app' => $this->app,
            'app_name' => $this->app_name,
            'channel' => $this->channel,
            'version_name' => $this->version_name,
            'version_code' => $this->version_code,
            'file_size' => $this->file_size,
            'file_sha256' => $this->file_sha256,
            'signing_sha256' => $this->signing_sha256,
            'git_sha' => $this->git_sha,
            'release_notes' => $this->release_notes,
            'uploaded_at' => $this->uploaded_at->toIso8601String(),
            'download_filename' => $this->download_filename,
            'download_url' => route('app-releases.download', ['app' => $this->app, 'channel' => $this->channel]),
        ];
    }
}
