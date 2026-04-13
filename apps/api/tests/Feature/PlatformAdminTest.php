<?php

namespace Tests\Feature;

use App\Models\Identity;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Traits\MakesAuthenticatedRequests;

/**
 * Platform admin bootstrap and platform API access (bundled with demo:seed-tenant --with-platform-admin in production).
 */
class PlatformAdminTest extends TestCase
{
    use RefreshDatabase;
    use MakesAuthenticatedRequests;

    public function test_platform_create_admin_command_is_idempotent(): void
    {
        $code1 = Artisan::call('platform:create-admin', [
            'email' => 'platform_matrix@terrava.test',
            'password' => 'Secret1234',
            '--name' => 'Platform Matrix',
        ]);
        $this->assertSame(0, $code1);

        $user = User::whereNull('tenant_id')->where('email', 'platform_matrix@terrava.test')->first();
        $this->assertNotNull($user);
        $this->assertSame('platform_admin', $user->role);

        $code2 = Artisan::call('platform:create-admin', [
            'email' => 'platform_matrix@terrava.test',
            'password' => 'Updated5678',
            '--name' => 'Platform Matrix 2',
        ]);
        $this->assertSame(0, $code2);

        $user->refresh();
        $this->assertSame('Platform Matrix 2', $user->name);
        $this->assertTrue(Hash::check('Updated5678', $user->password));

        $identity = Identity::find($user->identity_id);
        $this->assertNotNull($identity);
        $this->assertTrue($identity->is_platform_admin);
    }

    public function test_platform_login_and_tenant_list_after_create_admin(): void
    {
        Artisan::call('platform:create-admin', [
            'email' => 'pa_list@terrava.test',
            'password' => 'List123456',
            '--name' => 'List User',
        ]);

        Tenant::create(['name' => 'Farm One', 'status' => 'active', 'slug' => 'farm-one']);

        $login = $this->postJson('/api/platform/auth/login', [
            'email' => 'pa_list@terrava.test',
            'password' => 'List123456',
        ]);
        $login->assertStatus(200);
        $login->assertJsonPath('user.role', 'platform_admin');

        $list = $this->withAuthCookieFrom($login)->getJson('/api/platform/tenants');
        $list->assertStatus(200);
        $list->assertJsonStructure(['tenants']);
    }

    public function test_demo_seed_with_platform_admin_runs_successfully(): void
    {
        $this->artisan('demo:seed-tenant', [
            '--tenant-slug' => 'pa-demo-bundle',
            '--with-platform-admin' => true,
        ])->assertExitCode(0);

        $this->assertNotNull(User::whereNull('tenant_id')->where('email', 'pirnawaz_ali@hotmail.com')->first());
        $this->assertNotNull(Tenant::where('slug', 'pa-demo-bundle')->first());
    }
}
