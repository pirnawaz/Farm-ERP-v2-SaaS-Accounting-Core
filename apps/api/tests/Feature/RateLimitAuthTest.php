<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class RateLimitAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('auth.tenant.login');
        RateLimiter::clear('auth.platform.login');
        RateLimiter::clear('auth.accept-invite');
        RateLimiter::clear('auth.invitations');
    }

    /** @test */
    public function tenant_login_returns_429_after_limit_exceeded(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        User::create([
            'tenant_id' => $tenant->id,
            'name' => 'User',
            'email' => 'u@test.test',
            'password' => Hash::make('secret'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        // Per-email limit is 5/min (stricter than 10/min per IP). Hit it with 5 failed attempts.
        for ($i = 0; $i < 5; $i++) {
            $r = $this->postJson('/api/auth/login', [
                'email' => 'u@test.test',
                'password' => 'wrong',
            ], ['X-Tenant-Id' => $tenant->id]);
            $r->assertStatus(401);
        }

        $response = $this->postJson('/api/auth/login', [
            'email' => 'u@test.test',
            'password' => 'wrong',
        ], ['X-Tenant-Id' => $tenant->id]);

        $response->assertStatus(429);
        $response->assertJsonStructure(['message']);
        $this->assertStringContainsString('Too many attempts', $response->json('message'));
        $this->assertStringContainsString('seconds', $response->json('message'));
    }

    /** @test */
    public function platform_login_returns_429_after_limit_exceeded(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/platform/auth/login', [
                'email' => 'nobody@test.test',
                'password' => 'wrong',
            ])->assertStatus(401);
        }

        $response = $this->postJson('/api/platform/auth/login', [
            'email' => 'nobody@test.test',
            'password' => 'wrong',
        ]);

        $response->assertStatus(429);
        $this->assertStringContainsString('Too many attempts', $response->json('message'));
    }
}
