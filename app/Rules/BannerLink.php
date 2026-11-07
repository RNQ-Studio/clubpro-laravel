<?php

namespace App\Rules;

use App\Models\Banner;
use App\Models\Community;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Tujuan banner saat diketuk: halaman app yang dikenal, atau tautan http/https.
 */
class BannerLink implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $link = is_string($value) ? $value : '';

        if (! str_starts_with($link, '/')) {
            $scheme = strtolower((string) parse_url($link, PHP_URL_SCHEME));

            if (! in_array($scheme, ['http', 'https'], true) || filter_var($link, FILTER_VALIDATE_URL) === false) {
                $fail('Tautan harus diawali http:// atau https://.');
            }

            return;
        }

        if (preg_match(Banner::APP_LINK_PATTERN, $link) !== 1) {
            $fail('Halaman tujuan tidak dikenal.');

            return;
        }

        $communityId = Banner::communityIdFromLink($link);

        if ($communityId !== null && ! Community::query()->approved()->whereKey($communityId)->exists()) {
            $fail('Komunitas tujuan tidak ditemukan atau belum terverifikasi.');
        }
    }
}
