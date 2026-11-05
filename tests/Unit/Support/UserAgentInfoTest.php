<?php

namespace Tests\Unit\Support;

use App\Support\UserAgentInfo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UserAgentInfoTest extends TestCase
{
    /** @return array<string, array{string, string, string}> */
    public static function browsers(): array
    {
        return [
            'Chrome di macOS' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
                'Chrome 130 · macOS', 'desktop',
            ],
            'Edge di Windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.2849.56',
                'Edge 130 · Windows', 'desktop',
            ],
            'Firefox di Linux' => [
                'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0',
                'Firefox 131 · Linux', 'desktop',
            ],
            'Safari di iPhone' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1',
                'Safari 17 · iOS 17.2', 'mobile',
            ],
            'Safari di iPad' => [
                'Mozilla/5.0 (iPad; CPU OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
                'Safari 16 · iPadOS 16.6', 'tablet',
            ],
            'Chrome Android dengan model' => [
                'Mozilla/5.0 (Linux; Android 13; SM-S918B Build/TP1A.220624.014) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
                'Chrome 120 · Android 13 · SM-S918B', 'mobile',
            ],
            'Chrome Android model disamarkan' => [
                'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36',
                'Chrome 130 · Android 10', 'mobile',
            ],
            'Samsung Internet di tablet' => [
                'Mozilla/5.0 (Linux; Android 13; SM-X700) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/23.0 Chrome/115.0.0.0 Safari/537.36',
                'Samsung Internet 23 · Android 13 · SM-X700', 'tablet',
            ],
            'Chrome di iPhone' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/130.0.6723.37 Mobile/15E148 Safari/604.1',
                'Chrome 130 · iOS 17.2', 'mobile',
            ],
        ];
    }

    #[DataProvider('browsers')]
    public function test_browser_dikenali(string $userAgent, string $label, string $deviceType): void
    {
        $info = UserAgentInfo::parse($userAgent);

        $this->assertNotNull($info);
        $this->assertSame($label, $info->label());
        $this->assertSame($deviceType, $info->deviceType);
    }

    /** @return array<string, array{string|null}> */
    public static function nonBrowsers(): array
    {
        return [
            'tanpa header' => [null],
            'kosong' => [''],
            'app Flutter native' => ['Dart/3.5 (dart:io)'],
            'skrip' => ['curl/8.4.0'],
            'klien test' => ['Symfony'],
            'mirip browser tanpa mesin dikenal' => ['Mozilla/5.0 (compatible; Bot/1.0)'],
        ];
    }

    #[DataProvider('nonBrowsers')]
    public function test_bukan_browser_menghasilkan_null(?string $userAgent): void
    {
        $this->assertNull(UserAgentInfo::parse($userAgent));
    }

    public function test_pembaruan_browser_tetap_perangkat_yang_sama(): void
    {
        $lama = UserAgentInfo::parse('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36');
        $baru = UserAgentInfo::parse('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36');
        $lain = UserAgentInfo::parse('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36');

        $this->assertSame($lama?->fingerprint(), $baru?->fingerprint());
        $this->assertNotSame($lama?->fingerprint(), $lain?->fingerprint());
    }
}
