<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminResponder
{
    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        if (! $request->user()?->is_admin) {
            return redirect()
                ->route('dashboard')
                ->with('status', 'Admin access is reserved for admin receiver accounts.');
        }

        return $next($request);
    }
}
