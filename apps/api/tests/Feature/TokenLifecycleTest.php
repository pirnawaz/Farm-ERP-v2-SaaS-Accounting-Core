<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Traits\MakesAuthenticatedRequests;

class TokenLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use MakesAuthenticatedRequests;

    /** @test */
    public function expired_token_rejected(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'User',
            'email' => 'u@test.test',
            'password' => Hash::make('secret'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        $payload = [
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role' => $user->role,
            'email' => $user->email,
            'expires_at' => now()->subHour()->timestamp,
        ];
        $expiredToken = base64_encode(json_encode($payload));

        $response = $this->withAuthCookie($expiredToken)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->getJson('/api/auth/me');

        $response->assertStatus(401);
        $response->assertJsonPath('error', 'Token expired or invalid');
    }

    /** @test */
    public function logout_all_invalidates_old_token(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'User',
            'email' => 'u@test.test',
            'password' => Hash::make('secret'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        $login = $this->withHeader('X-Tenant-Id', $tenant->id)->postJson('/api/auth/login', [
            'email' => 'u@test.test',
            'password' => 'secret',
        ]);

        $login->assertStatus(200);
        $token = $login->json('token');
        $this->assertNotEmpty($token);

        $logoutAll = $this->withAuthCookieFrom($login)->withHeader('X-Tenant-Id', $tenant->id)
            ->postJson('/api/auth/logout-all');
        $logoutAll->assertStatus(200);

        $me = $this->withAuthCookie($token)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->getJson('/api/auth/me');
        $me->assertStatus(401);
        $me->assertJsonPath('error', 'Session invalidated. Please log in again.');
    }

    /** @test */
    public function change_password_invalidates_old_sessions(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'User',
            'email' => 'u@test.test',
            'password' => Hash::make('oldsecret'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        $login = $this->withHeader('X-Tenant-Id', $tenant->id)->postJson('/api/auth/login', [
            'email' => 'u@test.test',
            'password' => 'oldsecret',
        ]);
        $login->assertStatus(200);
        $oldToken = $login->json('token');

        $change = $this->withAuthCookieFrom($login)->withHeader('X-Tenant-Id', $tenant->id)
            ->postJson('/api/auth/change-password', [
                'current_password' => 'oldsecret',
                'new_password' => 'newsecret123',
                'new_password_confirmation' => 'newsecret123',
            ]);
        $change->assertStatus(200);

        $meWithOldToken = $this->withAuthCookie($oldToken)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->getJson('/api/auth/me');
        $meWithOldToken->assertStatus(401);
    }
}
