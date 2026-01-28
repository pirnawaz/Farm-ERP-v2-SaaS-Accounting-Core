<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('machine_maintenance_job_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('job_id')->nullable(false);
            $table->text('description')->nullable();
            $table->decimal('amount', 14, 2)->nullable(false);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('job_id')->references('id')->on('machine_maintenance_jobs')->onDelete('cascade');
            
            $table->index(['tenant_id']);
            $table->index(['job_id']);
        });

        DB::statement('ALTER TABLE machine_maintenance_job_lines ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('machine_maintenance_job_lines');
    }
};
