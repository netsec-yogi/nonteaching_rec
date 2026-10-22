<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `role:applicant` or `role:staff` (any staff role) or `role:a,b`. */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        abort_unless($user && $user->is_active, 403);

        $ok = in_array('staff', $roles, true) ? $user->isStaff() : $user->hasRole(...$roles);
        abort_unless($ok, 403, 'You do not have access to this area.');

        return $next($request);
    }
}
