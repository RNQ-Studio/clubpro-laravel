<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\DuesPayment;
use App\Models\IncomingReceipt;
use App\Models\User;
use App\Support\Enums\AssetStatus;
use App\Support\Enums\StorageType;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;

class SensitiveAssetService
{
    /** Move bytes out of publicly served storage, including legacy proof uploads. Safe to retry. */
    public function privatize(Asset $asset): void
    {
        if ($asset->storage_type === StorageType::Private) {
            return;
        }

        $source = Storage::disk($asset->storage_type->disk());
        $target = Storage::disk('local');
        $path = 'sensitive/'.$asset->id.'.'.($asset->extension ?: 'bin');
        if ($source->exists($asset->path)) {
            $stream = $source->readStream($asset->path);
            if ($stream === null) {
                throw new RuntimeException('Bukti tidak dapat dipindahkan ke penyimpanan privat.');
            }
            try {
                if (! $target->put($path, $stream)) {
                    throw new RuntimeException('Bukti tidak dapat disimpan secara privat.');
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            if (! $source->delete($asset->path)) {
                throw new RuntimeException('Salinan publik bukti belum dapat dihapus.');
            }
        }

        // Missing legacy bytes stay missing; never manufacture a replacement or expose the old URL.
        $asset->forceFill(['storage_type' => StorageType::Private, 'path' => $path, 'url' => null])->save();
    }

    /** Short-lived capability, issued only from an authorized API response. Never a public DTO field. */
    public function viewUrl(?Asset $asset, ?User $viewer): ?string
    {
        if ($asset === null || $viewer === null || $asset->status !== AssetStatus::Active) {
            return null;
        }

        $allowed = $asset->user_id === $viewer->id;
        if (! $allowed) {
            $allowed = DuesPayment::query()->where('proof_asset_id', $asset->id)
                ->whereIn('community_id', CommunityMember::query()->select('community_id')
                    ->where('user_id', $viewer->id)->where('status', 'active')->whereIn('role', ['creator', 'admin']))
                ->exists();
        }

        if (! $allowed) {
            $allowed = IncomingReceipt::query()->where('proof_asset_id', $asset->id)->get()->contains(function (IncomingReceipt $receipt) use ($viewer): bool {
                return $receipt->user_id === $viewer->id || app(IncomingReceiptService::class)->canReview($viewer, $receipt->community_id === null ? null : Community::query()->find($receipt->community_id), $receipt->purpose);
            });
        }

        return $allowed ? URL::temporarySignedRoute('assets.file', now()->addMinutes(5), ['asset' => $asset->id]) : null;
    }
}
