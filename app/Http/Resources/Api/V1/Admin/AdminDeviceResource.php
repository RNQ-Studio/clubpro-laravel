<?php

namespace App\Http\Resources\Api\V1\Admin;

use App\Models\UserDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin UserDevice
 */
class AdminDeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform->value,
            'device_type' => $this->device_type,
            'device_name' => $this->device_name,
            'os_version' => $this->os_version,
            'app_version' => $this->app_version,
            'ip_address' => $this->ip_address,
            'location' => $this->location,
            'user_agent' => $this->user_agent,
            'last_active_at' => $this->last_active_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
