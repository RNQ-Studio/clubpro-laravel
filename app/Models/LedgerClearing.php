<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $source_custodian
 * @property string $handler_custodian
 * @property int $amount
 * @property string $status
 * @property string $source_journal_id
 * @property string|null $settlement_journal_id
 * @property string $source_reference
 * @property string|null $settlement_reference
 * @property string|null $settlement_proof_digest
 * @property int|null $settled_by
 * @property Carbon|null $settled_at
 */
class LedgerClearing extends Model
{
    use HasUuids;

    protected $fillable = ['id', 'source_custodian', 'handler_custodian', 'amount', 'status', 'source_journal_id', 'settlement_journal_id', 'source_reference', 'settlement_reference', 'settlement_proof_digest', 'settled_by', 'settled_at'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'settled_at' => 'datetime'];
    }
}
