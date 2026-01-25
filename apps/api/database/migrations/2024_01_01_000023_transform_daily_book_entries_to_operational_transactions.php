<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Create Postgres ENUM type for operational transaction classification
        DB::statement("DO $$ BEGIN
            CREATE TYPE operational_transaction_classification AS ENUM ('SHARED', 'HARI_ONLY', 'FARM_OVERHEAD');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        // Rename table
        Schema::rename('daily_book_entries', 'operational_transactions');

        // Rename columns
        DB::statement('ALTER TABLE operational_transactions RENAME COLUMN event_date TO transaction_date');
        DB::statement('ALTER TABLE operational_transactions RENAME COLUMN gross_amount TO amount');

        // Change amount to numeric(12,2) if not already
        DB::statement('ALTER TABLE operational_transactions ALTER COLUMN amount TYPE NUMERIC(12,2)');

        // Remove columns
        Schema::table('operational_transactions', function (Blueprint $table) {
            $table->dropColumn(['description', 'currency_code']);
        });

        // Add new columns
        Schema::table('operational_transactions', function (Blueprint $table) {
            $table->string('classification')->nullable()->after('status');
            $table->uuid('crop_cycle_id')->nullable()->after('project_id');
            $table->uuid('created_by')->nullable()->after('classification');
        });

        // Add foreign keys
        Schema::table('operational_transactions', function (Blueprint $table) {
            $table->foreign('crop_cycle_id')->references('id')->on('crop_cycles');
            $table->foreign('created_by')->references('id')->on('users');
        });

        // Make project_id nullable (required for FARM_OVERHEAD)
        DB::statement('ALTER TABLE operational_transactions ALTER COLUMN project_id DROP NOT NULL');

        // Convert classification to ENUM
        // First, set a default for existing rows
        DB::statement("UPDATE operational_transactions SET classification = 'SHARED' WHERE classification IS NULL");
        DB::statement('ALTER TABLE operational_transactions ALTER COLUMN classification SET NOT NULL');
        DB::statement('ALTER TABLE operational_transactions DROP COLUMN classification');
        DB::statement('ALTER TABLE operational_transactions ADD COLUMN classification operational_transaction_classification NOT NULL DEFAULT \'SHARED\'');

        // Update existing CHECK constraints
        // Remove old constraints if they exist
        DB::statement('ALTER TABLE operational_transactions DROP CONSTRAINT IF EXISTS daily_book_entries_type_check');
        DB::statement('ALTER TABLE operational_transactions DROP CONSTRAINT IF EXISTS daily_book_entries_status_check');
        DB::statement('ALTER TABLE operational_transactions DROP CONSTRAINT IF EXISTS daily_book_entries_gross_amount_check');

        // Add new CHECK constraints
        DB::statement("ALTER TABLE operational_transactions ADD CONSTRAINT operational_transactions_type_check CHECK (type IN ('EXPENSE', 'INCOME'))");
        DB::statement("ALTER TABLE operational_transactions ADD CONSTRAINT operational_transactions_status_check CHECK (status IN ('DRAFT', 'POSTED', 'VOID'))");
        DB::statement('ALTER TABLE operational_transactions ADD CONSTRAINT operational_transactions_amount_check CHECK (amount > 0)');
        
        // Add constraint: FARM_OVERHEAD must have project_id IS NULL
        DB::statement("ALTER TABLE operational_transactions ADD CONSTRAINT operational_transactions_farm_overhead_project_null CHECK (NOT (classification = 'FARM_OVERHEAD' AND project_id IS NOT NULL))");

        // Add indexes
        Schema::table('operational_transactions', function (Blueprint $table) {
            $table->index('crop_cycle_id');
            $table->index('created_by');
        });

        // Note: Immutability of POSTED records is enforced at service layer
    }

    public function down(): void
    {
        Schema::table('operational_transactions', function (Blueprint $table) {
            $table->dropIndex(['created_by']);
            $table->dropIndex(['crop_cycle_id']);
        });

        DB::statement('ALTER TABLE operational_transactions DROP CONSTRAINT IF EXISTS operational_transactions_farm_overhead_project_null');
        DB::statement('ALTER TABLE operational_transactions DROP CONSTRAINT IF EXISTS operational_transactions_amount_check');
        DB::statement('ALTER TABLE operational_transactions DROP CONSTRAINT IF EXISTS operational_transactions_status_check');
        DB::statement('ALTER TABLE operational_transactions DROP CONSTRAINT IF EXISTS operational_transactions_type_check');

        DB::statement('ALTER TABLE operational_transactions DROP COLUMN classification');
        DB::statement("ALTER TABLE operational_transactions ADD COLUMN classification VARCHAR(255)");

        Schema::table('operational_transactions', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['crop_cycle_id']);
            $table->dropColumn(['created_by', 'crop_cycle_id', 'classification']);
        });

        DB::statement('ALTER TABLE operational_transactions ALTER COLUMN project_id SET NOT NULL');

        Schema::table('operational_transactions', function (Blueprint $table) {
            $table->string('description')->nullable();
            $table->char('currency_code', 3)->default('GBP')->nullable();
        });

        DB::statement('ALTER TABLE operational_transactions RENAME COLUMN transaction_date TO event_date');
        DB::statement('ALTER TABLE operational_transactions RENAME COLUMN amount TO gross_amount');

        Schema::rename('operational_transactions', 'daily_book_entries');

        DB::statement('DROP TYPE IF EXISTS operational_transaction_classification');
    }
};
