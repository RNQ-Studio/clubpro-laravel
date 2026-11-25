<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UniqueAccountEmail implements ValidationRule
{
    public function __construct(private readonly ?int $exceptUserId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($value))])
            ->when($this->exceptUserId !== null, fn ($query) => $query->whereKeyNot($this->exceptUserId))->exists()) {
            $fail('Email sudah digunakan akun lain.');
        }
    }
}
