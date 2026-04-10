<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive columns for Phase 2 machinery costing snapshots (posting logic comes later).
     *
     * Note: rate_snapshot, amount, and source_charge_id (→ machinery_charges) already exist on field_job_machines.
     */
    public function up(): void
    {
        Schema::table('field_job_machines', function (Blueprint $table) {
            $table->string('pricing_basis', 32)->nullable()->after('meter_unit_snapshot');
            $table->uuid('rate_card_id')->nullable()->after('rate_snapshot');

            $table->foreign('rate_card_id')->references('id')->on('machine_rate_cards')->nullOnDelete();
            $table->index(['tenant_id', 'rate_card_id'], 'field_job_machines_tenant_rate_card_idx');
        });
    }

    public function down(): void
    {
        Schema::table('field_job_machines', function (Blueprint $table) {
            $table->dropForeign(['rate_card_id']);
            $table->dropIndex('field_job_machines_tenant_rate_card_idx');
            $table->dropColumn(['pricing_basis', 'rate_card_id']);
        });
    }
};
