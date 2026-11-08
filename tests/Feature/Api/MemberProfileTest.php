<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\MemberProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;
use Tests\TestCase;

class MemberProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-17 10:00:00'));
        $this->user = User::factory()->create(['name' => 'Rina', 'email' => 'rina@gmail.com']);
        Passport::actingAs($this->user);
    }

    /**
     * @return array<string, string|bool>
     */
    private function fullProfile(): array
    {
        return [
            'name' => 'Rina Ayu Lestari',
            'gender' => 'female',
            'phone' => '081234567890',
            'domicile_province_code' => '32',
            'domicile_city_code' => '32.73',
            'nik_same_as_domicile' => false,
            'nik_province_code' => '35',
            'nik_city_code' => '35.78',
        ];
    }

    public function test_only_the_five_member_fields_count_toward_completion(): void
    {
        $this->assertSame(
            ['name', 'gender', 'phone', 'domicile', 'nik_region'],
            array_keys(MemberProfileService::COMPLETION_FIELDS),
        );

        $response = $this->putJson('/api/v1/auth/me', [
            'name' => 'Rina Ayu Lestari',
            'gender' => 'female',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Rina Ayu Lestari')
            ->assertJsonPath('data.gender', 'female')
            ->assertJsonPath('data.email', 'rina@gmail.com')
            ->assertJsonPath('data.profile_completion.is_complete', false)
            ->assertJsonPath('data.profile_completion.completed', 2)
            ->assertJsonPath('data.profile_completion.total', 5);

        $this->assertSame(
            [
                ['key' => 'phone', 'label' => 'Nomor WhatsApp'],
                ['key' => 'domicile', 'label' => 'Provinsi & kota domisili'],
                ['key' => 'nik_region', 'label' => 'Provinsi & kota sesuai NIK'],
            ],
            $response->json('data.profile_completion.missing'),
        );
    }

    public function test_complete_profile_without_photo_is_complete(): void
    {
        $this->putJson('/api/v1/auth/me', $this->fullProfile())->assertOk()
            ->assertJsonPath('data.avatar_url', null)
            ->assertJsonPath('data.profile_completion.is_complete', true)
            ->assertJsonPath('data.profile_completion.percent', 100)
            ->assertJsonPath('data.profile_completion.missing', []);
    }

    public function test_region_names_come_from_the_codes(): void
    {
        $this->putJson('/api/v1/auth/me', $this->fullProfile())->assertOk()
            ->assertJsonPath('data.domicile_province_code', '32')
            ->assertJsonPath('data.domicile_province_name', 'Jawa Barat')
            ->assertJsonPath('data.domicile_city_code', '32.73')
            ->assertJsonPath('data.domicile_city_name', 'Kota Bandung')
            ->assertJsonPath('data.nik_same_as_domicile', false)
            ->assertJsonPath('data.nik_province_name', 'Jawa Timur')
            ->assertJsonPath('data.nik_city_name', 'Kota Surabaya');

        // Nama kiriman app diabaikan.
        $this->putJson('/api/v1/auth/me', [
            'domicile_province_code' => '32',
            'domicile_city_code' => '32.05',
            'domicile_city_name' => 'Kota Palsu',
        ])->assertOk()
            ->assertJsonPath('data.domicile_city_name', 'Kabupaten Garut');
    }

    public function test_nik_same_as_domicile_copies_and_follows_domicile(): void
    {
        $this->putJson('/api/v1/auth/me', [
            'domicile_province_code' => '32',
            'domicile_city_code' => '32.73',
            'nik_same_as_domicile' => true,
            // Diabaikan: NIK mengikuti domisili.
            'nik_province_code' => '35',
            'nik_city_code' => '35.78',
        ])->assertOk()
            ->assertJsonPath('data.nik_same_as_domicile', true)
            ->assertJsonPath('data.nik_province_code', '32')
            ->assertJsonPath('data.nik_city_code', '32.73')
            ->assertJsonPath('data.nik_city_name', 'Kota Bandung')
            ->assertJsonPath('data.profile_completion.missing.1.key', 'phone');

        // Domisili pindah → NIK ikut.
        $this->putJson('/api/v1/auth/me', [
            'domicile_province_code' => '35',
            'domicile_city_code' => '35.78',
        ])->assertOk()
            ->assertJsonPath('data.nik_province_name', 'Jawa Timur')
            ->assertJsonPath('data.nik_city_name', 'Kota Surabaya');

        // Tidak lagi sama: NIK tetap nilai terakhir dan bisa diubah sendiri.
        $this->putJson('/api/v1/auth/me', [
            'nik_same_as_domicile' => false,
            'nik_province_code' => '32',
            'nik_city_code' => '32.05',
        ])->assertOk()
            ->assertJsonPath('data.domicile_city_name', 'Kota Surabaya')
            ->assertJsonPath('data.nik_city_name', 'Kabupaten Garut');
    }

    public function test_regions_are_validated_in_pairs(): void
    {
        $this->putJson('/api/v1/auth/me', ['domicile_province_code' => '32'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['domicile_city_code' => 'Pilih kota/kabupaten domisili.']);

        $this->putJson('/api/v1/auth/me', ['nik_city_code' => '32.73'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nik_province_code' => 'Pilih provinsi sesuai NIK.']);

        $this->putJson('/api/v1/auth/me', ['domicile_province_code' => '99', 'domicile_city_code' => '99.01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['domicile_province_code' => 'Provinsi tidak dikenal.']);

        // Kota milik provinsi lain.
        $this->putJson('/api/v1/auth/me', ['nik_province_code' => '32', 'nik_city_code' => '35.78'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nik_city_code' => 'Kota/kabupaten tidak sesuai dengan provinsinya.']);

        $this->assertNull($this->user->refresh()->domicile_province_code);
    }

    public function test_region_can_be_cleared(): void
    {
        $this->putJson('/api/v1/auth/me', $this->fullProfile())->assertOk();

        $this->putJson('/api/v1/auth/me', ['domicile_province_code' => null, 'domicile_city_code' => null])->assertOk()
            ->assertJsonPath('data.domicile_province_name', null)
            ->assertJsonPath('data.domicile_city_code', null)
            ->assertJsonPath('data.domicile_city_name', null)
            ->assertJsonPath('data.nik_city_name', 'Kota Surabaya')
            ->assertJsonPath('data.profile_completion.missing.0.key', 'domicile');
    }

    public function test_omitted_fields_are_left_untouched(): void
    {
        $this->putJson('/api/v1/auth/me', $this->fullProfile())->assertOk();

        $this->putJson('/api/v1/auth/me', ['gender' => 'male'])->assertOk()
            ->assertJsonPath('data.gender', 'male')
            ->assertJsonPath('data.phone', '081234567890')
            ->assertJsonPath('data.domicile_city_name', 'Kota Bandung')
            ->assertJsonPath('data.nik_city_name', 'Kota Surabaya')
            ->assertJsonPath('data.name', 'Rina Ayu Lestari');
    }

    public function test_empty_value_clears_a_field_but_name_cannot_be_emptied(): void
    {
        $this->user->update(['gender' => 'female']);

        $this->putJson('/api/v1/auth/me', ['gender' => ''])->assertOk()
            ->assertJsonPath('data.gender', null);

        $this->putJson('/api/v1/auth/me', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_phone_numbers_are_normalized_and_change_resets_verification(): void
    {
        $this->user->forceFill(['phone' => '081111111111', 'phone_verified_at' => now()])->save();

        $this->putJson('/api/v1/auth/me', ['phone' => '0812-3456 7890'])->assertOk()
            ->assertJsonPath('data.phone', '081234567890')
            ->assertJsonPath('data.phone_verified_at', null);
    }

    public function test_unchanged_phone_keeps_verification(): void
    {
        $this->user->forceFill(['phone' => '081111111111', 'phone_verified_at' => now()])->save();

        $this->putJson('/api/v1/auth/me', ['phone' => '081111111111', 'gender' => 'female'])->assertOk()
            ->assertJsonPath('data.phone_verified_at', now()->toIso8601String());
    }

    public function test_phone_must_be_unique(): void
    {
        User::factory()->create(['phone' => '081234567890']);

        $this->putJson('/api/v1/auth/me', ['phone' => '081234567890'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone' => 'Nomor WhatsApp ini sudah dipakai akun lain.']);
    }

    public function test_invalid_values_are_rejected(): void
    {
        $this->putJson('/api/v1/auth/me', [
            'gender' => 'other',
            'phone' => '12',
            'nik_same_as_domicile' => 'kadang',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['gender', 'phone', 'nik_same_as_domicile']);
    }

    public function test_removed_fields_are_ignored_and_not_returned(): void
    {
        $this->user->forceFill([
            'birth_place' => 'Bandung',
            'address' => 'Jl. Lama',
            'blood_type' => 'O',
            'emergency_contact_name' => 'Ibu Sari',
        ])->save();

        // App lama yang masih mengirim isian lama tidak gagal; isian diabaikan.
        $response = $this->putJson('/api/v1/auth/me', [
            'gender' => 'female',
            'birth_place' => 'Garut',
            'birth_date' => '2001-04-12',
            'address' => 'Jl. Baru',
            'blood_type' => 'A',
            'emergency_contact_name' => 'Pak Budi',
            'emergency_contact_phone' => '081298765432',
        ])->assertOk();

        foreach (['birth_place', 'birth_date', 'address', 'blood_type', 'emergency_contact_name', 'emergency_contact_phone', 'archery_started_at', 'bow_type'] as $field) {
            $response->assertJsonMissingPath("data.{$field}");
        }
        // Data lama di database tidak dihapus maupun diubah.
        $this->assertSame('Jl. Lama', $this->user->refresh()->address);
        $this->assertSame('Ibu Sari', $this->user->emergency_contact_name);
        $this->assertNull($this->user->birth_date);
        $this->assertFalse(Schema::hasColumn('users', 'bow_type'));
    }
}
