<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\UserActivityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Catat akses terakhir pengguna yang sudah login. */
class TrackUserActivity
{
    public function __construct(private readonly UserActivityService $activity) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        if ($user instanceof User) {
            $this->activity->recordAccess($user, $request);
        }

        return $response;
    }
}
