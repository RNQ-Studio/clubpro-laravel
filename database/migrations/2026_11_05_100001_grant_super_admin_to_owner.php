<?php

use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Pemilik app menjadi super admin. Deploy hanya menjalankan `migrate --force`, jadi
 * perannya dipasang di sini. Akun dibuat bila belum ada; login Google menautkannya
 * lewat email. Idempoten.
 */
return new class extends Migration
{
    private const EMAIL = 'ramadhanrp.developer@gmail.com';

    public function up(): void
    {
        // Test memakai database kosong; logikanya diuji lewat grant().
        if (! app()->runningUnitTests()) {
            $this->grant();
        }
    }

    public function grant(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('super-admin', 'web');
        $user = User::query()->whereRaw('lower(email) = ?', [self::EMAIL])->first();

        if ($user === null) {
            $user = User::query()->forceCreate([
                'name' => Str::before(self::EMAIL, '@'),
                'email' => self::EMAIL,
                // Masuk lewat Google; isi acak agar login password tertutup.
                'password' => Str::password(40),
                'email_verified_at' => now(),
                'is_active' => true,
            ]);
        } elseif ($user->email_verified_at === null) {
            // Pendaftaran tidak membuktikan kepemilikan email: siapa pun bisa sudah
            // mendaftarkan alamat ini. Tutup aksesnya sebelum akun ini diberi peran.
            $user->forceFill(['password' => Str::password(40)])->save();
            app(AuthService::class)->logoutAllDevices($user);
        }

        $user->forceFill(['is_active' => true])->save();
        $user->assignRole($role);
    }

    public function down(): void
    {
        // Peran dibiarkan: mencabutnya bisa mengunci pemilik dari Admin Panel.
    }
};
