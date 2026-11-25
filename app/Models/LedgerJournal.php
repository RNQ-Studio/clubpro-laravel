<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $actor_id
 * @property string $operation
 * @property string $idempotency_key
 * @property string $payload_hash
 * @property int|null $community_id
 * @property string|null $reference
 * @property array<string, mixed> $metadata
 * @property Carbon $created_at
 */
class LedgerJournal extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['id', 'actor_id', 'operation', 'idempotency_key', 'payload_hash', 'community_id', 'reference', 'metadata', 'created_at'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }
}
