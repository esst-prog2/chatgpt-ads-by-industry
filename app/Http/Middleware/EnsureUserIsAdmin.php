<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * Runs after 'auth', so $request->user() is always a real, authenticated
     * user here - this only decides whether they're an Admin, not whether
     * they're logged in at all.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isAdmin()) {
            abort(403, 'Only an Admin can access this page.');
        }

        return $next($request);
    }
}
