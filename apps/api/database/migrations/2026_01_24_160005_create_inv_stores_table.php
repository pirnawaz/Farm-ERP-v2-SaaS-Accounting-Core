<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("DO $$ BEGIN
            CREATE TYPE inv_store_type AS ENUM ('MAIN', 'FIELD', 'OTHER');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        Schema::create('inv_stores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->string('name')->nullable(false);
            $table->string('type')->nullable(false)->default('MAIN');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id']);
        });

        DB::statement('ALTER TABLE inv_stores ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE inv_stores DROP COLUMN type');
        DB::statement("ALTER TABLE inv_stores ADD COLUMN type inv_store_type NOT NULL DEFAULT 'MAIN'::inv_store_type");
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_stores');
        DB::statement('DROP TYPE IF EXISTS inv_store_type');
    }
};
