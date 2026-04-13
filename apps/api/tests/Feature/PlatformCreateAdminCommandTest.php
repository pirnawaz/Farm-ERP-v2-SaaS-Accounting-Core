<?php

namespace Tests\Feature;

use App\Models\Identity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlatformCreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_create_admin_is_idempotent_and_syncs_identity(): void
    {
        $code1 = Artisan::call('platform:create-admin', [
            'email' => 'pa_demo@example.com',
            'password' => 'Secret1234',
            '--name' => 'Platform Demo',
        ]);
        $this->assertSame(0, $code1);

        $user = User::whereNull('tenant_id')->where('email', 'pa_demo@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('platform_admin', $user->role);
        $this->assertNotNull($user->identity_id);

        $identity = Identity::find($user->identity_id);
        $this->assertNotNull($identity);
        $this->assertTrue($identity->is_platform_admin);
        $this->assertTrue(Hash::check('Secret1234', $identity->password_hash));

        $code2 = Artisan::call('platform:create-admin', [
            'email' => 'pa_demo@example.com',
            'password' => 'Updated5678',
            '--name' => 'Platform Updated',
        ]);
        $this->assertSame(0, $code2);

        $user->refresh();
        $identity->refresh();
        $this->assertSame('Platform Updated', $user->name);
        $this->assertTrue(Hash::check('Updated5678', $user->password));
        $this->assertTrue(Hash::check('Updated5678', $identity->password_hash));

        $this->assertSame(1, User::whereNull('tenant_id')->where('email', 'pa_demo@example.com')->count());
    }

    public function test_platform_auth_login_succeeds_after_command(): void
    {
        Artisan::call('platform:create-admin', [
            'email' => 'pa_login@example.com',
            'password' => 'Login12345',
            '--name' => 'Login Test',
        ]);

        $response = $this->postJson('/api/platform/auth/login', [
            'email' => 'pa_login@example.com',
            'password' => 'Login12345',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('user.role', 'platform_admin');
    }
}
