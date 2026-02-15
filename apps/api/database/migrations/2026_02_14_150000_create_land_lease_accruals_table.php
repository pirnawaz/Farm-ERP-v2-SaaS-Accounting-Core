<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('land_lease_accruals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('lease_id')->nullable(false);
            $table->uuid('project_id')->nullable(false);
            $table->date('period_start')->nullable(false);
            $table->date('period_end')->nullable(false);
            $table->decimal('amount', 18, 2)->nullable(false);
            $table->text('memo')->nullable();
            $table->string('status', 20)->nullable(false)->default('DRAFT');
            $table->uuid('posting_group_id')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->uuid('posted_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('lease_id')->references('id')->on('land_leases');
            $table->foreign('project_id')->references('id')->on('projects');
            $table->foreign('posted_by')->references('id')->on('users');
            $table->index(['tenant_id']);
            $table->index(['lease_id']);
            $table->index(['project_id']);
            $table->index(['tenant_id', 'lease_id']);
            $table->unique(['tenant_id', 'lease_id', 'period_start', 'period_end'], 'land_lease_accruals_tenant_lease_period_unique');
        });

        DB::statement('ALTER TABLE land_lease_accruals ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE land_lease_accruals ADD CONSTRAINT land_lease_accruals_period_order_check CHECK (period_start <= period_end)');
        DB::statement('ALTER TABLE land_lease_accruals ADD CONSTRAINT land_lease_accruals_amount_non_negative_check CHECK (amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('land_lease_accruals');
    }
};
