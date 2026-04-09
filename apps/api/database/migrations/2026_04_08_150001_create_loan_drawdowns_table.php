<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_drawdowns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('project_id')->nullable(false);
            $table->uuid('loan_agreement_id')->nullable(false);
            $table->date('drawdown_date')->nullable(false);
            $table->decimal('amount', 18, 2)->nullable(false);
            $table->string('reference_no', 64)->nullable();
            $table->string('status', 20)->nullable(false)->default('DRAFT');
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('project_id')->references('id')->on('projects');
            $table->foreign('loan_agreement_id')->references('id')->on('loan_agreements');
            $table->foreign('created_by')->references('id')->on('users');
            $table->index(['tenant_id']);
            $table->index(['tenant_id', 'project_id']);
            $table->index(['tenant_id', 'loan_agreement_id']);
            $table->index(['loan_agreement_id']);
            $table->index(['status']);
        });

        DB::statement('ALTER TABLE loan_drawdowns ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE loan_drawdowns ADD CONSTRAINT loan_drawdowns_status_check CHECK (status IN ('DRAFT', 'ACTIVE', 'POSTED', 'CLOSED'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE loan_drawdowns DROP CONSTRAINT IF EXISTS loan_drawdowns_status_check');
        Schema::dropIfExists('loan_drawdowns');
    }
};
