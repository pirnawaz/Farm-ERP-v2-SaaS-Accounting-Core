<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Produce-share buckets for harvest (draft + post snapshot). Posting logic is Phase 3C.
     *
     * Remainder uniqueness: at most one row with remainder_bucket=true per (harvest_id, harvest_line_id).
     * Enforced in DB when harvest_line_id is set; service must enforce when harvest_line_id is null.
     *
     * @see docs/phase-3-implementation-plan-harvest-share.md
     */
    public function up(): void
    {
        Schema::create('harvest_share_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('harvest_id')->nullable(false);
            /** Ties bucket to a physical harvest line (recommended for per-line qty splits). */
            $table->uuid('harvest_line_id')->nullable();

            $table->string('recipient_role', 32)->nullable(false);
            $table->string('settlement_mode', 32)->nullable(false);
            $table->uuid('beneficiary_party_id')->nullable();
            $table->uuid('machine_id')->nullable();
            $table->uuid('worker_id')->nullable();
            $table->uuid('source_field_job_id')->nullable();
            $table->uuid('source_lab_work_log_id')->nullable();
            $table->uuid('source_machinery_charge_id')->nullable();
            $table->uuid('source_settlement_id')->nullable();
            $table->uuid('inventory_item_id')->nullable();
            $table->uuid('store_id')->nullable();

            $table->string('share_basis', 32)->nullable(false);
            $table->decimal('share_value', 18, 6)->nullable();
            $table->decimal('ratio_numerator', 18, 6)->nullable();
            $table->decimal('ratio_denominator', 18, 6)->nullable();
            $table->decimal('computed_qty', 18, 3)->nullable();
            $table->decimal('computed_unit_cost_snapshot', 18, 6)->nullable();
            $table->decimal('computed_value_snapshot', 18, 2)->nullable();

            $table->boolean('remainder_bucket')->default(false);
            $table->integer('sort_order')->default(0);
            $table->jsonb('rule_snapshot')->nullable();
            $table->text('notes')->nullable();

            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('harvest_id')->references('id')->on('harvests')->onDelete('cascade');
            $table->foreign('harvest_line_id')->references('id')->on('harvest_lines')->onDelete('cascade');
            $table->foreign('beneficiary_party_id')->references('id')->on('parties');
            $table->foreign('machine_id')->references('id')->on('machines');
            $table->foreign('worker_id')->references('id')->on('lab_workers');
            $table->foreign('source_field_job_id')->references('id')->on('field_jobs');
            $table->foreign('source_lab_work_log_id')->references('id')->on('lab_work_logs');
            $table->foreign('source_machinery_charge_id')->references('id')->on('machinery_charges');
            $table->foreign('source_settlement_id')->references('id')->on('settlements');
            $table->foreign('inventory_item_id')->references('id')->on('inv_items');
            $table->foreign('store_id')->references('id')->on('inv_stores');

            $table->index(['tenant_id', 'harvest_id']);
            $table->index(['tenant_id', 'recipient_role']);
            $table->index(['tenant_id', 'settlement_mode']);
            $table->index(['harvest_id', 'sort_order']);
        });

        DB::statement('ALTER TABLE harvest_share_lines ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement("ALTER TABLE harvest_share_lines ADD CONSTRAINT harvest_share_lines_recipient_role_check CHECK (recipient_role IN ('OWNER', 'MACHINE', 'LABOUR', 'LANDLORD', 'CONTRACTOR'))");
        DB::statement("ALTER TABLE harvest_share_lines ADD CONSTRAINT harvest_share_lines_settlement_mode_check CHECK (settlement_mode IN ('IN_KIND', 'CASH'))");
        DB::statement("ALTER TABLE harvest_share_lines ADD CONSTRAINT harvest_share_lines_share_basis_check CHECK (share_basis IN ('FIXED_QTY', 'PERCENT', 'RATIO', 'REMAINDER'))");

        // At most one remainder flag per harvest line (when line is set).
        DB::statement('CREATE UNIQUE INDEX harvest_share_lines_one_remainder_per_line ON harvest_share_lines (harvest_id, harvest_line_id) WHERE remainder_bucket = true AND harvest_line_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS harvest_share_lines_one_remainder_per_line');
        Schema::dropIfExists('harvest_share_lines');
    }
};
