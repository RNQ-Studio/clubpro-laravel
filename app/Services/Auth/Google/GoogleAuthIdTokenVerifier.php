<?php

namespace App\Services\Auth\Google;

use Google\Auth\AccessToken;
use Illuminate\Auth\AuthenticationException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

/**
 * Verifikasi ID token dengan sertifikat publik Google (library google/auth):
 * tanda tangan, masa berlaku, issuer, audiens (client ID OAuth yang diizinkan),
 * dan status verifikasi email.
 */
class GoogleAuthIdTokenVerifier implements GoogleIdTokenVerifier
{
    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    /**
     * @param  list<string>  $clientIds  Web client ID (serverClientId app) dan client lain yang boleh menjadi `aud`.
     */
    public function __construct(
        private readonly array $clientIds,
        private readonly AccessToken $accessToken = new AccessToken,
    ) {}

    public function verify(string $idToken): GoogleIdentity
    {
        if ($this->clientIds === []) {
            throw new ServiceUnavailableHttpException(null, 'Google sign-in is not configured.');
        }

        try {
            $payload = $this->accessToken->verify($idToken);
        } catch (Throwable) {
            $payload = false;
        }

        if (! is_array($payload)) {
            throw new AuthenticationException('Invalid Google token.');
        }

        $subject = (string) ($payload['sub'] ?? '');
        $audience = (string) ($payload['aud'] ?? '');
        $issuer = (string) ($payload['iss'] ?? '');
        $email = (string) ($payload['email'] ?? '');
        $emailVerified = filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($subject === '' || ! in_array($audience, $this->clientIds, true) || ! in_array($issuer, self::ISSUERS, true)) {
            throw new AuthenticationException('Invalid Google token.');
        }

        if ($email === '' || ! $emailVerified) {
            throw new AuthenticationException('Google account email is not verified.');
        }

        return new GoogleIdentity(
            subject: $subject,
            email: strtolower($email),
            name: isset($payload['name']) ? (string) $payload['name'] : null,
            pictureUrl: isset($payload['picture']) ? (string) $payload['picture'] : null,
        );
    }
}
