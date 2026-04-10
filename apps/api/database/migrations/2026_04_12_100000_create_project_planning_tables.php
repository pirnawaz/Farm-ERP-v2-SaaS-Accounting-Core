<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8B.1 — Planning schema (no ledger/posting coupling).
 *
 * @see docs/PHASE_8A_1_PLANNING_FORECASTING_DESIGN.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('project_id');
            $table->uuid('crop_cycle_id');
            $table->string('name');
            $table->string('status', 32)->default('DRAFT');

            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('crop_cycle_id')->references('id')->on('crop_cycles')->restrictOnDelete();

            $table->index(['tenant_id', 'project_id']);
            $table->index(['tenant_id', 'crop_cycle_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('project_plan_costs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_plan_id');
            $table->string('cost_type', 32);
            $table->decimal('expected_quantity', 18, 4)->nullable();
            $table->decimal('expected_cost', 18, 2)->nullable();

            $table->timestampsTz();

            $table->foreign('project_plan_id')->references('id')->on('project_plans')->cascadeOnDelete();

            $table->index(['project_plan_id', 'cost_type']);
        });

        Schema::create('project_plan_yields', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_plan_id');
            $table->decimal('expected_quantity', 18, 4)->nullable();
            $table->decimal('expected_unit_value', 18, 4)->nullable();

            $table->timestampsTz();

            $table->foreign('project_plan_id')->references('id')->on('project_plans')->cascadeOnDelete();

            $table->index('project_plan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_plan_yields');
        Schema::dropIfExists('project_plan_costs');
        Schema::dropIfExists('project_plans');
    }
};
