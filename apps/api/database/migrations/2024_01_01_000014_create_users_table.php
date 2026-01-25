<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Create Postgres ENUM type for user role
        DB::statement("DO $$ BEGIN
            CREATE TYPE user_role AS ENUM ('tenant_admin', 'accountant', 'operator');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->string('name')->nullable(false);
            $table->string('email')->nullable(false);
            $table->string('role')->nullable(false);
            $table->timestampTz('created_at')->nullable(false)->useCurrent();
            
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['tenant_id', 'email']);
            $table->index(['tenant_id']);
            $table->index(['tenant_id', 'email']);
        });
        
        DB::statement('ALTER TABLE users ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        
        // Convert role column to use ENUM type
        DB::statement('ALTER TABLE users DROP COLUMN role');
        DB::statement('ALTER TABLE users ADD COLUMN role user_role NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        DB::statement('DROP TYPE IF EXISTS user_role');
    }
};
