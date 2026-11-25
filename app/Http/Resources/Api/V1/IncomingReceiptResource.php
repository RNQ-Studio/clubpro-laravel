<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Asset;
use App\Models\Community;
use App\Models\IncomingReceipt;
use App\Models\User;
use App\Services\IncomingReceiptService;
use App\Services\SensitiveAssetService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin IncomingReceipt */
class IncomingReceiptResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $user = User::query()->find($this->user_id);
        $reviewer = $this->reviewed_by === null ? null : User::query()->find($this->reviewed_by);
        $proof = Asset::query()->find($this->proof_asset_id);
        /** @var User|null $viewer */
        $viewer = $request->user();

        return [
            'id' => $this->id, 'can_review' => $viewer !== null && app(IncomingReceiptService::class)->canReview($viewer, $this->community_id === null ? null : Community::query()->find($this->community_id), $this->purpose), 'purpose' => $this->purpose, 'status' => $this->status,
            'community_id' => $this->community_id, 'claimed_amount' => $this->claimed_amount,
            'verified_amount' => $this->verified_amount, 'bank_reference' => $this->bank_reference,
            'verified_reference' => $this->verified_reference, 'note' => $this->note, 'review_note' => $this->review_note,
            'created_at' => $this->created_at->toIso8601String(), 'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'user' => $user === null ? null : ['id' => $user->id, 'name' => $user->name],
            'reviewer' => $reviewer === null ? null : ['id' => $reviewer->id, 'name' => $reviewer->name],
            'proof_url' => app(SensitiveAssetService::class)->viewUrl($proof, $viewer),
            'collector_snapshot' => $this->collector_snapshot, 'invoice_ids' => $this->invoice_ids, 'credit_excess' => $this->credit_excess, 'target_member_id' => $this->target_member_id, 'journal_id' => $this->journal_id,
        ];
    }
}
