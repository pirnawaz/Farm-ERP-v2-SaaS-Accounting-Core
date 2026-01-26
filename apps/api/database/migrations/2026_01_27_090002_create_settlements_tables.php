<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Create Postgres ENUM type for settlement status
        DB::statement("DO $$ BEGIN
            CREATE TYPE settlement_status AS ENUM ('DRAFT', 'POSTED', 'REVERSED');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        // Add new columns to existing settlements table (nullable to support existing records)
        Schema::table('settlements', function (Blueprint $table) {
            $table->string('settlement_no')->nullable()->after('id');
            $table->uuid('share_rule_id')->nullable()->after('project_id');
            $table->uuid('crop_cycle_id')->nullable()->after('share_rule_id');
            $table->date('from_date')->nullable()->after('crop_cycle_id');
            $table->date('to_date')->nullable()->after('from_date');
            $table->decimal('basis_amount', 12, 2)->nullable()->after('to_date');
            $table->string('status')->nullable()->default('DRAFT')->after('basis_amount');
            $table->date('posting_date')->nullable()->after('status');
            $table->uuid('reversal_posting_group_id')->nullable()->after('posting_group_id');
            $table->timestampTz('posted_at')->nullable()->after('reversal_posting_group_id');
            $table->timestampTz('reversed_at')->nullable()->after('posted_at');
            $table->uuid('created_by')->nullable()->after('reversed_at');
        });

        // Add foreign keys
        Schema::table('settlements', function (Blueprint $table) {
            $table->foreign('share_rule_id')->references('id')->on('share_rules');
            $table->foreign('crop_cycle_id')->references('id')->on('crop_cycles');
            $table->foreign('reversal_posting_group_id')->references('id')->on('posting_groups');
            $table->foreign('created_by')->references('id')->on('users');
        });

        // Add indexes
        Schema::table('settlements', function (Blueprint $table) {
            $table->index(['tenant_id', 'status']);
            $table->index(['crop_cycle_id']);
            $table->index(['posting_date']);
            $table->index(['settlement_no']);
            $table->unique(['tenant_id', 'settlement_no']);
        });

        // Convert status to ENUM (handle existing data)
        // First, set default status for existing records
        DB::statement("UPDATE settlements SET status = 'POSTED' WHERE posting_group_id IS NOT NULL AND status IS NULL");
        DB::statement("UPDATE settlements SET status = 'DRAFT' WHERE status IS NULL");
        
        // Drop and recreate as ENUM
        DB::statement('ALTER TABLE settlements DROP COLUMN status');
        DB::statement("ALTER TABLE settlements ADD COLUMN status settlement_status NOT NULL DEFAULT 'DRAFT'");

        // Create settlement_lines table
        Schema::create('settlement_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('settlement_id')->nullable(false);
            $table->uuid('party_id')->nullable(false);
            $table->string('role')->nullable();
            $table->decimal('percentage', 5, 2)->nullable(false);
            $table->decimal('amount', 12, 2)->nullable(false);
            $table->timestampsTz();
            
            $table->foreign('settlement_id')->references('id')->on('settlements')->onDelete('cascade');
            $table->foreign('party_id')->references('id')->on('parties');
            $table->index(['settlement_id']);
            $table->index(['party_id']);
        });
        
        DB::statement('ALTER TABLE settlement_lines ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        
        // Ensure amount >= 0
        DB::statement('ALTER TABLE settlement_lines ADD CONSTRAINT settlement_lines_amount_check CHECK (amount >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE settlement_lines DROP CONSTRAINT IF EXISTS settlement_lines_amount_check');
        
        Schema::dropIfExists('settlement_lines');
        
        // Revert status column
        DB::statement('ALTER TABLE settlements DROP COLUMN status');
        DB::statement("ALTER TABLE settlements ADD COLUMN status VARCHAR(255) DEFAULT 'DRAFT'");
        
        // Drop indexes
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'settlement_no']);
            $table->dropIndex(['settlement_no']);
            $table->dropIndex(['posting_date']);
            $table->dropIndex(['crop_cycle_id']);
            $table->dropIndex(['tenant_id', 'status']);
        });
        
        // Drop foreign keys
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['reversal_posting_group_id']);
            $table->dropForeign(['crop_cycle_id']);
            $table->dropForeign(['share_rule_id']);
        });
        
        // Drop columns
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropColumn([
                'created_by',
                'reversed_at',
                'posted_at',
                'reversal_posting_group_id',
                'posting_date',
                'status',
                'basis_amount',
                'to_date',
                'from_date',
                'crop_cycle_id',
                'share_rule_id',
                'settlement_no',
            ]);
        });
        
        DB::statement('DROP TYPE IF EXISTS settlement_status');
    }
};
