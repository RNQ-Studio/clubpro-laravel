<?php

namespace Tests\Feature\BackOffice;

use App\Filament\Pages\SendNotificationPage;
use App\Filament\Resources\AppConfigs\AppConfigResource;
use App\Filament\Resources\AppReleases\AppReleaseResource;
use App\Filament\Resources\AppVersions\AppVersionResource;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResourceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * Test that admin can access all back-office resources and pages.
     */
    public function test_admin_can_access_all_resources_and_pages(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(CategoryResource::getUrl('index'))
            ->assertOk();

        $this->actingAs($admin)
            ->get(UserResource::getUrl('index'))
            ->assertOk();

        $this->actingAs($admin)
            ->get(RoleResource::getUrl('index'))
            ->assertOk();

        $this->actingAs($admin)
            ->get(AppConfigResource::getUrl('index'))
            ->assertOk();

        $this->actingAs($admin)
            ->get(AppVersionResource::getUrl('index'))
            ->assertOk();

        $this->actingAs($admin)
            ->get(AppReleaseResource::getUrl('index'))
            ->assertOk();

        $this->actingAs($admin)
            ->get(SendNotificationPage::getUrl())
            ->assertOk();
    }

    /**
     * Test that staff can only access Category management and is blocked from others.
     */
    public function test_staff_restricted_access_behavior(): void
    {
        $staff = $this->userWithRole('staff');

        // Staff CAN access categories
        $this->actingAs($staff)
            ->get(CategoryResource::getUrl('index'))
            ->assertOk();

        // Staff is BLOCKED from users
        $this->actingAs($staff)
            ->get(UserResource::getUrl('index'))
            ->assertForbidden();

        // Staff is BLOCKED from roles
        $this->actingAs($staff)
            ->get(RoleResource::getUrl('index'))
            ->assertForbidden();

        // Staff is BLOCKED from app config
        $this->actingAs($staff)
            ->get(AppConfigResource::getUrl('index'))
            ->assertForbidden();

        // Staff is BLOCKED from app versions
        $this->actingAs($staff)
            ->get(AppVersionResource::getUrl('index'))
            ->assertForbidden();

        // Staff is BLOCKED from APK releases
        $this->actingAs($staff)
            ->get(AppReleaseResource::getUrl('index'))
            ->assertForbidden();

        // Staff is BLOCKED from sending push notifications
        $this->actingAs($staff)
            ->get(SendNotificationPage::getUrl())
            ->assertForbidden();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
