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
        // Reuse machine_rate_card_rate_unit enum (HOUR, KM, JOB)
        // Ensure it exists (created in create_machine_rate_cards_table migration)
        DB::statement("DO $$ BEGIN
            CREATE TYPE machine_rate_card_rate_unit AS ENUM ('HOUR', 'KM', 'JOB');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        Schema::create('machinery_charge_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('machinery_charge_id')->nullable(false);
            $table->uuid('machine_work_log_id')->nullable(false);
            $table->decimal('usage_qty', 12, 2)->nullable(false);
            $table->string('unit')->nullable(false);
            $table->decimal('rate', 14, 2)->nullable(false);
            $table->decimal('amount', 14, 2)->nullable(false);
            $table->uuid('rate_card_id')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('machinery_charge_id')->references('id')->on('machinery_charges')->cascadeOnDelete();
            $table->foreign('machine_work_log_id')->references('id')->on('machine_work_logs');
            $table->foreign('rate_card_id')->references('id')->on('machine_rate_cards');
            
            $table->index(['tenant_id']);
            $table->index(['machinery_charge_id']);
            $table->index(['machine_work_log_id']);
        });

        DB::statement('ALTER TABLE machinery_charge_lines ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        
        // Convert unit to ENUM (reuse machine_rate_card_rate_unit)
        DB::statement('ALTER TABLE machinery_charge_lines DROP COLUMN unit');
        DB::statement("ALTER TABLE machinery_charge_lines ADD COLUMN unit machine_rate_card_rate_unit NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('machinery_charge_lines');
        // Note: machine_rate_card_rate_unit enum is not dropped as it's used by machine_rate_cards
    }
};
