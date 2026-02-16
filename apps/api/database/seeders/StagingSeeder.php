<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class StagingSeeder extends Seeder
{
    /** Staging tenant ID for idempotent upsert. */
    private const STAGING_TENANT_ID = '11111111-1111-1111-1111-111111111111';

    /** Known admin email for staging login. */
    public const STAGING_ADMIN_EMAIL = 'admin@staging.local';

    /** Known admin password for staging (change in production). */
    public const STAGING_ADMIN_PASSWORD = 'StagingAdmin1!';

    public function run(): void
    {
        $tenant = Tenant::query()->updateOrCreate(
            ['id' => self::STAGING_TENANT_ID],
            [
                'name' => 'Staging',
                'status' => Tenant::STATUS_ACTIVE,
                'currency_code' => 'GBP',
                'locale' => 'en-GB',
                'timezone' => 'Europe/London',
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
            SystemAccountsSeeder::class,
            ModulesSeeder::class,
        ]);
    }
}
