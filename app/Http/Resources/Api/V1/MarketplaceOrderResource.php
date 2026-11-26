<?php

namespace App\Http\Resources\Api\V1;

use App\Models\MarketplaceOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MarketplaceOrder */
class MarketplaceOrderResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'product_id' => $this->product_id, 'quantity' => $this->quantity, 'unit_price' => $this->unit_price, 'total' => $this->total, 'status' => $this->status, 'journal_id' => $this->journal_id, 'no_refund' => true, 'idempotency_key' => $request->user()?->id === $this->user_id ? $this->idempotency_key : null, 'snapshot' => $this->snapshot, 'created_at' => $this->created_at->toIso8601String()];
    }
}
