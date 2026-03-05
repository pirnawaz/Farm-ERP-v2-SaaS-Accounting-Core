<?php

namespace Tests\Feature;

use Database\Seeders\StagingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StagingSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_staging_seeder_creates_tenant_and_admin_user(): void
    {
        $this->seed(StagingSeeder::class);

        $tenant = \App\Models\Tenant::where('slug', 'staging')->first();
        $this->assertNotNull($tenant);
        $this->assertSame('staging', $tenant->slug);
        $this->assertSame('Staging Farm', $tenant->name);
        $this->assertSame('active', $tenant->status);
        $this->assertSame('11111111-1111-1111-1111-111111111111', $tenant->id);

        $user = \App\Models\User::where('tenant_id', $tenant->id)
            ->where('email', StagingSeeder::STAGING_ADMIN_EMAIL)
            ->first();
        $this->assertNotNull($user);
        $this->assertSame('tenant_admin', $user->role);
        $this->assertTrue($user->is_enabled);
        $this->assertTrue(Hash::check(StagingSeeder::STAGING_ADMIN_PASSWORD, $user->password));
    }

    public function test_staging_seeder_is_idempotent(): void
    {
        $this->seed(StagingSeeder::class);
        $this->seed(StagingSeeder::class);

        $tenant = \App\Models\Tenant::where('slug', 'staging')->first();
        $this->assertNotNull($tenant);
        $userCount = \App\Models\User::where('tenant_id', $tenant->id)
            ->where('email', StagingSeeder::STAGING_ADMIN_EMAIL)
            ->count();
        $this->assertSame(1, $userCount);
    }
}
