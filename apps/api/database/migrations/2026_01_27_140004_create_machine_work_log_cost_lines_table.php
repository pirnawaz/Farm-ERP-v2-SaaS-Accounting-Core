<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_work_log_cost_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('machine_work_log_id')->nullable(false);
            $table->string('cost_code')->nullable(false);
            $table->string('description')->nullable();
            $table->decimal('amount', 14, 2)->nullable(false);
            $table->uuid('party_id')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('machine_work_log_id')->references('id')->on('machine_work_logs')->cascadeOnDelete();
            $table->foreign('party_id')->references('id')->on('parties');
            
            $table->index(['tenant_id']);
            $table->index(['machine_work_log_id']);
            $table->index(['tenant_id', 'machine_work_log_id']);
        });

        DB::statement('ALTER TABLE machine_work_log_cost_lines ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_work_log_cost_lines');
    }
};
