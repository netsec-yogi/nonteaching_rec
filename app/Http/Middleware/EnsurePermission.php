<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `permission:slug` — passes when the user holds any of the listed permissions. */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        abort_unless($user && collect($permissions)->contains(fn ($p) => $user->hasPermission($p)), 403, 'You do not have permission for this action.');

        return $next($request);
    }
}
