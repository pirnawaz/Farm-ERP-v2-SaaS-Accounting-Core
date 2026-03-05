<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the composite unique so we can make tenant_id nullable and add partial uniques
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'email']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->uuid('tenant_id')->nullable()->change();
        });

        // Tenant users: unique (tenant_id, email) where tenant_id IS NOT NULL
        DB::statement('CREATE UNIQUE INDEX users_tenant_id_email_unique ON users (tenant_id, email) WHERE tenant_id IS NOT NULL');

        // Platform admins: unique email where tenant_id IS NULL
        DB::statement('CREATE UNIQUE INDEX users_email_platform_unique ON users (email) WHERE tenant_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_tenant_id_email_unique');
        DB::statement('DROP INDEX IF EXISTS users_email_platform_unique');

        Schema::table('users', function (Blueprint $table) {
            $table->uuid('tenant_id')->nullable(false)->change();
            $table->unique(['tenant_id', 'email']);
        });
    }
};
