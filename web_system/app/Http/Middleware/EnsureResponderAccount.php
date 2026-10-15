<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureResponderAccount
{
    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user && $user->is_admin) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Admin receiver access is required.',
            ], 403);
        }

        return redirect()
            ->route('dashboard')
            ->with('status', 'This area is reserved for authorized admins.');
    }
}
