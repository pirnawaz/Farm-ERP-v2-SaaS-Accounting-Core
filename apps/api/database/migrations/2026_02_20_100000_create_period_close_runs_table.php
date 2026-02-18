<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Period close runs: one per crop cycle (idempotent close).
     * Stores closing posting group reference, net profit, and audit snapshot.
     */
    public function up(): void
    {
        Schema::create('period_close_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('crop_cycle_id')->index();
            $table->uuid('posting_group_id')->nullable()->index();
            $table->string('status', 32)->default('COMPLETED');
            $table->timestamp('closed_at')->nullable();
            $table->uuid('closed_by_user_id')->nullable();
            $table->date('from_date');
            $table->date('to_date');
            $table->decimal('net_profit', 18, 2)->default(0);
            $table->json('snapshot_json')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'crop_cycle_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('crop_cycle_id')->references('id')->on('crop_cycles')->cascadeOnDelete();
            $table->foreign('posting_group_id')->references('id')->on('posting_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_close_runs');
    }
};
