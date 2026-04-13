<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ModulesSeeder::class);
        // Demo tenant + platform admin: php artisan demo:seed-tenant --with-platform-admin
    }
}
