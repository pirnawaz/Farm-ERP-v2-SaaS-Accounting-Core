<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Create Postgres ENUM type for sale status
        DB::statement("DO $$ BEGIN
            CREATE TYPE sale_status AS ENUM ('DRAFT', 'POSTED');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        Schema::create('sales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('buyer_party_id')->nullable(false);
            $table->uuid('project_id')->nullable();
            $table->uuid('crop_cycle_id')->nullable();
            $table->decimal('amount', 12, 2)->nullable(false);
            $table->date('posting_date')->nullable(false);
            $table->string('status')->nullable(false)->default('DRAFT');
            $table->uuid('posting_group_id')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->timestampTz('created_at')->nullable(false)->useCurrent();
            
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('buyer_party_id')->references('id')->on('parties');
            $table->foreign('posting_group_id')->references('id')->on('posting_groups');
            $table->foreign('project_id')->references('id')->on('projects');
            $table->foreign('crop_cycle_id')->references('id')->on('crop_cycles');
            
            $table->index(['tenant_id']);
            $table->index(['buyer_party_id']);
            $table->index(['status']);
            $table->index(['posting_date']);
            $table->index(['tenant_id', 'buyer_party_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'project_id']);
        });
        
        DB::statement('ALTER TABLE sales ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        
        // Convert status to ENUM
        DB::statement('ALTER TABLE sales DROP COLUMN status');
        DB::statement("ALTER TABLE sales ADD COLUMN status sale_status NOT NULL DEFAULT 'DRAFT'");
        
        // Add CHECK constraint: amount must be greater than 0
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_amount_check CHECK (amount > 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sales DROP CONSTRAINT IF EXISTS sales_amount_check');
        
        DB::statement('ALTER TABLE sales DROP COLUMN status');
        DB::statement("ALTER TABLE sales ADD COLUMN status VARCHAR(255) NOT NULL DEFAULT 'DRAFT'");
        
        Schema::dropIfExists('sales');
        
        DB::statement('DROP TYPE IF EXISTS sale_status');
    }
};
