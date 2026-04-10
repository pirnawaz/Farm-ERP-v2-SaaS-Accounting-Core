<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Formal agreements for output/cost sharing (Phase 6B.1 — schema only).
 *
 * @see docs/PHASE_6A_1_AGREEMENTS_ENGINE_DESIGN.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('agreement_type', 64);

            $table->uuid('project_id')->nullable();
            $table->uuid('crop_cycle_id')->nullable();
            $table->uuid('party_id')->nullable();
            $table->uuid('machine_id')->nullable();
            $table->uuid('worker_id')->nullable();

            $table->jsonb('terms')->nullable();

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->integer('priority')->default(0);

            $table->string('status', 32)->default('ACTIVE');

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
            $table->foreign('crop_cycle_id')->references('id')->on('crop_cycles')->nullOnDelete();
            $table->foreign('party_id')->references('id')->on('parties')->nullOnDelete();
            $table->foreign('machine_id')->references('id')->on('machines')->nullOnDelete();
            $table->foreign('worker_id')->references('id')->on('lab_workers')->nullOnDelete();

            $table->index(['tenant_id', 'project_id']);
            $table->index(['tenant_id', 'crop_cycle_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'agreement_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agreements');
    }
};
