<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TYPE posting_group_source_type ADD VALUE IF NOT EXISTS 'FX_REVALUATION_RUN'");

        Schema::create('fx_revaluation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->string('reference_no')->nullable(false);
            $table->string('status')->nullable(false);
            $table->date('as_of_date')->nullable(false);
            $table->date('posting_date')->nullable();
            $table->uuid('posting_group_id')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->uuid('posted_by_user_id')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('posting_group_id')->references('id')->on('posting_groups')->nullOnDelete();
            $table->foreign('posted_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->unique(['tenant_id', 'reference_no'], 'fx_revaluation_runs_tenant_reference_unique');
            $table->index(['tenant_id', 'as_of_date']);
            $table->index(['tenant_id', 'status']);
        });

        DB::statement('ALTER TABLE fx_revaluation_runs ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement("ALTER TABLE fx_revaluation_runs ADD CONSTRAINT fx_revaluation_runs_status_check CHECK (status IN ('DRAFT', 'POSTED', 'VOID'))");

        Schema::create('fx_revaluation_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('fx_revaluation_run_id')->nullable(false);
            $table->string('source_type')->nullable(false);
            $table->uuid('source_id')->nullable(false);
            $table->char('currency_code', 3)->nullable(false);
            $table->decimal('original_base_amount', 14, 2)->nullable(false);
            $table->decimal('revalued_base_amount', 14, 2)->nullable(false);
            $table->decimal('delta_amount', 14, 2)->nullable(false);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('fx_revaluation_run_id', 'fx_rev_lines_run_fk')
                ->references('id')->on('fx_revaluation_runs')
                ->cascadeOnDelete();

            $table->index(['tenant_id', 'fx_revaluation_run_id']);
            $table->index(['source_type', 'source_id']);
        });

        DB::statement('ALTER TABLE fx_revaluation_lines ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_revaluation_lines');
        Schema::dropIfExists('fx_revaluation_runs');
    }
};
