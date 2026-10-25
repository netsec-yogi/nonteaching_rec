<?php

namespace App\Http\Middleware;

use App\Models\Advertisement;
use App\Services\ApplicationAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `manages-advertisement` — on routes with an {advertisement} parameter, passes only for the
 * Super Administrator or a Recruitment Administrator assigned to that advertisement. Holding
 * advertisements.manage is not enough on its own: it lets you manage *your* advertisements.
 */
class EnsureManagesAdvertisement
{
    public function __construct(private ApplicationAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $advertisement = $request->route('advertisement');
        if ($advertisement instanceof Advertisement) {
            abort_unless($this->access->managesAdvertisement($request->user(), $advertisement->id), 403, 'You are not an administrator of this advertisement.');
        }

        return $next($request);
    }
}
