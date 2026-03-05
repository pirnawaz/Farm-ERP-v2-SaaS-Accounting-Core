<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestId
{
    public const ATTRIBUTE = 'request_id';

    /**
     * Assign a UUID request_id to each request for audit log traceability.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::ATTRIBUTE, (string) Str::uuid());
        return $next($request);
    }
}
