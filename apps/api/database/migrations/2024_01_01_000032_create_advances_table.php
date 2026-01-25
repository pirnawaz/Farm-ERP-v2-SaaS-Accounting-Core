<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Create Postgres ENUM types for advance type, direction, method, and status
        DB::statement("DO $$ BEGIN
            CREATE TYPE advance_type AS ENUM ('HARI_ADVANCE', 'VENDOR_ADVANCE', 'LOAN');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        DB::statement("DO $$ BEGIN
            CREATE TYPE advance_direction AS ENUM ('OUT', 'IN');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        DB::statement("DO $$ BEGIN
            CREATE TYPE advance_method AS ENUM ('CASH', 'BANK');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        DB::statement("DO $$ BEGIN
            CREATE TYPE advance_status AS ENUM ('DRAFT', 'POSTED');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        Schema::create('advances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('party_id')->nullable(false);
            $table->string('type')->nullable(false);
            $table->string('direction')->nullable(false);
            $table->decimal('amount', 12, 2)->nullable(false);
            $table->date('posting_date')->nullable(false);
            $table->string('method')->nullable(false);
            $table->string('status')->nullable(false)->default('DRAFT');
            $table->uuid('posting_group_id')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->uuid('project_id')->nullable();
            $table->uuid('crop_cycle_id')->nullable();
            $table->text('notes')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->timestampTz('created_at')->nullable(false)->useCurrent();
            
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('party_id')->references('id')->on('parties');
            $table->foreign('posting_group_id')->references('id')->on('posting_groups');
            $table->foreign('project_id')->references('id')->on('projects');
            $table->foreign('crop_cycle_id')->references('id')->on('crop_cycles');
            
            $table->index(['tenant_id']);
            $table->index(['party_id']);
            $table->index(['status']);
            $table->index(['type']);
            $table->index(['posting_date']);
            $table->index(['tenant_id', 'party_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'type']);
        });
        
        DB::statement('ALTER TABLE advances ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        
        // Convert columns to ENUMs
        DB::statement('ALTER TABLE advances DROP COLUMN type');
        DB::statement("ALTER TABLE advances ADD COLUMN type advance_type NOT NULL");
        DB::statement('ALTER TABLE advances DROP COLUMN direction');
        DB::statement("ALTER TABLE advances ADD COLUMN direction advance_direction NOT NULL DEFAULT 'OUT'");
        DB::statement('ALTER TABLE advances DROP COLUMN method');
        DB::statement("ALTER TABLE advances ADD COLUMN method advance_method NOT NULL DEFAULT 'CASH'");
        DB::statement('ALTER TABLE advances DROP COLUMN status');
        DB::statement("ALTER TABLE advances ADD COLUMN status advance_status NOT NULL DEFAULT 'DRAFT'");
        
        // Add CHECK constraint: amount must be greater than 0
        DB::statement('ALTER TABLE advances ADD CONSTRAINT advances_amount_check CHECK (amount > 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE advances DROP CONSTRAINT IF EXISTS advances_amount_check');
        
        DB::statement('ALTER TABLE advances DROP COLUMN status');
        DB::statement("ALTER TABLE advances ADD COLUMN status VARCHAR(255) NOT NULL DEFAULT 'DRAFT'");
        DB::statement('ALTER TABLE advances DROP COLUMN method');
        DB::statement("ALTER TABLE advances ADD COLUMN method VARCHAR(255) NOT NULL");
        DB::statement('ALTER TABLE advances DROP COLUMN direction');
        DB::statement("ALTER TABLE advances ADD COLUMN direction VARCHAR(255) NOT NULL");
        DB::statement('ALTER TABLE advances DROP COLUMN type');
        DB::statement("ALTER TABLE advances ADD COLUMN type VARCHAR(255) NOT NULL");
        
        Schema::dropIfExists('advances');
        
        DB::statement('DROP TYPE IF EXISTS advance_status');
        DB::statement('DROP TYPE IF EXISTS advance_method');
        DB::statement('DROP TYPE IF EXISTS advance_direction');
        DB::statement('DROP TYPE IF EXISTS advance_type');
    }
};
