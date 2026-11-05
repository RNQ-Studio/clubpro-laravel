<?php

namespace App\Support;

/**
 * Browser & sistem operasi dari header User-Agent. Yang bukan browser (app native,
 * skrip) menghasilkan null: perangkatnya hanya dikenal bila app melaporkannya sendiri.
 */
final readonly class UserAgentInfo
{
    public const TYPE_DESKTOP = 'desktop';

    public const TYPE_MOBILE = 'mobile';

    public const TYPE_TABLET = 'tablet';

    /** Urutan penting: Edge/Opera/Samsung memuat "Chrome", dan Chrome memuat "Safari". */
    private const BROWSERS = [
        'Edge' => '/\bEdg(?:e|A|iOS)?\/(\d+)/',
        'Opera' => '/\b(?:OPR|Opera)\/(\d+)/',
        'Samsung Internet' => '/\bSamsungBrowser\/(\d+)/',
        'Firefox' => '/\b(?:Firefox|FxiOS)\/(\d+)/',
        'Chrome' => '/\b(?:Chrome|CriOS)\/(\d+)/',
        'Safari' => '/\bVersion\/(\d+).*\bSafari\//',
    ];

    public function __construct(
        public string $browser,
        public string $browserVersion,
        public string $os,
        public ?string $osVersion,
        public string $deviceType,
        public ?string $model,
    ) {}

    public static function parse(?string $userAgent): ?self
    {
        if ($userAgent === null || ! str_starts_with($userAgent, 'Mozilla/')) {
            return null;
        }

        foreach (self::BROWSERS as $browser => $pattern) {
            if (preg_match($pattern, $userAgent, $match) === 1) {
                [$os, $osVersion] = self::os($userAgent);

                return new self(
                    browser: $browser,
                    browserVersion: $match[1],
                    os: $os,
                    osVersion: $osVersion,
                    deviceType: self::deviceType($userAgent),
                    model: self::model($userAgent),
                );
            }
        }

        return null;
    }

    /** "Chrome 130 · Android 14 · SM-S918B". */
    public function label(): string
    {
        return implode(' · ', array_filter(["{$this->browser} {$this->browserVersion}", $this->osLabel(), $this->model]));
    }

    /** "Android 14", atau "macOS" bila versinya tidak terbaca. */
    public function osLabel(): string
    {
        return trim("{$this->os} {$this->osVersion}");
    }

    /** Tanpa nomor versi: pembaruan browser tidak boleh dihitung sebagai perangkat baru. */
    public function fingerprint(): string
    {
        return 'ua-'.sha1("{$this->browser}|{$this->os}|{$this->model}");
    }

    /** @return array{0: string, 1: string|null} */
    private static function os(string $userAgent): array
    {
        return match (true) {
            preg_match('/Android ([\d.]+)/', $userAgent, $m) === 1 => ['Android', $m[1]],
            preg_match('/iPhone OS ([\d_]+)/', $userAgent, $m) === 1 => ['iOS', str_replace('_', '.', $m[1])],
            preg_match('/iPad.*OS ([\d_]+)/', $userAgent, $m) === 1 => ['iPadOS', str_replace('_', '.', $m[1])],
            str_contains($userAgent, 'Windows') => ['Windows', null],
            str_contains($userAgent, 'CrOS') => ['ChromeOS', null],
            // Versi di UA macOS dibekukan browser pada 10.15, jadi tidak dipakai.
            str_contains($userAgent, 'Macintosh') => ['macOS', null],
            str_contains($userAgent, 'Linux') => ['Linux', null],
            default => ['Lainnya', null],
        };
    }

    private static function deviceType(string $userAgent): string
    {
        return match (true) {
            str_contains($userAgent, 'iPad') => self::TYPE_TABLET,
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'Mobile') => self::TYPE_MOBILE,
            str_contains($userAgent, 'Android') => self::TYPE_TABLET,
            default => self::TYPE_DESKTOP,
        };
    }

    private static function model(string $userAgent): ?string
    {
        if (preg_match('/Android [\d.]+; ([^;)]+?)(?: Build\/[^;)]*)?[;)]/', $userAgent, $m) !== 1) {
            return null;
        }

        // Chrome menyamarkan model Android menjadi "K"; "wv" penanda WebView.
        return in_array($m[1], ['K', 'wv'], true) ? null : $m[1];
    }
}
