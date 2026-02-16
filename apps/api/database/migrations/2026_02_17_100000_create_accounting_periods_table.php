<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Accounting periods per tenant: OPEN/CLOSED, auditable close/reopen.
     */
    public function up(): void
    {
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false)->index();
            $table->date('period_start')->nullable(false);
            $table->date('period_end')->nullable(false);
            $table->string('name')->nullable(false);
            $table->string('status', 20)->default('OPEN');
            $table->uuid('closed_by')->nullable();
            $table->timestamptz('closed_at')->nullable();
            $table->uuid('reopened_by')->nullable();
            $table->timestamptz('reopened_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('closed_by')->references('id')->on('users');
            $table->foreign('reopened_by')->references('id')->on('users');
            $table->foreign('created_by')->references('id')->on('users');
            $table->unique(['tenant_id', 'period_start', 'period_end']);
            $table->index(['tenant_id', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};
