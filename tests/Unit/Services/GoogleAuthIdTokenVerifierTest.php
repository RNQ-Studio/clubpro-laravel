<?php

namespace Tests\Unit\Services;

use App\Services\Auth\Google\GoogleAuthIdTokenVerifier;
use Google\Auth\AccessToken;
use Illuminate\Auth\AuthenticationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\TestCase;

class GoogleAuthIdTokenVerifierTest extends TestCase
{
    private const CLIENT_ID = 'web-client.apps.googleusercontent.com';

    public function test_valid_token_yields_identity(): void
    {
        $identity = $this->verifier($this->payload())->verify('token');

        $this->assertSame('1234567890', $identity->subject);
        $this->assertSame('rina@gmail.com', $identity->email);
        $this->assertSame('Rina', $identity->name);
        $this->assertSame('https://lh3.googleusercontent.com/a/x', $identity->pictureUrl);
    }

    public function test_issuer_without_scheme_is_accepted(): void
    {
        $identity = $this->verifier($this->payload(['iss' => 'accounts.google.com']))->verify('token');

        $this->assertSame('rina@gmail.com', $identity->email);
    }

    /**
     * @return array<string, array{0: array<string, mixed>|false}>
     */
    public static function rejectedPayloads(): array
    {
        return [
            'signature/expiry gagal' => [false],
            'audiens lain' => [['aud' => 'other-app.apps.googleusercontent.com']],
            'issuer lain' => [['iss' => 'https://evil.example']],
            'email belum terverifikasi' => [['email_verified' => false]],
            'tanpa email' => [['email' => '']],
            'tanpa sub' => [['sub' => '']],
        ];
    }

    /**
     * @param  array<string, mixed>|false  $override
     */
    #[DataProvider('rejectedPayloads')]
    public function test_rejected_tokens(array|false $override): void
    {
        $this->expectException(AuthenticationException::class);

        $this->verifier($override === false ? false : $this->payload($override))->verify('token');
    }

    public function test_library_exception_is_treated_as_invalid_token(): void
    {
        $accessToken = Mockery::mock(AccessToken::class);
        $accessToken->shouldReceive('verify')->andThrow(new \UnexpectedValueException('bad'));

        $this->expectException(AuthenticationException::class);

        (new GoogleAuthIdTokenVerifier([self::CLIENT_ID], $accessToken))->verify('token');
    }

    public function test_missing_client_ids_means_not_configured(): void
    {
        $this->expectException(ServiceUnavailableHttpException::class);

        (new GoogleAuthIdTokenVerifier([], Mockery::mock(AccessToken::class)))->verify('token');
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function payload(array $override = []): array
    {
        return array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID,
            'sub' => '1234567890',
            'email' => 'Rina@Gmail.com',
            'email_verified' => true,
            'name' => 'Rina',
            'picture' => 'https://lh3.googleusercontent.com/a/x',
        ], $override);
    }

    /**
     * @param  array<string, mixed>|false  $payload
     */
    private function verifier(array|false $payload): GoogleAuthIdTokenVerifier
    {
        $accessToken = Mockery::mock(AccessToken::class);
        $accessToken->shouldReceive('verify')->with('token')->andReturn($payload);

        return new GoogleAuthIdTokenVerifier([self::CLIENT_ID], $accessToken);
    }
}
