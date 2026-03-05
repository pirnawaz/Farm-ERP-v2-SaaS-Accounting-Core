<?php

namespace App\Providers;

use App\Domains\Operations\LandLease\LandLease;
use App\Domains\Operations\LandLease\LandLeaseAccrual;
use App\Domains\Operations\LandLease\LandLeasePolicy;
use App\Policies\LandLeaseAccrualPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EnvironmentValidationServiceProvider extends ServiceProvider
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
        $this->validateEnvironment();
        $this->validateDatabaseConnection();
        $this->warnProductionGuardrails();

        Gate::policy(LandLease::class, LandLeasePolicy::class);
        Gate::policy(LandLeaseAccrual::class, LandLeaseAccrualPolicy::class);
    }

    /**
     * Log warnings when production has risky configuration (do not throw).
     */
    private function warnProductionGuardrails(): void
    {
        if (!app()->environment('production')) {
            return;
        }
        if (config('app.debug') === true) {
            Log::warning('Production guardrail: APP_DEBUG=true in production. Set APP_DEBUG=false.');
        }
        if (filter_var(env('DEV_IDENTITY_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
            Log::warning('Production guardrail: DEV_IDENTITY_ENABLED is set in production. Dev identity headers are ignored when APP_ENV=production, but this env should be unset or false.');
        }
        $sessionDomain = env('SESSION_DOMAIN');
        $rootDomain = config('app.root_domain');
        if (!empty($sessionDomain) && (empty($rootDomain) || trim((string) $rootDomain) === '')) {
            Log::warning('Production guardrail: SESSION_DOMAIN is set (subdomain cookie) but APP_ROOT_DOMAIN is missing. Set APP_ROOT_DOMAIN for tenant subdomain resolution.');
        }
    }

    /**
     * Validate critical environment variables.
     */
    private function validateEnvironment(): void
    {
        $required = [
            'APP_ENV' => env('APP_ENV'),
        ];

        $missing = [];
        foreach ($required as $key => $value) {
            if (empty($value)) {
                $missing[] = $key;
            }
        }

        if (!empty($missing)) {
            $message = 'Missing required environment variables: ' . implode(', ', $missing);
            Log::error($message);
            throw new \RuntimeException($message);
        }

        // Validate APP_ENV is a known value
        $validEnvs = ['local', 'staging', 'production', 'testing'];
        if (!in_array(env('APP_ENV'), $validEnvs)) {
            $message = 'Invalid APP_ENV value: ' . env('APP_ENV') . '. Must be one of: ' . implode(', ', $validEnvs);
            Log::error($message);
            throw new \RuntimeException($message);
        }
    }

    /**
     * Validate database connection is available.
     */
    private function validateDatabaseConnection(): void
    {
        try {
            DB::connection()->getPdo();
        } catch (\Exception $e) {
            $message = 'Database connection failed: ' . $e->getMessage();
            Log::error($message);
            throw new \RuntimeException($message . ' Please check your database configuration in .env');
        }
    }
}
