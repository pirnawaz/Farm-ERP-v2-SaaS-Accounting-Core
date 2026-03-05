<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configurePasswordValidation();
    }

    protected function configurePasswordValidation(): void
    {
        $minLength = (int) config('auth.password_min_length', 10);
        Password::defaults(fn () => Password::min($minLength));
    }

    protected function configureRateLimiting(): void
    {
        $loginPerMinute = (int) env('RATE_LIMIT_LOGIN_PER_MINUTE', 10);
        $loginPerEmailPerMinute = (int) env('RATE_LIMIT_LOGIN_PER_EMAIL_PER_MINUTE', 5);
        $acceptInvitePerMinute = (int) env('RATE_LIMIT_ACCEPT_INVITE_PER_MINUTE', 10);
        $invitationsPerHour = (int) env('RATE_LIMIT_INVITATIONS_PER_HOUR', 30);
        $invitationsPerUserPerHour = (int) env('RATE_LIMIT_INVITATIONS_PER_USER_PER_HOUR', 10);

        RateLimiter::for('auth.platform.login', function (Request $request) use ($loginPerMinute) {
            return Limit::perMinute($loginPerMinute)->by($request->ip());
        });

        RateLimiter::for('auth.tenant.login', function (Request $request) use ($loginPerMinute, $loginPerEmailPerMinute) {
            $email = $request->input('email');
            $by = $email ? $request->ip() . ':' . $email : $request->ip();
            return [
                Limit::perMinute($loginPerMinute)->by($request->ip()),
                Limit::perMinute($loginPerEmailPerMinute)->by($by),
            ];
        });

        RateLimiter::for('auth.accept-invite', function (Request $request) use ($acceptInvitePerMinute) {
            return Limit::perMinute($acceptInvitePerMinute)->by($request->ip());
        });

        RateLimiter::for('auth.invitations', function (Request $request) use ($invitationsPerHour, $invitationsPerUserPerHour) {
            $tenantId = $request->attributes->get('tenant_id') ?? 'global';
            $userId = $request->attributes->get('user_id') ?? $request->ip();
            return [
                Limit::perHour($invitationsPerHour)->by('tenant:' . $tenantId),
                Limit::perHour($invitationsPerUserPerHour)->by('user:' . $userId),
            ];
        });

        RateLimiter::for('auth.platform.invitations', function (Request $request) use ($invitationsPerHour, $invitationsPerUserPerHour) {
            $tenantId = $request->route('tenant') ?? 'global';
            $userId = $request->header('X-User-Id') ?? $request->ip();
            return [
                Limit::perHour($invitationsPerHour)->by('tenant:' . $tenantId),
                Limit::perHour($invitationsPerUserPerHour)->by('user:' . $userId),
            ];
        });

        $manualUserPerTenantPerHour = (int) env('RATE_LIMIT_MANUAL_USER_PER_TENANT_PER_HOUR', 20);
        $manualUserPerActorPerHour = (int) env('RATE_LIMIT_MANUAL_USER_PER_ACTOR_PER_HOUR', 10);
        RateLimiter::for('auth.manual_user_create', function (Request $request) use ($manualUserPerTenantPerHour, $manualUserPerActorPerHour) {
            $tenantId = $request->attributes->get('tenant_id') ?? $request->route('tenant') ?? 'global';
            $userId = $request->attributes->get('user_id') ?? $request->header('X-User-Id') ?? $request->ip();
            return [
                Limit::perHour($manualUserPerTenantPerHour)->by('tenant:' . $tenantId),
                Limit::perHour($manualUserPerActorPerHour)->by('user:' . $userId),
            ];
        });

        $platformUserUpdatePerHour = (int) env('RATE_LIMIT_PLATFORM_USER_UPDATE_PER_HOUR', 60);
        RateLimiter::for('auth.platform_user_update', function (Request $request) use ($platformUserUpdatePerHour) {
            $userId = $request->header('X-User-Id') ?? $request->ip();
            return Limit::perHour($platformUserUpdatePerHour)->by('user:' . $userId);
        });
    }
}
