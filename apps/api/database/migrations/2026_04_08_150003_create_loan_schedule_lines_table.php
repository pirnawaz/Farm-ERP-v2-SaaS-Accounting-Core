<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_schedule_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('project_id')->nullable(false);
            $table->uuid('loan_agreement_id')->nullable(false);
            $table->unsignedInteger('line_number')->nullable(false);
            $table->date('due_date')->nullable(false);
            $table->decimal('principal_amount', 18, 2)->nullable(false)->default(0);
            $table->decimal('interest_amount', 18, 2)->nullable(false)->default(0);
            $table->decimal('total_amount', 18, 2)->nullable(false)->default(0);
            $table->string('status', 20)->nullable(false)->default('DRAFT');
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('project_id')->references('id')->on('projects');
            $table->foreign('loan_agreement_id')->references('id')->on('loan_agreements');
            $table->foreign('created_by')->references('id')->on('users');
            $table->unique(['loan_agreement_id', 'line_number']);
            $table->index(['tenant_id']);
            $table->index(['tenant_id', 'project_id']);
            $table->index(['tenant_id', 'loan_agreement_id']);
            $table->index(['loan_agreement_id']);
            $table->index(['due_date']);
            $table->index(['status']);
        });

        DB::statement('ALTER TABLE loan_schedule_lines ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE loan_schedule_lines ADD CONSTRAINT loan_schedule_lines_status_check CHECK (status IN ('DRAFT', 'ACTIVE', 'POSTED', 'CLOSED'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE loan_schedule_lines DROP CONSTRAINT IF EXISTS loan_schedule_lines_status_check');
        Schema::dropIfExists('loan_schedule_lines');
    }
};
