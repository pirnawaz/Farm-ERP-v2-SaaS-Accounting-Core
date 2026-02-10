<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Internal machinery service: valuation of machine usage for project settlement.
     * Not real revenue; creates AllocationRows and internal clearing ledger entries only.
     */
    public function up(): void
    {
        Schema::create('machinery_services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('machine_id')->nullable(false);
            $table->uuid('project_id')->nullable(false);
            $table->uuid('rate_card_id')->nullable(false);
            $table->decimal('quantity', 14, 2)->nullable(false);
            $table->decimal('amount', 14, 2)->nullable(); // Set at POST from rate card × quantity
            $table->string('allocation_scope', 32)->nullable(false); // SHARED | HARI_ONLY
            $table->date('posting_date')->nullable();
            $table->string('status', 32)->nullable(false)->default('DRAFT'); // DRAFT | POSTED
            $table->uuid('posting_group_id')->nullable();
            $table->uuid('reversal_posting_group_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('machine_id')->references('id')->on('machines')->restrictOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
            $table->foreign('rate_card_id')->references('id')->on('machine_rate_cards')->restrictOnDelete();
            $table->foreign('posting_group_id')->references('id')->on('posting_groups')->nullOnDelete();
            $table->foreign('reversal_posting_group_id')->references('id')->on('posting_groups')->nullOnDelete();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machinery_services');
    }
};
