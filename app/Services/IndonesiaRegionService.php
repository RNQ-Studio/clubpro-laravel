<?php

namespace App\Services;

/**
 * Provinsi dan kabupaten/kota Indonesia dari resources/data/wilayah-indonesia.json
 * (github.com/cahyadsn/wilayah, MIT, Kepmendagri 2025: 38 provinsi, 514 kab/kota).
 * Tabel `regions` tidak dipakai: isinya opsional dan sumbernya masih 34 provinsi.
 */
class IndonesiaRegionService
{
    /** @var list<array{code: string, name: string, cities: list<array{code: string, name: string}>}>|null */
    private ?array $provinces = null;

    /** @return list<array{code: string, name: string, cities: list<array{code: string, name: string}>}> */
    public function provinces(): array
    {
        return $this->provinces ??= json_decode(
            (string) file_get_contents(resource_path('data/wilayah-indonesia.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /** @return array{code: string, name: string, cities: list<array{code: string, name: string}>}|null */
    public function province(?string $code): ?array
    {
        return collect($this->provinces())->firstWhere('code', $code);
    }

    /** @return array{code: string, name: string}|null */
    public function city(?string $provinceCode, ?string $cityCode): ?array
    {
        return collect($this->province($provinceCode)['cities'] ?? [])->firstWhere('code', $cityCode);
    }
}
