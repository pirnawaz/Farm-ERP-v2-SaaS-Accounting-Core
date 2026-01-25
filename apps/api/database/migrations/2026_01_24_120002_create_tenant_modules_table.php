<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Create Postgres ENUM type for tenant_module status
        DB::statement("DO $$ BEGIN
            CREATE TYPE tenant_module_status AS ENUM ('ENABLED', 'DISABLED');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        Schema::create('tenant_modules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->unsignedBigInteger('module_id');
            $table->string('status')->default('ENABLED');
            $table->timestampTz('enabled_at')->nullable();
            $table->timestampTz('disabled_at')->nullable();
            $table->uuid('enabled_by_user_id')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('module_id')->references('id')->on('modules')->onDelete('cascade');
            $table->foreign('enabled_by_user_id')->references('id')->on('users')->onDelete('set null');

            $table->unique(['tenant_id', 'module_id']);
            $table->index('tenant_id');
            $table->index('module_id');
            $table->index('status');
        });

        DB::statement('ALTER TABLE tenant_modules ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        // Convert status to use ENUM
        DB::statement('ALTER TABLE tenant_modules DROP COLUMN status');
        DB::statement("ALTER TABLE tenant_modules ADD COLUMN status tenant_module_status NOT NULL DEFAULT 'ENABLED'");

        Schema::table('tenant_modules', function (Blueprint $table) {
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_modules');
        DB::statement('DROP TYPE IF EXISTS tenant_module_status');
    }
};
