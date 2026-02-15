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

        Gate::policy(LandLease::class, LandLeasePolicy::class);
        Gate::policy(LandLeaseAccrual::class, LandLeaseAccrualPolicy::class);
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
