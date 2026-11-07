<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Community;
use App\Models\User;
use App\Support\Enums\CommunityMemberRole;
use App\Support\Enums\CommunityMemberStatus;
use App\Support\Enums\CommunityStatus;
use App\Support\Enums\CommunityType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommunityService
{
    public const MAX_PHOTOS = 6;

    /** Batas galeri sesudah komunitas berjalan. */
    public const MAX_GALLERY_PHOTOS = 60;

    public function __construct(
        private readonly IndonesiaRegionService $regions,
        private readonly AssetDeletionService $assets,
        private readonly ImageAssetGuard $images,
    ) {}

    /**
     * @param  array{search?: string|null, type?: string|null, province_code?: string|null}  $filters
     * @return LengthAwarePaginator<int, Community>
     */
    public function paginateApproved(array $filters, int $perPage): LengthAwarePaginator
    {
        $search = Str::lower(trim($filters['search'] ?? ''));

        return Community::query()
            ->approved()
            ->with('logo')
            ->withCount(['members as members_count' => fn (Builder $member): Builder => $member->where('status', CommunityMemberStatus::Active)])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.addcslashes($search, '\\%_').'%';
                $query->where(fn (Builder $match): Builder => $match
                    ->whereRaw('lower(name) like ?', [$like])
                    ->orWhereRaw('lower(short_name) like ?', [$like])
                    ->orWhereRaw('lower(city_name) like ?', [$like]));
            })
            ->when($filters['type'] ?? null, fn (Builder $query, string $type): Builder => $query->where('type', $type))
            ->when($filters['province_code'] ?? null, fn (Builder $query, string $code): Builder => $query->where('province_code', $code))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage);
    }

    /**
     * Ajukan komunitas baru. Selalu berstatus menunggu sampai diverifikasi super admin.
     *
     * @param  array{name: string, short_name: string, type: string, type_other?: string|null, description: string, address: string, province_code: string, city_code: string, contact_name: string, contact_phone: string, contact_email?: string|null, logo_asset_id: string, photo_asset_ids: list<string>}  $data
     *
     * @throws ValidationException
     */
    public function submit(User $owner, array $data): Community
    {
        $logoId = $data['logo_asset_id'];
        $photoIds = $data['photo_asset_ids'];

        $this->assertNotDuplicate($owner, $data['name']);
        $this->images->assertUsable($owner, [$logoId], 'logo_asset_id');
        $this->images->assertUsable($owner, $photoIds, 'photo_asset_ids');

        if (in_array($logoId, $photoIds, true)) {
            $this->reject('photo_asset_ids', 'Foto profil tidak boleh dipakai lagi sebagai lampiran.');
        }

        $province = $this->regions->province($data['province_code']);
        $city = $this->regions->city($data['province_code'], $data['city_code']);

        try {
            return DB::transaction(function () use ($owner, $data, $logoId, $photoIds, $province, $city): Community {
                $community = new Community([
                    ...collect($data)->except('photo_asset_ids')->all(),
                    'type_other' => $data['type'] === CommunityType::Lainnya->value ? $data['type_other'] : null,
                    'province_name' => $province['name'] ?? '',
                    'city_name' => $city['name'] ?? '',
                ]);
                $community->user_id = $owner->getKey();
                $community->status = CommunityStatus::Pending;
                $community->save();

                foreach ($photoIds as $order => $assetId) {
                    $community->photos()->attach($assetId, ['sort_order' => $order]);
                }

                $community->members()->create([
                    'user_id' => $owner->getKey(),
                    'role' => CommunityMemberRole::Creator,
                    'status' => CommunityMemberStatus::Active,
                    'joined_at' => now(),
                ]);

                // Foto yang sudah terpakai menjadi permanen; yang ditinggalkan di tengah
                // pengisian tetap dibersihkan scheduler sesudah retain_until-nya lewat.
                Asset::query()->whereKey([$logoId, ...$photoIds])->update(['retain_until' => null]);

                return $community;
            });
        } catch (UniqueConstraintViolationException) {
            // Kirim ganda bersamaan: foto yang sama sudah dipakai permintaan pertama.
            $this->reject('logo_asset_id', 'Komunitas ini sudah terkirim.');
        }
    }

    /**
     * Komunitas yang pengguna ikuti sebagai creator, admin, atau anggota. Yang belum atau
     * tidak terverifikasi hanya tampil untuk creatornya.
     *
     * @return Collection<int, Community>
     */
    public function joined(User $user): Collection
    {
        $id = $user->getKey();

        return Community::query()
            ->whereHas('members', fn (Builder $member): Builder => $member
                ->where('user_id', $id)
                ->where('status', CommunityMemberStatus::Active))
            ->where(fn (Builder $query): Builder => $query
                ->where('status', CommunityStatus::Approved)
                ->orWhere('communities.user_id', $id))
            ->with(['logo', 'members' => fn ($member) => $member->where('user_id', $id)])
            ->withCount(['members as members_count' => fn (Builder $member): Builder => $member->where('status', CommunityMemberStatus::Active)])
            ->orderBy('name')
            ->get();
    }

    /** Data detail, termasuk keanggotaan [$viewer] dan jumlah untuk tab. */
    public function detail(User $viewer, Community $community): Community
    {
        return $community
            ->load(['logo', 'photos', 'members' => fn ($member) => $member->where('user_id', $viewer->getKey())])
            ->loadCount([
                'members as members_count' => fn (Builder $member): Builder => $member->where('status', CommunityMemberStatus::Active),
                'members as pending_members_count' => fn (Builder $member): Builder => $member->where('status', CommunityMemberStatus::Pending),
                'activities as upcoming_activities_count' => fn (Builder $activity): Builder => $activity->where('starts_at', '>=', now()),
            ]);
    }

    /**
     * @throws ValidationException
     */
    public function addPhoto(User $user, Community $community, string $assetId): void
    {
        if ($community->photos()->count() >= self::MAX_GALLERY_PHOTOS) {
            $this->reject('asset_id', 'Galeri sudah penuh ('.self::MAX_GALLERY_PHOTOS.' foto). Hapus foto lama terlebih dahulu.');
        }

        $this->images->assertUsable($user, [$assetId], 'asset_id');

        $order = (int) DB::table('community_photos')->where('community_id', $community->getKey())->max('sort_order');

        try {
            DB::transaction(function () use ($community, $assetId, $order): void {
                $community->photos()->attach($assetId, ['sort_order' => $order + 1]);
                Asset::query()->whereKey($assetId)->update(['retain_until' => null]);
            });
        } catch (UniqueConstraintViolationException) {
            $this->reject('asset_id', 'Foto ini sudah ada di galeri.');
        }
    }

    public function removePhoto(Community $community, Asset $asset): void
    {
        abort_unless($community->photos()->whereKey($asset->getKey())->exists(), 404);

        $community->photos()->detach($asset->getKey());
        $this->assets->hardDelete($asset);
    }

    private function assertNotDuplicate(User $owner, string $name): void
    {
        $exists = Community::query()
            ->where('user_id', $owner->getKey())
            ->where('status', CommunityStatus::Pending)
            ->whereRaw('lower(name) = ?', [Str::lower($name)])
            ->exists();

        if ($exists) {
            $this->reject('name', 'Komunitas ini sudah Anda ajukan dan sedang menunggu verifikasi.');
        }
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
