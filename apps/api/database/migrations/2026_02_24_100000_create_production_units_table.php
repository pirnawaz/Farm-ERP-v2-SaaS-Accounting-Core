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
            CREATE TYPE production_unit_type AS ENUM ('SEASONAL', 'LONG_CYCLE');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        DB::statement("DO $$ BEGIN
            CREATE TYPE production_unit_status AS ENUM ('ACTIVE', 'CLOSED');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        Schema::create('production_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->string('name')->nullable(false);
            $table->string('type')->nullable(false)->default('SEASONAL');
            $table->date('start_date')->nullable(false);
            $table->date('end_date')->nullable();
            $table->string('status')->nullable(false)->default('ACTIVE');
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->index(['tenant_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'type']);
        });

        DB::statement('ALTER TABLE production_units ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE production_units DROP COLUMN type");
        DB::statement("ALTER TABLE production_units ADD COLUMN type production_unit_type NOT NULL DEFAULT 'SEASONAL'");
        DB::statement("ALTER TABLE production_units DROP COLUMN status");
        DB::statement("ALTER TABLE production_units ADD COLUMN status production_unit_status NOT NULL DEFAULT 'ACTIVE'");
    }

    public function down(): void
    {
        Schema::dropIfExists('production_units');
        DB::statement('DROP TYPE IF EXISTS production_unit_status');
        DB::statement('DROP TYPE IF EXISTS production_unit_type');
    }
};
