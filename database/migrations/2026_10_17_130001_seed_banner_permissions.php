<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Deploy hanya menjalankan `migrate --force`, jadi permission banners.* dibuat
 * di sini agar admin bisa mengelola banner tanpa seeding manual. Idempoten.
 */
return new class extends Migration
{
    private const GUARD = 'web';

    private const ABILITIES = ['viewAny', 'view', 'create', 'update', 'delete'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect(self::ABILITIES)
            ->map(fn (string $ability): Permission => Permission::findOrCreate("banners.{$ability}", self::GUARD));

        foreach (['super-admin', 'admin'] as $role) {
            Role::query()->where('guard_name', self::GUARD)->where('name', $role)->first()
                ?->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()->where('guard_name', self::GUARD)->where('name', 'like', 'banners.%')->delete();
    }
};
