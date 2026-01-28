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
        // Create Postgres ENUM type for maintenance job status
        DB::statement("DO $$ BEGIN
            CREATE TYPE machine_maintenance_job_status AS ENUM ('DRAFT', 'POSTED', 'REVERSED');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        Schema::create('machine_maintenance_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->string('job_no')->nullable(false);
            $table->string('status')->nullable(false)->default('DRAFT');
            $table->uuid('machine_id')->nullable(false);
            $table->uuid('maintenance_type_id')->nullable();
            $table->uuid('vendor_party_id')->nullable();
            $table->date('job_date')->nullable(false);
            $table->date('posting_date')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->uuid('posting_group_id')->nullable();
            $table->uuid('reversal_posting_group_id')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('machine_id')->references('id')->on('machines');
            $table->foreign('maintenance_type_id')->references('id')->on('machine_maintenance_types');
            $table->foreign('vendor_party_id')->references('id')->on('parties');
            $table->foreign('posting_group_id')->references('id')->on('posting_groups');
            $table->foreign('reversal_posting_group_id')->references('id')->on('posting_groups');
            
            $table->unique(['tenant_id', 'job_no']);
            $table->index(['tenant_id']);
            $table->index(['status']);
            $table->index(['machine_id']);
            $table->index(['vendor_party_id']);
            $table->index(['job_date']);
        });

        DB::statement('ALTER TABLE machine_maintenance_jobs ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        
        // Convert status to ENUM
        DB::statement('ALTER TABLE machine_maintenance_jobs DROP COLUMN status');
        DB::statement("ALTER TABLE machine_maintenance_jobs ADD COLUMN status machine_maintenance_job_status NOT NULL DEFAULT 'DRAFT'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('machine_maintenance_jobs');
        DB::statement('DROP TYPE IF EXISTS machine_maintenance_job_status');
    }
};
