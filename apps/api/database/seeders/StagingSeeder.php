<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Bootstrap a single staging tenant + tenant_admin user + required system/module data.
 * Idempotent: safe to run multiple times (creates or updates in place).
 * Use after migrate so farm (tenant) login works with known credentials.
 *
 * Required for app to work after seeding: SystemAccountsSeeder (per-tenant chart),
 * ModulesSeeder (global module catalog; tenant_modules backfill is in migrations).
 */
class StagingSeeder extends Seeder
{
    /** Staging tenant ID for idempotent upsert. */
    private const STAGING_TENANT_ID = '11111111-1111-1111-1111-111111111111';

    /** Known admin email for staging login. */
    public const STAGING_ADMIN_EMAIL = 'admin@staging.local';

    /** Known admin password for staging (local/dev only; change in production). */
    public const STAGING_ADMIN_PASSWORD = 'StagingAdmin1!';

    public function run(): void
    {
        $tenant = Tenant::query()->updateOrCreate(
            ['id' => self::STAGING_TENANT_ID],
            [
                'name' => 'Staging Farm',
                'slug' => 'staging',
                'status' => Tenant::STATUS_ACTIVE,
                'currency_code' => 'PKR',
                'locale' => 'en-PK',
                'timezone' => 'Asia/Karachi',
            ]
        );

        User::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'email' => self::STAGING_ADMIN_EMAIL,
            ],
            [
                'name' => 'Staging Admin',
                'password' => Hash::make(self::STAGING_ADMIN_PASSWORD),
                'role' => 'tenant_admin',
                'is_enabled' => true,
            ]
        );

        $this->call([
            ModulesSeeder::class,
            SystemAccountsSeeder::class,
        ]);

        $this->printSummary($tenant);
    }

    private function printSummary(Tenant $tenant): void
    {
        $this->command->newLine();
        $this->command->info('--- Staging seed complete ---');
        $this->command->line('Tenant: ' . $tenant->name . ' (slug: ' . $tenant->slug . ', id: ' . $tenant->id . ')');
        $this->command->line('Admin: ' . self::STAGING_ADMIN_EMAIL);
        $this->command->line('Password: ' . self::STAGING_ADMIN_PASSWORD . ' (local/dev only)');
        $this->command->newLine();
        $this->command->comment('To log in: select Tenant and use slug "staging" or UUID above. Send header X-Tenant-Slug: staging or X-Tenant-Id: ' . $tenant->id);
        $this->command->newLine();
    }
}
