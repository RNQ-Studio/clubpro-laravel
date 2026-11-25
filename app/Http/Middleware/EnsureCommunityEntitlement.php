<?php

namespace App\Http\Middleware;

use App\Models\Community;
use App\Models\User;
use App\Services\BillingService;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Paid community actions are scoped to the route, while recovery remains reachable. */
class EnsureCommunityEntitlement
{
    public function __construct(private readonly BillingService $billing) {}

    public function handle(Request $request, Closure $next): Response
    {
        $community = $request->route('community');
        $viewer = $request->user();
        if (! $community instanceof Community || ! $viewer instanceof User) {
            return $next($request);
        }

        $uri = $request->route()?->uri() ?? '';
        $prefix = 'api/v1/communities/{community}';
        if (! str_starts_with($uri, $prefix)) {
            return $next($request);
        }
        $suffix = substr($uri, strlen($prefix));
        // These routes pay obligations or expose the status needed to recover access.
        if (str_starts_with($suffix, '/billing') || str_starts_with($suffix, '/dues')
            || str_starts_with($suffix, '/topups') || str_starts_with($suffix, '/receipts')
            || $suffix === '/wallet'
            || in_array($suffix, ['/leave', '/card'], true)) {
            return $next($request);
        }
        $privateRead = $suffix === '/members' || str_starts_with($suffix, '/manual-members');
        if ($request->isMethod('GET') && ! $privateRead) {
            return $next($request);
        }

        // Central support retains its existing audited recovery authority.
        if ($viewer->hasRole('super-admin')) {
            return $next($request);
        }
        if (! $this->billing->entitlement($this->billing->platformSubject($community))['active']) {
            return $this->paymentRequired($community, 'platform');
        }
        if ($suffix !== '/join') {
            $member = $community->membershipOf($viewer);
            // Existing policies remain responsible for nonmembers and moderation.
            if ($member !== null && ! $this->billing->entitlement($this->billing->memberSubject($member))['active']) {
                return $this->paymentRequired($community, 'member');
            }
        }

        return $next($request);
    }

    private function paymentRequired(Community $community, string $scope): Response
    {
        return ApiResponse::error(
            $scope === 'platform'
                ? 'Langganan komunitas tidak aktif. Pendiri dapat membuka pembayaran untuk mengaktifkan kembali.'
                : 'Keanggotaan tidak aktif. Lunasi seluruh tagihan untuk mengaktifkan kembali.',
            402,
            code: 'ENTITLEMENT_REQUIRED',
            meta: [
                'scope' => $scope,
                'community_id' => $community->id,
                'billing_path' => 'communities/'.$community->id.'/billing?scope='.$scope,
            ],
        );
    }
}
