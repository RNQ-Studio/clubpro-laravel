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
     * @return array<string, string>
     */
    private function fullProfile(): array
    {
        return [
            'name' => 'Rina Ayu Lestari',
            'gender' => 'female',
            'birth_place' => 'Bandung',
            'birth_date' => '2001-04-12',
            'phone' => '081234567890',
            'address' => 'Jl. Merdeka No. 10, Bandung',
            'blood_type' => 'O',
            'emergency_contact_name' => 'Ibu Sari',
            'emergency_contact_phone' => '081298765432',
        ];
    }

    public function test_partial_profile_can_be_saved_and_completion_reports_missing_fields(): void
    {
        $response = $this->putJson('/api/v1/auth/me', [
            'name' => 'Rina Ayu Lestari',
            'gender' => 'female',
            'birth_date' => '2001-04-12',
        ])->assertOk();

        $response
            ->assertJsonPath('data.name', 'Rina Ayu Lestari')
            ->assertJsonPath('data.gender', 'female')
            ->assertJsonPath('data.birth_date', '2001-04-12')
            ->assertJsonPath('data.address', null)
            ->assertJsonPath('data.email', 'rina@gmail.com')
            ->assertJsonPath('data.profile_completion.is_complete', false)
            ->assertJsonPath('data.profile_completion.completed', 3)
            ->assertJsonPath('data.profile_completion.total', count(MemberProfileService::COMPLETION_FIELDS));

        $missing = collect($response->json('data.profile_completion.missing'))->pluck('key');
        $this->assertContains('avatar', $missing);
        $this->assertContains('blood_type', $missing);
        $this->assertNotContains('gender', $missing);
    }

    public function test_omitted_fields_are_left_untouched(): void
    {
        $this->user->update(['address' => 'Jl. Lama', 'blood_type' => 'B']);

        $this->putJson('/api/v1/auth/me', ['birth_place' => 'Garut'])->assertOk()
            ->assertJsonPath('data.address', 'Jl. Lama')
            ->assertJsonPath('data.blood_type', 'B')
            ->assertJsonPath('data.birth_place', 'Garut')
            ->assertJsonPath('data.name', 'Rina');
    }

    public function test_empty_value_clears_a_field_but_name_cannot_be_emptied(): void
    {
        $this->user->update(['address' => 'Jl. Lama']);

        $this->putJson('/api/v1/auth/me', ['address' => ''])->assertOk()
            ->assertJsonPath('data.address', null);

        $this->putJson('/api/v1/auth/me', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_complete_profile_is_marked_complete(): void
    {
        $this->user->forceFill(['google_avatar_url' => 'https://lh3.googleusercontent.com/a/rina'])->save();

        $this->putJson('/api/v1/auth/me', $this->fullProfile())->assertOk()
            ->assertJsonPath('data.profile_completion.is_complete', true)
            ->assertJsonPath('data.profile_completion.percent', 100)
            ->assertJsonPath('data.profile_completion.missing', []);
    }

    public function test_photo_is_the_only_missing_item_without_avatar(): void
    {
        $this->putJson('/api/v1/auth/me', $this->fullProfile())->assertOk()
            ->assertJsonPath('data.profile_completion.is_complete', false)
            ->assertJsonPath('data.profile_completion.missing.0.key', 'avatar')
            ->assertJsonPath('data.profile_completion.missing.0.label', 'Foto profil');
    }

    public function test_phone_numbers_are_normalized_and_change_resets_verification(): void
    {
        $this->user->forceFill(['phone' => '081111111111', 'phone_verified_at' => now()])->save();

        $this->putJson('/api/v1/auth/me', [
            'phone' => '0812-3456 7890',
            'emergency_contact_phone' => '(0812) 9876.5432',
        ])->assertOk()
            ->assertJsonPath('data.phone', '081234567890')
            ->assertJsonPath('data.phone_verified_at', null)
            ->assertJsonPath('data.emergency_contact_phone', '081298765432');
    }

    public function test_unchanged_phone_keeps_verification(): void
    {
        $this->user->forceFill(['phone' => '081111111111', 'phone_verified_at' => now()])->save();

        $this->putJson('/api/v1/auth/me', ['phone' => '081111111111', 'address' => 'Jl. Baru'])->assertOk()
            ->assertJsonPath('data.phone_verified_at', now()->toIso8601String());
    }

    public function test_phone_must_be_unique(): void
    {
        User::factory()->create(['phone' => '081234567890']);

        $this->putJson('/api/v1/auth/me', ['phone' => '081234567890'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_invalid_values_are_rejected(): void
    {
        $this->putJson('/api/v1/auth/me', [
            'gender' => 'other',
            'blood_type' => 'C',
            'birth_date' => '2030-01-01',
            'phone' => '12',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['gender', 'blood_type', 'birth_date', 'phone']);
    }

    public function test_archery_fields_are_removed(): void
    {
        $this->assertFalse(Schema::hasColumn('users', 'archery_started_at'));
        $this->assertFalse(Schema::hasColumn('users', 'bow_type'));
        $this->assertArrayNotHasKey('archery_started_at', MemberProfileService::COMPLETION_FIELDS);
        $this->assertArrayNotHasKey('bow_type', MemberProfileService::COMPLETION_FIELDS);

        // App lama yang masih mengirim isian panahan tidak gagal; isian diabaikan.
        $this->putJson('/api/v1/auth/me', [
            'address' => 'Jl. Baru',
            'archery_started_at' => '2019-07-01',
            'bow_type' => 'recurve',
        ])->assertOk()
            ->assertJsonPath('data.address', 'Jl. Baru')
            ->assertJsonMissingPath('data.archery_started_at')
            ->assertJsonMissingPath('data.bow_type')
            ->assertJsonPath('data.profile_completion.total', 10);
    }

    public function test_me_includes_member_fields(): void
    {
        $this->user->update($this->fullProfile());

        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.blood_type', 'O')
            ->assertJsonPath('data.emergency_contact_name', 'Ibu Sari')
            ->assertJsonMissingPath('data.bow_type')
            ->assertJsonStructure(['data' => ['profile_completion' => ['is_complete', 'completed', 'total', 'percent', 'missing']]]);
    }
}
