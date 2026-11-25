<?php

namespace Tests\Feature\Api;

use App\Models\Quote;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class PublicMutationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_import_export_or_mutate_quotes(): void
    {
        $quote = Quote::factory()->create();
        $this->getJson('/api/v1/users/export')->assertUnauthorized();
        $this->postJson('/api/v1/users/import')->assertUnauthorized();
        $this->postJson('/api/v1/quotes', ['text' => 'Tidak boleh', 'author' => 'Tamu'])->assertUnauthorized();
        $this->putJson('/api/v1/quotes/'.$quote->id, ['text' => 'Tidak boleh'])->assertUnauthorized();
        $this->deleteJson('/api/v1/quotes/'.$quote->id)->assertUnauthorized();
        $this->assertDatabaseHas('quotes', ['id' => $quote->id, 'deleted_at' => null]);
    }

    public function test_community_or_platform_staff_do_not_gain_platform_data_access(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $quote = Quote::factory()->create();
        foreach ([null, 'staff', 'admin'] as $role) {
            $user = User::factory()->create();
            if ($role !== null) {
                $user->assignRole($role);
            }
            Passport::actingAs($user);
            $this->getJson('/api/v1/users/export')->assertForbidden();
            $this->postJson('/api/v1/users/import')->assertForbidden();
            $this->postJson('/api/v1/quotes', ['text' => 'Tidak boleh', 'author' => 'Anggota'])->assertForbidden();
            $this->putJson('/api/v1/quotes/'.$quote->id, ['text' => 'Tidak boleh'])->assertForbidden();
            $this->deleteJson('/api/v1/quotes/'.$quote->id)->assertForbidden();
        }
    }
}
