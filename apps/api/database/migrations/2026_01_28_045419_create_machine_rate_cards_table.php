<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create Postgres ENUM types
        DB::statement("DO $$ BEGIN
            CREATE TYPE machine_rate_card_applies_to_mode AS ENUM ('MACHINE', 'MACHINE_TYPE');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        DB::statement("DO $$ BEGIN
            CREATE TYPE machine_rate_card_rate_unit AS ENUM ('HOUR', 'KM', 'JOB');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        DB::statement("DO $$ BEGIN
            CREATE TYPE machine_rate_card_pricing_model AS ENUM ('FIXED', 'COST_PLUS');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        Schema::create('machine_rate_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->string('applies_to_mode')->nullable(false);
            $table->uuid('machine_id')->nullable();
            $table->string('machine_type')->nullable();
            $table->date('effective_from')->nullable(false);
            $table->date('effective_to')->nullable();
            $table->string('rate_unit')->nullable(false);
            $table->string('pricing_model')->nullable(false);
            $table->decimal('base_rate', 14, 2)->nullable(false);
            $table->decimal('cost_plus_percent', 6, 2)->nullable();
            $table->boolean('includes_fuel')->default(true);
            $table->boolean('includes_operator')->default(true);
            $table->boolean('includes_maintenance')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('machine_id')->references('id')->on('machines');
            
            $table->index(['tenant_id']);
            $table->index(['tenant_id', 'applies_to_mode', 'machine_id']);
            $table->index(['tenant_id', 'applies_to_mode', 'machine_type']);
            $table->index(['tenant_id', 'rate_unit']);
        });

        DB::statement('ALTER TABLE machine_rate_cards ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        
        // Convert applies_to_mode to ENUM
        DB::statement('ALTER TABLE machine_rate_cards DROP COLUMN applies_to_mode');
        DB::statement("ALTER TABLE machine_rate_cards ADD COLUMN applies_to_mode machine_rate_card_applies_to_mode NOT NULL");
        
        // Convert rate_unit to ENUM
        DB::statement('ALTER TABLE machine_rate_cards DROP COLUMN rate_unit');
        DB::statement("ALTER TABLE machine_rate_cards ADD COLUMN rate_unit machine_rate_card_rate_unit NOT NULL");
        
        // Convert pricing_model to ENUM
        DB::statement('ALTER TABLE machine_rate_cards DROP COLUMN pricing_model');
        DB::statement("ALTER TABLE machine_rate_cards ADD COLUMN pricing_model machine_rate_card_pricing_model NOT NULL");

        // Add CHECK constraints
        DB::statement("ALTER TABLE machine_rate_cards ADD CONSTRAINT machine_rate_cards_applies_to_machine_check 
            CHECK ((applies_to_mode = 'MACHINE' AND machine_id IS NOT NULL AND machine_type IS NULL) OR 
                   (applies_to_mode = 'MACHINE_TYPE' AND machine_type IS NOT NULL AND machine_id IS NULL))");
        
        DB::statement("ALTER TABLE machine_rate_cards ADD CONSTRAINT machine_rate_cards_effective_dates_check 
            CHECK (effective_to IS NULL OR effective_to >= effective_from)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('machine_rate_cards');
        DB::statement('DROP TYPE IF EXISTS machine_rate_card_pricing_model');
        DB::statement('DROP TYPE IF EXISTS machine_rate_card_rate_unit');
        DB::statement('DROP TYPE IF EXISTS machine_rate_card_applies_to_mode');
    }
};
