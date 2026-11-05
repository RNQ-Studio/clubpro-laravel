<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pengelolaan pengguna dari Admin Panel. Pelaku tidak bisa mencabut akses atau
 * menonaktifkan dirinya sendiri, sehingga selalu tersisa satu super admin aktif.
 */
class UserAdminService
{
    public const SUPER_ADMIN = 'super-admin';

    public function __construct(private readonly AuthService $auth) {}

    /**
     * @param  array{search?: string|null, role?: string|null, status?: string|null}  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $search = Str::lower(trim($filters['search'] ?? ''));
        $status = $filters['status'] ?? null;

        $users = User::query()
            ->with(['roles', 'latestDevice'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.addcslashes($search, '\\%_').'%';
                $query->where(fn (Builder $match): Builder => $match
                    ->whereRaw('lower(name) like ?', [$like])
                    ->orWhereRaw('lower(email) like ?', [$like])
                    ->orWhere('phone', 'like', $like));
            })
            ->when(($filters['role'] ?? null) === self::SUPER_ADMIN, fn (Builder $query): Builder => $this->superAdmins($query))
            ->when($status !== null, fn (Builder $query): Builder => $query->where('is_active', $status === 'active'))
            // Paling baru mengakses di atas; yang belum tercatat di bawah.
            ->orderByRaw('last_seen_at is null')
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $this->loadAvatarAssets($users);

        return $users;
    }

    /** @return array{total: int, super_admins: int, active_last_7_days: int, inactive: int} */
    public function summary(): array
    {
        return [
            'total' => User::query()->count(),
            'super_admins' => $this->superAdmins(User::query())->count(),
            'active_last_7_days' => User::query()->where('last_seen_at', '>=', now()->subDays(7))->count(),
            'inactive' => User::query()->where('is_active', false)->count(),
        ];
    }

    public function detail(User $user): User
    {
        return $user->load([
            'roles',
            'devices' => fn ($query) => $query->orderByRaw('last_active_at is null')->orderByDesc('last_active_at'),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function setSuperAdmin(User $actor, User $target, bool $enabled): User
    {
        if ($enabled === $target->hasRole(self::SUPER_ADMIN)) {
            return $this->detail($target);
        }

        if (! $enabled && $actor->is($target)) {
            $this->reject('super_admin', 'Anda tidak bisa mencabut akses super admin Anda sendiri.');
        }

        if ($enabled && ! $target->is_active) {
            $this->reject('super_admin', 'Aktifkan akun ini terlebih dahulu.');
        }

        // Pendaftaran dan impor tidak membuktikan kepemilikan email: akun yang belum
        // terverifikasi bisa saja dibuat orang lain memakai email pemilik sebenarnya.
        if ($enabled && ! $target->hasVerifiedEmail()) {
            $this->reject('super_admin', 'Email akun ini belum terverifikasi. Minta pengguna masuk dengan Google terlebih dahulu.');
        }

        $enabled ? $target->assignRole(self::SUPER_ADMIN) : $target->removeRole(self::SUPER_ADMIN);

        $this->log($actor, $target, $enabled ? 'super_admin_granted' : 'super_admin_revoked');

        return $this->detail($target);
    }

    /**
     * @throws ValidationException
     */
    public function setActive(User $actor, User $target, bool $active): User
    {
        if ($active === $target->is_active) {
            return $this->detail($target);
        }

        if (! $active && $actor->is($target)) {
            $this->reject('is_active', 'Anda tidak bisa menonaktifkan akun Anda sendiri.');
        }

        $target->update(['is_active' => $active]);

        // Akun nonaktif tidak boleh tetap memakai sesi yang sudah berjalan.
        if (! $active) {
            $this->auth->logoutAllDevices($target);
        }

        $this->log($actor, $target, $active ? 'user_activated' : 'user_deactivated');

        return $this->detail($target);
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private function superAdmins(Builder $query): Builder
    {
        return $query->whereHas('roles', fn (Builder $role): Builder => $role->where('name', self::SUPER_ADMIN));
    }

    /**
     * `users.avatar` bisa berisi path lama; hanya yang UUID boleh menyentuh tabel assets.
     *
     * @param  LengthAwarePaginator<int, User>  $users
     */
    private function loadAvatarAssets(LengthAwarePaginator $users): void
    {
        Collection::make($users->items())
            ->filter(fn (User $user): bool => Str::isUuid((string) $user->avatar))
            ->load('avatarAsset');
    }

    private function log(User $actor, User $target, string $event): void
    {
        activity('admin-panel')
            ->causedBy($actor)
            ->performedOn($target)
            ->event($event)
            ->log($event);
    }

    /**
     * @throws ValidationException
     */
    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
