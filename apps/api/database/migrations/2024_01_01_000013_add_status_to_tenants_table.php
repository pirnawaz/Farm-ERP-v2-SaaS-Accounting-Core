<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Create Postgres ENUM type for tenant status
        DB::statement("DO $$ BEGIN
            CREATE TYPE tenant_status AS ENUM ('active', 'suspended');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        // Add status column with default 'active'
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('status')->default('active')->nullable(false)->after('name');
        });

        // Convert existing string column to use ENUM type
        // First, update all existing rows to ensure they have valid status
        DB::statement("UPDATE tenants SET status = 'active' WHERE status IS NULL OR status NOT IN ('active', 'suspended')");

        // Drop the string column and recreate as ENUM
        DB::statement('ALTER TABLE tenants DROP COLUMN status');
        DB::statement("ALTER TABLE tenants ADD COLUMN status tenant_status NOT NULL DEFAULT 'active'");

        // Add index for filtering
        Schema::table('tenants', function (Blueprint $table) {
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        DB::statement('ALTER TABLE tenants DROP COLUMN status');
        DB::statement('DROP TYPE IF EXISTS tenant_status');
    }
};
