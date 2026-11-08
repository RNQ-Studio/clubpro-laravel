<?php

namespace App\Services;

use App\Models\User;

/**
 * Data diri anggota: pembaruan sebagian dan perhitungan kelengkapan profil.
 */
class MemberProfileService
{
    /**
     * Isian yang dihitung untuk "profil lengkap" (kunci => label untuk app).
     *
     * @var array<string, string>
     */
    public const COMPLETION_FIELDS = [
        'name' => 'Nama lengkap',
        'gender' => 'Jenis kelamin',
        'phone' => 'Nomor WhatsApp',
        'domicile' => 'Provinsi & kota domisili',
        'nik_region' => 'Provinsi & kota sesuai NIK',
    ];

    /** Awalan kolom wilayah: `{awalan}_province_code`, `{awalan}_city_name`, dst. */
    public const REGION_PREFIXES = ['domicile', 'nik'];

    public function __construct(private readonly IndonesiaRegionService $regions) {}

    /**
     * Simpan hanya isian yang dikirim; string kosong berarti dikosongkan.
     * Nama wilayah diambil dari kodenya. Wilayah NIK yang "sama dengan
     * domisili" disalin dari domisili dan ikut berubah bersamanya.
     *
     * @param  array<string, mixed>  $data  Data tervalidasi UpdateProfileRequest.
     */
    public function update(User $user, array $data): User
    {
        $data = array_map(
            fn (mixed $value): mixed => is_string($value) && trim($value) === '' ? null : $value,
            $data,
        );

        foreach (self::REGION_PREFIXES as $prefix) {
            if (array_key_exists("{$prefix}_city_code", $data)) {
                $data += $this->regionNames($prefix, $data["{$prefix}_province_code"] ?? null, $data["{$prefix}_city_code"]);
            }
        }

        $user->fill($data);

        if ($user->nik_same_as_domicile) {
            foreach (['province_code', 'province_name', 'city_code', 'city_name'] as $column) {
                $user->setAttribute("nik_{$column}", $user->getAttribute("domicile_{$column}"));
            }
        }

        // Nomor HP baru belum terverifikasi OTP.
        if ($user->isDirty('phone')) {
            $user->phone_verified_at = null;
        }

        $user->save();

        return $user->refresh();
    }

    /**
     * Wilayah data diri untuk API (domisili & sesuai NIK).
     *
     * @return array<string, string|bool|null>
     */
    public function regionAttributes(User $user): array
    {
        $attributes = [];
        foreach (self::REGION_PREFIXES as $prefix) {
            foreach (['province_code', 'province_name', 'city_code', 'city_name'] as $column) {
                $attributes["{$prefix}_{$column}"] = $user->getAttribute("{$prefix}_{$column}");
            }
        }

        return [...$attributes, 'nik_same_as_domicile' => (bool) $user->nik_same_as_domicile];
    }

    /**
     * @return array{is_complete: bool, completed: int, total: int, percent: int, missing: list<array{key: string, label: string}>}
     */
    public function completion(User $user): array
    {
        $missing = [];

        foreach (self::COMPLETION_FIELDS as $key => $label) {
            if (! $this->isFilled($user, $key)) {
                $missing[] = ['key' => $key, 'label' => $label];
            }
        }

        $total = count(self::COMPLETION_FIELDS);
        $completed = $total - count($missing);

        return [
            'is_complete' => $missing === [],
            'completed' => $completed,
            'total' => $total,
            'percent' => (int) floor($completed * 100 / $total),
            'missing' => $missing,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function regionNames(string $prefix, mixed $provinceCode, mixed $cityCode): array
    {
        $provinceCode = is_string($provinceCode) ? $provinceCode : null;
        $cityCode = is_string($cityCode) ? $cityCode : null;

        return [
            "{$prefix}_province_name" => $this->regions->province($provinceCode)['name'] ?? null,
            "{$prefix}_city_name" => $this->regions->city($provinceCode, $cityCode)['name'] ?? null,
        ];
    }

    private function isFilled(User $user, string $key): bool
    {
        $value = match ($key) {
            'domicile' => $user->domicile_city_code,
            'nik_region' => $user->nik_city_code,
            default => $user->getAttribute($key),
        };

        return $value !== null && (! is_string($value) || trim($value) !== '');
    }
}
