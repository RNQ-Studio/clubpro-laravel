<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Asset;
use App\Models\Community;
use App\Models\IncomingReceipt;
use App\Models\Payout;
use App\Models\PayoutEvent;
use App\Models\PayoutFragment;
use App\Models\PayoutPart;
use App\Models\User;
use App\Services\MoneyMovementService;
use App\Services\SensitiveAssetService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/** @mixin Payout */
class PayoutResource extends JsonResource
{
    public function __construct(Payout $resource, private readonly bool $detail = true, private readonly ?int $scopeCommunity = null)
    {
        parent::__construct($resource);
    }

    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $actor */ $actor = $request->user();
        $money = app(MoneyMovementService::class);
        Gate::forUser($actor)->authorize('view', $this->resource);
        $owner = $actor->id === $this->user_id;
        $parts = PayoutPart::query()->where('payout_id', $this->id)->orderBy('id')->get()->filter(fn (PayoutPart $part): bool => ($owner || $money->canManage($actor, $part)) && ($this->scopeCommunity === null || ($this->scopeCommunity === -1 ? $part->community_id === null : $part->community_id === $this->scopeCommunity)));
        abort_unless($parts->isNotEmpty(), 404);
        $destination = $this->bank_snapshot;
        $destination['masked_number'] = $money->mask($destination['account_number']);
        if (! $this->detail) {
            unset($destination['account_number']);
        }
        $amount = (int) $parts->sum('amount');
        $verified = (int) $parts->sum('verified_amount');
        $held = (int) $parts->whereNotIn('status', ['failed', 'cancelled', 'succeeded'])->sum(fn (PayoutPart $part): int => $part->amount - $part->verified_amount);

        $statuses = $parts->pluck('status');
        $status = $verified === $amount ? 'succeeded' : ($statuses->contains('uncertain') || $verified > 0 ? 'uncertain' : ($statuses->contains('processing') ? 'processing' : ($statuses->every(fn ($s) => in_array($s, ['failed', 'cancelled'], true)) ? ($statuses->contains('failed') ? 'failed' : 'cancelled') : 'requested')));
        $contact = $this->detail ? $this->phone(User::query()->find($this->user_id)?->phone) : null;

        return ['member_contact' => ['phone' => $contact, 'available' => $contact !== null], 'released_amount' => (int) $parts->whereIn('status', ['failed', 'cancelled'])->sum('amount'), 'id' => $this->id, 'reference' => $this->id, 'community_id' => $this->community_id, 'member' => ['identity_id' => $this->member_identity_id, 'name' => $this->member_name], 'amount' => $amount, 'verified_amount' => $verified, 'remaining_amount' => $held, 'status' => $status, 'version' => $owner && $this->scopeCommunity === null ? $this->version : (int) $parts->sum('version'), 'destination' => $destination, 'created_at' => $this->created_at->toIso8601String(), 'updated_at' => $this->updated_at->toIso8601String(), 'can_manage' => $parts->contains(fn (PayoutPart $part): bool => $money->canManage($actor, $part)), 'can_cancel' => $owner && $parts->contains(fn (PayoutPart $part): bool => $part->status === 'requested'), 'parts' => $parts->map(function (PayoutPart $part) use ($actor, $money): array {
            $community = $part->community_id === null ? null : Community::query()->find($part->community_id);
            $founder = $community?->user_id === null ? null : User::query()->find($community->user_id);
            $phone = $this->phone($founder?->phone);
            $sourceReceipt = IncomingReceipt::query()->find($part->allocations[0]['receipt_id'] ?? '');
            $source = $sourceReceipt->collector_snapshot ?? [];
            $source['masked_number'] = isset($source['account_number']) ? $money->mask((string) $source['account_number']) : null;
            unset($source['account_number']);
            $fragments = $this->detail ? PayoutFragment::query()->where('payout_part_id', $part->id)->orderBy('created_at')->get()->map(fn (PayoutFragment $fragment): array => ['id' => $fragment->id, 'amount' => $fragment->amount, 'bank_reference' => $fragment->bank_reference, 'note' => $fragment->note, 'status' => $fragment->status, 'proof_asset_id' => $fragment->proof_asset_id, 'proof_url' => app(SensitiveAssetService::class)->viewUrl(Asset::query()->find($fragment->proof_asset_id), $actor), 'created_at' => $fragment->created_at->toIso8601String(), 'verified_at' => $fragment->verified_at?->toIso8601String(), 'verified_by' => $fragment->verified_by === null ? null : User::query()->find($fragment->verified_by)?->name])->all() : [];

            return ['source_account' => $source, 'id' => $part->id, 'community_id' => $part->community_id, 'community_name' => $community->name ?? 'Treasury platform', 'amount' => $part->amount, 'verified_amount' => $part->verified_amount, 'remaining_amount' => in_array($part->status, ['failed', 'cancelled'], true) ? 0 : $part->amount - $part->verified_amount, 'status' => $part->status, 'version' => $part->version, 'can_manage' => $money->canManage($actor, $part), 'founder_contact' => ['phone' => $phone, 'available' => $phone !== null], 'fragments' => $fragments, 'events' => $this->detail ? PayoutEvent::query()->where('payout_part_id', $part->id)->orderBy('id')->get()->map(fn (PayoutEvent $event): array => ['status' => $event->status, 'actor_name' => $event->actor_name, 'at' => $event->created_at->toIso8601String(), 'note' => $event->note])->all() : []];
        })->values()->all()];
    }

    private function phone(?string $phone): ?string
    {
        if (preg_match('/^\+?[0-9()\s-]+$/', trim((string) $phone)) !== 1) {
            return null;
        }
        $value = preg_replace('/[^0-9]/', '', (string) $phone);
        if (str_starts_with($value, '0')) {
            $value = '62'.substr($value, 1);
        }

        return preg_match('/^[1-9][0-9]{7,14}$/', $value) === 1 ? $value : null;
    }
}
