<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PerformanceMonitoringServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if (! config('performance.slow_sql.enabled', false)) {
            return;
        }

        $thresholdMs = config('performance.slow_sql.threshold_ms', 1000);
        $bindingsMaxLength = config('performance.slow_sql.bindings_max_length', 100);

        DB::listen(function ($query) use ($thresholdMs, $bindingsMaxLength) {
            $elapsedMs = $query->time;

            if ($elapsedMs < $thresholdMs) {
                return;
            }

            $bindings = is_array($query->bindings) ? $query->bindings : [];
            $truncated = array_map(function ($value) use ($bindingsMaxLength) {
                if (is_string($value) && strlen($value) > $bindingsMaxLength) {
                    return substr($value, 0, $bindingsMaxLength) . '…';
                }
                if (is_object($value) || is_array($value)) {
                    return '[object]';
                }
                return $value;
            }, $bindings);

            $connectionName = $query->connection->getName();

            $tenantId = null;
            if (function_exists('request') && request()) {
                $tenantId = request()->attributes->get('tenant_id');
            }

            Log::warning('Slow SQL query detected', [
                'sql' => $query->sql,
                'bindings' => $truncated,
                'elapsed_ms' => $elapsedMs,
                'connection' => $connectionName,
                'tenant_id' => $tenantId,
            ]);
        });
    }
}
