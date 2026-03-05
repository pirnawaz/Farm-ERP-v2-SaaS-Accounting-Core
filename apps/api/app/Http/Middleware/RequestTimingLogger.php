<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs one structured (JSON) event per request for observability.
 * Includes request_id, user_id, tenant_id, route, status_code, latency_ms.
 * Does not log sensitive fields (passwords, tokens).
 */
class RequestTimingLogger
{
    private const START_ATTR = 'request_timing_start';

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::START_ATTR, microtime(true));
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $start = $request->attributes->get(self::START_ATTR);
        if ($start === null) {
            return;
        }
        $latencyMs = (int) round((microtime(true) - $start) * 1000);
        $payload = [
            'message' => 'request',
            'request_id' => $request->attributes->get(RequestId::ATTRIBUTE),
            'user_id' => $request->attributes->get('user_id'),
            'tenant_id' => $request->attributes->get('tenant_id'),
            'impersonator_user_id' => $request->attributes->get('impersonator_user_id'),
            'route' => $request->route()?->getName(),
            'status_code' => $response->getStatusCode(),
            'latency_ms' => $latencyMs,
        ];
        // Remove nulls so log stays minimal
        $payload = array_filter($payload, fn ($v) => $v !== null && $v !== '');
        Log::channel('request')->info('request', $payload);
    }
}
