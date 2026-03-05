<?php

namespace App\Http\Middleware;

use App\Helpers\AuthCookie;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the response is 401 and the request had an auth cookie, attach a clear-cookie
 * so the browser removes the invalid session and avoids infinite invalid-session loops.
 */
class ClearCookieOnUnauthorized
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() === 401 && $request->cookie(AuthCookie::NAME)) {
            $response = $response->withCookie(AuthCookie::make('', true));
        }

        return $response;
    }
}
