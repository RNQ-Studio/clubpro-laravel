<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property array<string,mixed> $details
 * @property int $version
 */
class PrimaryBankAccount extends Model
{
    protected $hidden = ['details'];

    protected $fillable = ['user_id', 'details', 'version'];

    protected function casts(): array
    {
        return ['details' => 'encrypted:array', 'version' => 'integer'];
    }
}
