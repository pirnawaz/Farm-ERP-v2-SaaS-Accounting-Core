<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Create Postgres ENUM types for loan direction, purpose, and status
        DB::statement("DO $$ BEGIN
            CREATE TYPE loan_direction AS ENUM ('PAYABLE', 'RECEIVABLE');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        DB::statement("DO $$ BEGIN
            CREATE TYPE loan_purpose AS ENUM ('FARM_LOAN', 'VENDOR_CREDIT', 'HARI_ADVANCE');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        DB::statement("DO $$ BEGIN
            CREATE TYPE loan_status AS ENUM ('OPEN', 'CLOSED');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        Schema::create('loans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('party_id')->nullable(false);
            $table->string('direction')->nullable(false);
            $table->string('purpose')->nullable(false);
            $table->decimal('principal_amount', 12, 2)->nullable(false);
            $table->decimal('markup_pct', 5, 2)->nullable();
            $table->string('status')->nullable(false)->default('OPEN');
            $table->uuid('linked_loan_id')->nullable();
            $table->timestampTz('created_at')->nullable(false)->useCurrent();
            
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('party_id')->references('id')->on('parties');
            // Note: Self-referencing foreign key added after table creation
            $table->index(['tenant_id']);
            $table->index(['party_id']);
            $table->index(['status']);
            $table->index(['linked_loan_id']);
        });
        
        DB::statement('ALTER TABLE loans ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        
        // Add self-referencing foreign key after table is created
        Schema::table('loans', function (Blueprint $table) {
            $table->foreign('linked_loan_id')->references('id')->on('loans');
        });
        
        // Convert direction, purpose, and status to ENUMs
        DB::statement('ALTER TABLE loans DROP COLUMN direction');
        DB::statement("ALTER TABLE loans ADD COLUMN direction loan_direction NOT NULL DEFAULT 'PAYABLE'");
        DB::statement('ALTER TABLE loans DROP COLUMN purpose');
        DB::statement("ALTER TABLE loans ADD COLUMN purpose loan_purpose NOT NULL DEFAULT 'FARM_LOAN'");
        DB::statement('ALTER TABLE loans DROP COLUMN status');
        DB::statement("ALTER TABLE loans ADD COLUMN status loan_status NOT NULL DEFAULT 'OPEN'");
        
        // Add CHECK constraints
        DB::statement('ALTER TABLE loans ADD CONSTRAINT loans_principal_amount_check CHECK (principal_amount > 0)');
        DB::statement('ALTER TABLE loans ADD CONSTRAINT loans_markup_pct_check CHECK (markup_pct IS NULL OR markup_pct >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE loans DROP CONSTRAINT IF EXISTS loans_markup_pct_check');
        DB::statement('ALTER TABLE loans DROP CONSTRAINT IF EXISTS loans_principal_amount_check');
        
        DB::statement('ALTER TABLE loans DROP COLUMN status');
        DB::statement("ALTER TABLE loans ADD COLUMN status VARCHAR(255) NOT NULL DEFAULT 'OPEN'");
        DB::statement('ALTER TABLE loans DROP COLUMN purpose');
        DB::statement("ALTER TABLE loans ADD COLUMN purpose VARCHAR(255) NOT NULL");
        DB::statement('ALTER TABLE loans DROP COLUMN direction');
        DB::statement("ALTER TABLE loans ADD COLUMN direction VARCHAR(255) NOT NULL");
        
        Schema::dropIfExists('loans');
        
        DB::statement('DROP TYPE IF EXISTS loan_status');
        DB::statement('DROP TYPE IF EXISTS loan_purpose');
        DB::statement('DROP TYPE IF EXISTS loan_direction');
    }
};
