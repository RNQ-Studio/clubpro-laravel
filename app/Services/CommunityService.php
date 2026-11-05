<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Community;
use App\Models\User;
use App\Support\Enums\AssetStatus;
use App\Support\Enums\CommunityStatus;
use App\Support\Enums\CommunityType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommunityService
{
    public const MAX_PHOTOS = 6;

    /** Batas per foto; app sudah mengecilkan foto sebelum mengunggah. */
    private const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private readonly IndonesiaRegionService $regions) {}

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
        $this->assertUsableImages($owner, [$logoId], 'logo_asset_id');
        $this->assertUsableImages($owner, $photoIds, 'photo_asset_ids');

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

    /**
     * Foto harus unggahan pengaju sendiri: tanpa ini, id aset orang lain bisa ditautkan.
     *
     * @param  list<string>  $ids
     */
    private function assertUsableImages(User $owner, array $ids, string $field): void
    {
        $usable = Asset::query()
            ->whereKey($ids)
            ->where('user_id', $owner->getKey())
            ->where('status', AssetStatus::Active)
            ->whereIn('mime_type', self::IMAGE_MIMES)
            ->where('size', '<=', self::MAX_IMAGE_BYTES)
            ->count();

        if ($usable !== count($ids)) {
            $this->reject($field, 'Foto tidak valid. Unggah ulang foto JPG, PNG, atau WebP maksimal 5 MB.');
        }

        $taken = Community::query()->whereIn('logo_asset_id', $ids)->exists()
            || DB::table('community_photos')->whereIn('asset_id', $ids)->exists();

        if ($taken) {
            $this->reject($field, 'Foto ini sudah dipakai komunitas lain. Unggah ulang fotonya.');
        }
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
