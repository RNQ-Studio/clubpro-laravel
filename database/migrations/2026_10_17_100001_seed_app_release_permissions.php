<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Deploy produksi hanya menjalankan `migrate --force` (tanpa db:seed), jadi
 * permission app_releases.* dibuat di sini agar role admin bisa mengunggah APK
 * tanpa seeding manual. Idempoten; RolePermissionSeeder tetap sumber utamanya.
 */
return new class extends Migration
{
    private const GUARD = 'web';

    private const ABILITIES = ['viewAny', 'view', 'create', 'update', 'delete'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect(self::ABILITIES)
            ->map(fn (string $ability): Permission => Permission::findOrCreate("app_releases.{$ability}", self::GUARD));

        Role::query()->where('guard_name', self::GUARD)->where('name', 'super-admin')->first()
            ?->givePermissionTo($permissions);

        Role::query()->where('guard_name', self::GUARD)->where('name', 'admin')->first()
            ?->givePermissionTo($permissions->reject(fn (Permission $p): bool => $p->name === 'app_releases.update'));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()->where('guard_name', self::GUARD)->where('name', 'like', 'app_releases.%')->delete();
    }
};
