<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('project_id')->nullable();
            $table->string('asset_code', 64)->nullable(false);
            $table->string('name')->nullable(false);
            $table->string('category', 128)->nullable(false);
            $table->date('acquisition_date')->nullable(false);
            $table->date('in_service_date')->nullable();
            $table->string('status', 20)->nullable(false)->default('DRAFT');
            $table->char('currency_code', 3)->nullable(false)->default('GBP');
            $table->decimal('acquisition_cost', 18, 2)->nullable(false);
            $table->decimal('residual_value', 18, 2)->nullable(false)->default(0);
            $table->unsignedInteger('useful_life_months')->nullable(false);
            $table->string('depreciation_method', 32)->nullable(false)->default('STRAIGHT_LINE');
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('project_id')->references('id')->on('projects');
            $table->foreign('created_by')->references('id')->on('users');
            $table->unique(['tenant_id', 'asset_code']);
            $table->index(['tenant_id']);
            $table->index(['tenant_id', 'project_id']);
            $table->index(['tenant_id', 'status']);
        });

        DB::statement('ALTER TABLE fixed_assets ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE fixed_assets ADD CONSTRAINT fixed_assets_status_check CHECK (status IN ('DRAFT', 'ACTIVE', 'DISPOSED', 'RETIRED'))");
        DB::statement("ALTER TABLE fixed_assets ADD CONSTRAINT fixed_assets_depreciation_method_check CHECK (depreciation_method IN ('STRAIGHT_LINE'))");
        DB::statement('ALTER TABLE fixed_assets ADD CONSTRAINT fixed_assets_useful_life_months_check CHECK (useful_life_months > 0)');
        DB::statement('ALTER TABLE fixed_assets ADD CONSTRAINT fixed_assets_acquisition_cost_check CHECK (acquisition_cost >= 0)');
        DB::statement('ALTER TABLE fixed_assets ADD CONSTRAINT fixed_assets_residual_value_check CHECK (residual_value >= 0)');

        Schema::create('fixed_asset_books', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('fixed_asset_id')->nullable(false);
            $table->string('book_type', 32)->nullable(false)->default('PRIMARY');
            $table->decimal('asset_cost', 18, 2)->nullable(false);
            $table->decimal('accumulated_depreciation', 18, 2)->nullable(false)->default(0);
            $table->decimal('carrying_amount', 18, 2)->nullable(false);
            $table->date('last_depreciation_date')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('fixed_asset_id')->references('id')->on('fixed_assets');
            $table->unique(['tenant_id', 'fixed_asset_id', 'book_type']);
            $table->index(['tenant_id']);
            $table->index(['tenant_id', 'fixed_asset_id']);
        });

        DB::statement('ALTER TABLE fixed_asset_books ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE fixed_asset_books ADD CONSTRAINT fixed_asset_books_book_type_check CHECK (book_type IN ('PRIMARY'))");
        DB::statement('ALTER TABLE fixed_asset_books ADD CONSTRAINT fixed_asset_books_asset_cost_check CHECK (asset_cost >= 0)');
        DB::statement('ALTER TABLE fixed_asset_books ADD CONSTRAINT fixed_asset_books_accumulated_depreciation_check CHECK (accumulated_depreciation >= 0)');
        DB::statement('ALTER TABLE fixed_asset_books ADD CONSTRAINT fixed_asset_books_carrying_amount_check CHECK (carrying_amount >= 0)');

        Schema::create('fixed_asset_depreciation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->string('reference_no', 64)->nullable(false);
            $table->string('status', 20)->nullable(false)->default('DRAFT');
            $table->date('period_start')->nullable(false);
            $table->date('period_end')->nullable(false);
            $table->date('posting_date')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->uuid('posted_by_user_id')->nullable();
            $table->uuid('posting_group_id')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('posted_by_user_id')->references('id')->on('users');
            $table->foreign('posting_group_id')->references('id')->on('posting_groups');
            $table->unique(['tenant_id', 'reference_no']);
            $table->index(['tenant_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'period_start', 'period_end']);
        });

        DB::statement('ALTER TABLE fixed_asset_depreciation_runs ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE fixed_asset_depreciation_runs ADD CONSTRAINT fixed_asset_depreciation_runs_status_check CHECK (status IN ('DRAFT', 'POSTED', 'VOID'))");
        DB::statement('ALTER TABLE fixed_asset_depreciation_runs ADD CONSTRAINT fixed_asset_depreciation_runs_period_check CHECK (period_start <= period_end)');

        Schema::create('fixed_asset_depreciation_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('depreciation_run_id')->nullable(false);
            $table->uuid('fixed_asset_id')->nullable(false);
            $table->decimal('depreciation_amount', 18, 2)->nullable(false);
            $table->decimal('opening_carrying_amount', 18, 2)->nullable(false);
            $table->decimal('closing_carrying_amount', 18, 2)->nullable(false);
            $table->date('depreciation_start')->nullable(false);
            $table->date('depreciation_end')->nullable(false);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('depreciation_run_id')->references('id')->on('fixed_asset_depreciation_runs')->cascadeOnDelete();
            $table->foreign('fixed_asset_id')->references('id')->on('fixed_assets');
            $table->index(['tenant_id']);
            $table->index(['tenant_id', 'depreciation_run_id']);
            $table->index(['tenant_id', 'fixed_asset_id']);
        });

        DB::statement('ALTER TABLE fixed_asset_depreciation_lines ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE fixed_asset_depreciation_lines ADD CONSTRAINT fixed_asset_depreciation_lines_depreciation_amount_check CHECK (depreciation_amount >= 0)');
        DB::statement('ALTER TABLE fixed_asset_depreciation_lines ADD CONSTRAINT fixed_asset_depreciation_lines_opening_carrying_check CHECK (opening_carrying_amount >= 0)');
        DB::statement('ALTER TABLE fixed_asset_depreciation_lines ADD CONSTRAINT fixed_asset_depreciation_lines_closing_carrying_check CHECK (closing_carrying_amount >= 0)');
        DB::statement('ALTER TABLE fixed_asset_depreciation_lines ADD CONSTRAINT fixed_asset_depreciation_lines_depreciation_period_check CHECK (depreciation_start <= depreciation_end)');

        Schema::create('fixed_asset_disposals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('fixed_asset_id')->nullable(false);
            $table->date('disposal_date')->nullable(false);
            $table->decimal('proceeds_amount', 18, 2)->nullable(false)->default(0);
            $table->string('status', 20)->nullable(false)->default('DRAFT');
            $table->date('posting_date')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->uuid('posted_by_user_id')->nullable();
            $table->uuid('posting_group_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('fixed_asset_id')->references('id')->on('fixed_assets');
            $table->foreign('posted_by_user_id')->references('id')->on('users');
            $table->foreign('posting_group_id')->references('id')->on('posting_groups');
            $table->index(['tenant_id']);
            $table->index(['tenant_id', 'fixed_asset_id']);
            $table->index(['tenant_id', 'status']);
        });

        DB::statement('ALTER TABLE fixed_asset_disposals ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE fixed_asset_disposals ADD CONSTRAINT fixed_asset_disposals_status_check CHECK (status IN ('DRAFT', 'POSTED'))");
        DB::statement('ALTER TABLE fixed_asset_disposals ADD CONSTRAINT fixed_asset_disposals_proceeds_amount_check CHECK (proceeds_amount >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE fixed_asset_disposals DROP CONSTRAINT IF EXISTS fixed_asset_disposals_proceeds_amount_check');
        DB::statement('ALTER TABLE fixed_asset_disposals DROP CONSTRAINT IF EXISTS fixed_asset_disposals_status_check');
        Schema::dropIfExists('fixed_asset_disposals');

        DB::statement('ALTER TABLE fixed_asset_depreciation_lines DROP CONSTRAINT IF EXISTS fixed_asset_depreciation_lines_depreciation_period_check');
        DB::statement('ALTER TABLE fixed_asset_depreciation_lines DROP CONSTRAINT IF EXISTS fixed_asset_depreciation_lines_closing_carrying_check');
        DB::statement('ALTER TABLE fixed_asset_depreciation_lines DROP CONSTRAINT IF EXISTS fixed_asset_depreciation_lines_opening_carrying_check');
        DB::statement('ALTER TABLE fixed_asset_depreciation_lines DROP CONSTRAINT IF EXISTS fixed_asset_depreciation_lines_depreciation_amount_check');
        Schema::dropIfExists('fixed_asset_depreciation_lines');

        DB::statement('ALTER TABLE fixed_asset_depreciation_runs DROP CONSTRAINT IF EXISTS fixed_asset_depreciation_runs_period_check');
        DB::statement('ALTER TABLE fixed_asset_depreciation_runs DROP CONSTRAINT IF EXISTS fixed_asset_depreciation_runs_status_check');
        Schema::dropIfExists('fixed_asset_depreciation_runs');

        DB::statement('ALTER TABLE fixed_asset_books DROP CONSTRAINT IF EXISTS fixed_asset_books_carrying_amount_check');
        DB::statement('ALTER TABLE fixed_asset_books DROP CONSTRAINT IF EXISTS fixed_asset_books_accumulated_depreciation_check');
        DB::statement('ALTER TABLE fixed_asset_books DROP CONSTRAINT IF EXISTS fixed_asset_books_asset_cost_check');
        DB::statement('ALTER TABLE fixed_asset_books DROP CONSTRAINT IF EXISTS fixed_asset_books_book_type_check');
        Schema::dropIfExists('fixed_asset_books');

        DB::statement('ALTER TABLE fixed_assets DROP CONSTRAINT IF EXISTS fixed_assets_residual_value_check');
        DB::statement('ALTER TABLE fixed_assets DROP CONSTRAINT IF EXISTS fixed_assets_acquisition_cost_check');
        DB::statement('ALTER TABLE fixed_assets DROP CONSTRAINT IF EXISTS fixed_assets_useful_life_months_check');
        DB::statement('ALTER TABLE fixed_assets DROP CONSTRAINT IF EXISTS fixed_assets_depreciation_method_check');
        DB::statement('ALTER TABLE fixed_assets DROP CONSTRAINT IF EXISTS fixed_assets_status_check');
        Schema::dropIfExists('fixed_assets');
    }
};
