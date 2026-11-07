<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Banner;
use App\Models\Community;
use App\Models\DuesPayment;
use App\Models\User;
use App\Support\Enums\AssetStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Syarat foto yang ditautkan ke komunitas, banner, atau bukti transfer iuran. Satu foto hanya untuk satu pemakai:
 * menghapus pemakainya ikut menghapus berkasnya.
 */
class ImageAssetGuard
{
    /** Batas per foto; app sudah mengecilkan foto sebelum mengunggah. */
    private const MAX_BYTES = 5 * 1024 * 1024;

    private const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Foto harus unggahan pengaju sendiri: tanpa ini, id aset orang lain bisa ditautkan.
     *
     * @param  list<string>  $ids
     *
     * @throws ValidationException
     */
    public function assertUsable(User $owner, array $ids, string $field): void
    {
        $usable = Asset::query()
            ->whereKey($ids)
            ->where('user_id', $owner->getKey())
            ->where('status', AssetStatus::Active)
            ->whereIn('mime_type', self::MIMES)
            ->where('size', '<=', self::MAX_BYTES)
            ->count();

        if ($usable !== count($ids)) {
            throw ValidationException::withMessages([$field => ['Foto tidak valid. Unggah ulang foto JPG, PNG, atau WebP maksimal 5 MB.']]);
        }

        $taken = Community::query()->whereIn('logo_asset_id', $ids)->exists()
            || DB::table('community_photos')->whereIn('asset_id', $ids)->exists()
            || Banner::query()->whereIn('image', $ids)->exists()
            || DuesPayment::query()->whereIn('proof_asset_id', $ids)->exists();

        if ($taken) {
            throw ValidationException::withMessages([$field => ['Foto ini sudah dipakai. Unggah ulang fotonya.']]);
        }
    }
}
