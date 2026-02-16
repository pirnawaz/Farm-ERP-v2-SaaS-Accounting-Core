<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit log for period CREATED / CLOSED / REOPENED.
     */
    public function up(): void
    {
        Schema::create('accounting_period_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false)->index();
            $table->uuid('accounting_period_id')->nullable(false);
            $table->string('event_type', 20)->nullable(false);
            $table->text('notes')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->timestamptz('created_at')->nullable(false);

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('accounting_period_id')->references('id')->on('accounting_periods')->cascadeOnDelete();
            $table->foreign('actor_id')->references('id')->on('users');
            $table->index(['accounting_period_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_period_events');
    }
};
