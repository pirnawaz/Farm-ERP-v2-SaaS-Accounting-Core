<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Traits\MakesAuthenticatedRequests;

class UserInvitationTest extends TestCase
{
    use RefreshDatabase;
    use MakesAuthenticatedRequests;

    private function makeAuthCookie(string $userId, string $tenantId, string $role): string
    {
        return base64_encode(json_encode([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'role' => $role,
            'email' => 'admin@test.test',
            'expires_at' => now()->addDays(7)->timestamp,
        ]));
    }

    /** @test */
    public function tenant_admin_can_create_invitation_and_accept_creates_user(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $admin = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@test.test',
            'password' => Hash::make('secret'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        $login = $this->withHeader('X-Tenant-Id', $tenant->id)->postJson('/api/auth/login', [
            'email' => 'admin@test.test',
            'password' => 'secret',
        ]);
        $login->assertStatus(200);

        $response = $this->withAuthCookieFrom($login)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->postJson('/api/tenant/invitations', [
                'email' => 'newuser@test.test',
                'role' => 'accountant',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('expires_in_hours', 168);
        $inviteLink = $response->json('invite_link');
        $this->assertStringContainsString('token=', $inviteLink);
        parse_str(parse_url($inviteLink, PHP_URL_QUERY) ?: '', $params);
        $token = $params['token'] ?? '';
        $this->assertNotEmpty($token);

        $accept = $this->postJson('/api/auth/accept-invite', [
            'token' => $token,
            'name' => 'New User',
            'new_password' => 'password123',
        ]);

        $accept->assertStatus(200);
        $accept->assertJsonPath('user.email', 'newuser@test.test');
        $accept->assertJsonPath('user.role', 'accountant');
        $accept->assertJsonPath('tenant.id', $tenant->id);

        $this->assertDatabaseHas('users', [
            'tenant_id' => $tenant->id,
            'email' => 'newuser@test.test',
            'role' => 'accountant',
        ]);
        $this->assertDatabaseMissing('user_invitations', ['email' => 'newuser@test.test']);
    }

    /** @test */
    public function expired_token_returns_400(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $admin = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'a@t.test',
            'password' => Hash::make('x'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        $token = UserInvitation::createInvitation($tenant->id, 'u@test.test', 'operator', $admin->id, -1);

        $response = $this->postJson('/api/auth/accept-invite', [
            'token' => $token,
            'name' => 'User',
            'new_password' => 'password123',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('error', 'Invalid or expired invitation token');
    }

    /** @test */
    public function token_reuse_returns_400(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $admin = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@test.test',
            'password' => Hash::make('secret'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        $token = UserInvitation::createInvitation($tenant->id, 'once@test.test', 'operator', $admin->id, 24);

        $first = $this->postJson('/api/auth/accept-invite', [
            'token' => $token,
            'name' => 'User',
            'new_password' => 'password123',
        ]);
        $first->assertStatus(200);

        $second = $this->postJson('/api/auth/accept-invite', [
            'token' => $token,
            'name' => 'User',
            'new_password' => 'password123',
        ]);
        $second->assertStatus(400);
    }
}
