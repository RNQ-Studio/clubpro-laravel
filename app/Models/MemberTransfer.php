<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property int $sender_identity_id
 * @property int $recipient_identity_id
 * @property int $community_id
 * @property int $recipient_community_id
 * @property int $amount
 * @property string $idempotency_key
 * @property string $payload_hash
 * @property string|null $journal_id
 * @property array<string,mixed> $snapshot
 * @property Carbon $created_at
 */
class MemberTransfer extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'sender_identity_id', 'recipient_identity_id', 'community_id', 'recipient_community_id', 'amount', 'idempotency_key', 'payload_hash', 'journal_id', 'snapshot'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'snapshot' => 'array'];
    }
}
