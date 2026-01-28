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
        // Add new columns for M1 support
        Schema::table('allocation_rows', function (Blueprint $table) {
            $table->decimal('quantity', 12, 2)->nullable()->after('amount');
            $table->string('unit')->nullable()->after('quantity');
            $table->uuid('machine_id')->nullable()->after('unit');
        });

        // Add foreign key for machine_id
        Schema::table('allocation_rows', function (Blueprint $table) {
            $table->foreign('machine_id')->references('id')->on('machines');
        });

        // Make amount nullable to allow usage rows with amount null or 0
        // First, drop the existing check constraint
        DB::statement('ALTER TABLE allocation_rows DROP CONSTRAINT IF EXISTS allocation_rows_amount_check');
        
        // Make amount nullable
        DB::statement('ALTER TABLE allocation_rows ALTER COLUMN amount DROP NOT NULL');
        
        // Add new check constraint that allows NULL or >= 0
        DB::statement('ALTER TABLE allocation_rows ADD CONSTRAINT allocation_rows_amount_check CHECK (amount IS NULL OR amount >= 0)');

        // Add enum values to allocation_row_allocation_type
        DB::statement("ALTER TYPE allocation_row_allocation_type ADD VALUE IF NOT EXISTS 'MACHINERY_USAGE'");
        DB::statement("ALTER TYPE allocation_row_allocation_type ADD VALUE IF NOT EXISTS 'MACHINERY_CHARGE'");

        // Add enum value to posting_group_source_type
        DB::statement("DO $$ BEGIN
            ALTER TYPE posting_group_source_type ADD VALUE 'MACHINERY_CHARGE';
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove foreign key
        Schema::table('allocation_rows', function (Blueprint $table) {
            $table->dropForeign(['machine_id']);
        });

        // Remove columns
        Schema::table('allocation_rows', function (Blueprint $table) {
            $table->dropColumn(['quantity', 'unit', 'machine_id']);
        });

        // Restore amount to NOT NULL
        // First, set NULL values to 0
        DB::statement("UPDATE allocation_rows SET amount = 0 WHERE amount IS NULL");
        
        // Make amount NOT NULL again
        DB::statement('ALTER TABLE allocation_rows ALTER COLUMN amount SET NOT NULL');
        
        // Restore original check constraint
        DB::statement('ALTER TABLE allocation_rows DROP CONSTRAINT IF EXISTS allocation_rows_amount_check');
        DB::statement('ALTER TABLE allocation_rows ADD CONSTRAINT allocation_rows_amount_check CHECK (amount >= 0)');

        // Note: PostgreSQL does not support removing values from enums, so enum changes cannot be reversed
    }
};
