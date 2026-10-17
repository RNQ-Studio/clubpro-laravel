<?php

namespace App\Services\Auth\Google;

use Illuminate\Auth\AuthenticationException;

interface GoogleIdTokenVerifier
{
    /**
     * Verifikasi ID token dari Google Sign-In di app.
     *
     * @throws AuthenticationException Token tidak sah, kedaluwarsa, audiens salah, atau email belum terverifikasi.
     */
    public function verify(string $idToken): GoogleIdentity;
}
