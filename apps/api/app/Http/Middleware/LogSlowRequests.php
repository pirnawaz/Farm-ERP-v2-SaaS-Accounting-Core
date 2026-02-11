<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogSlowRequests
{
    /**
     * Handle an incoming request and log if it exceeds the slow-request threshold.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        $response = $next($request);

        if (! config('performance.slow_request.enabled', false)) {
            return $response;
        }

        $durationMs = (int) round((microtime(true) - $start) * 1000);
        $thresholdMs = config('performance.slow_request.threshold_ms', 3000);

        if ($durationMs < $thresholdMs) {
            return $response;
        }

        $maxLen = config('performance.slow_request.query_params_max_length', 200);
        $queryParams = $request->query();
        $safeParams = [];
        foreach ($queryParams as $key => $value) {
            $str = is_scalar($value) ? (string) $value : json_encode($value);
            $safeParams[$key] = strlen($str) > $maxLen
                ? substr($str, 0, $maxLen) . '…'
                : $str;
        }

        $tenantId = $request->attributes->get('tenant_id');

        Log::warning('Slow request detected', [
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
            'duration_ms' => $durationMs,
            'query_params' => $safeParams,
            'tenant_id' => $tenantId,
        ]);

        return $response;
    }
}
