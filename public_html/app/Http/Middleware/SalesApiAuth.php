<?php

namespace App\Http\Middleware;

use App\Modules\Erp\Services\SalesApiTokens;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Authenticates sales app requests with a bearer token from SalesApiTokens. */
class SalesApiAuth
{
    public function handle(Request $request, Closure $next)
    {
        $user = (new SalesApiTokens())->resolve((string) $request->bearerToken());
        if (!$user) return response()->json(['message' => 'Your session has ended. Sign in again.'], 401);
        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
