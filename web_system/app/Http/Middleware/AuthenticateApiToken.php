<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return $this->unauthorizedResponse();
        }

        $user = User::query()
            ->where('api_token', hash('sha256', $token))
            ->first();

        if (! $user) {
            return $this->unauthorizedResponse();
        }

        if ($user->isBlocked()) {
            $user->forceFill(['api_token' => null])->save();

            return response()->json([
                'message' => 'This civilian account has been blocked by an admin. Please contact Bontoc Rescue for assistance.',
            ], 423);
        }

        Auth::setUser($user);
        $request->setUserResolver(static fn (): User => $user);

        return $next($request);
    }

    private function unauthorizedResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Unauthorized.',
        ], 401);
    }
}
