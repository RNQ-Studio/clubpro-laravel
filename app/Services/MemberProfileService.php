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
        'avatar' => 'Foto profil',
        'gender' => 'Jenis kelamin',
        'birth_place' => 'Tempat lahir',
        'birth_date' => 'Tanggal lahir',
        'phone' => 'Nomor HP / WhatsApp',
        'address' => 'Alamat',
        'blood_type' => 'Golongan darah',
        'emergency_contact_name' => 'Nama kontak darurat',
        'emergency_contact_phone' => 'Nomor kontak darurat',
    ];

    /**
     * Simpan hanya isian yang dikirim; string kosong berarti dikosongkan.
     *
     * @param  array<string, mixed>  $data  Data tervalidasi UpdateProfileRequest.
     */
    public function update(User $user, array $data): User
    {
        $data = array_map(
            fn (mixed $value): mixed => is_string($value) && trim($value) === '' ? null : $value,
            $data,
        );

        $user->fill($data);

        // Nomor HP baru belum terverifikasi OTP.
        if ($user->isDirty('phone')) {
            $user->phone_verified_at = null;
        }

        $user->save();

        return $user->refresh();
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

    private function isFilled(User $user, string $key): bool
    {
        // Foto akun Google dihitung sebagai foto profil.
        if ($key === 'avatar') {
            return filled($user->avatar) || filled($user->google_avatar_url);
        }

        $value = $user->getAttribute($key);

        return $value !== null && (! is_string($value) || trim($value) !== '');
    }
}
