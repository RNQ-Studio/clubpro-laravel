<?php

namespace App\Services\Auth\Google;

/**
 * Klaim terverifikasi dari ID token Google.
 */
final readonly class GoogleIdentity
{
    public function __construct(
        public string $subject,
        public string $email,
        public ?string $name = null,
        public ?string $pictureUrl = null,
    ) {}
}
