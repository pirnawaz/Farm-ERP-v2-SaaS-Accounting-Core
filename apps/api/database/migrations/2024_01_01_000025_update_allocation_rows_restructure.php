<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Create Postgres ENUM type for allocation type
        DB::statement("DO $$ BEGIN
            CREATE TYPE allocation_row_allocation_type AS ENUM ('POOL_SHARE', 'HARI_ONLY', 'KAMDARI');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        // Remove columns
        Schema::table('allocation_rows', function (Blueprint $table) {
            $table->dropColumn(['cost_type', 'currency_code', 'rule_version', 'rule_hash']);
        });

        // Add new columns (nullable first, then populate)
        Schema::table('allocation_rows', function (Blueprint $table) {
            $table->uuid('party_id')->nullable()->after('project_id');
            $table->string('allocation_type')->nullable()->after('party_id');
        });

        // Populate party_id from projects table for existing rows
        // Get party_id from the project's party_id
        DB::statement('UPDATE allocation_rows SET party_id = (SELECT party_id FROM projects WHERE projects.id = allocation_rows.project_id) WHERE party_id IS NULL');

        // If any rows still don't have party_id, we need to handle them
        // For now, we'll set a default or leave nullable if no party exists
        // Note: This assumes all projects have party_id set, which should be true after project migration
        
        // Set default allocation_type for existing rows
        DB::statement("UPDATE allocation_rows SET allocation_type = 'POOL_SHARE' WHERE allocation_type IS NULL");

        // Now make them NOT NULL
        DB::statement('ALTER TABLE allocation_rows ALTER COLUMN party_id SET NOT NULL');
        DB::statement('ALTER TABLE allocation_rows ALTER COLUMN allocation_type SET NOT NULL');

        // Add foreign key for party_id
        Schema::table('allocation_rows', function (Blueprint $table) {
            $table->foreign('party_id')->references('id')->on('parties');
        });

        // Rename rule_snapshot_json to rule_snapshot
        DB::statement('ALTER TABLE allocation_rows RENAME COLUMN rule_snapshot_json TO rule_snapshot');

        // Update amount to numeric(12,2) if not already
        DB::statement('ALTER TABLE allocation_rows ALTER COLUMN amount TYPE NUMERIC(12,2)');

        // Convert allocation_type to ENUM
        // First, set a default for existing rows
        DB::statement("UPDATE allocation_rows SET allocation_type = 'POOL_SHARE' WHERE allocation_type IS NULL");
        DB::statement('ALTER TABLE allocation_rows ALTER COLUMN allocation_type SET NOT NULL');
        DB::statement('ALTER TABLE allocation_rows DROP COLUMN allocation_type');
        DB::statement("ALTER TABLE allocation_rows ADD COLUMN allocation_type allocation_row_allocation_type NOT NULL DEFAULT 'POOL_SHARE'");

        // Add indexes
        Schema::table('allocation_rows', function (Blueprint $table) {
            $table->index('party_id');
            $table->index('allocation_type');
        });
    }

    public function down(): void
    {
        Schema::table('allocation_rows', function (Blueprint $table) {
            $table->dropIndex(['allocation_type']);
            $table->dropIndex(['party_id']);
        });

        DB::statement('ALTER TABLE allocation_rows DROP COLUMN allocation_type');
        DB::statement("ALTER TABLE allocation_rows ADD COLUMN allocation_type VARCHAR(255) NOT NULL");

        Schema::table('allocation_rows', function (Blueprint $table) {
            $table->dropForeign(['party_id']);
            $table->dropColumn('party_id');
        });

        DB::statement('ALTER TABLE allocation_rows RENAME COLUMN rule_snapshot TO rule_snapshot_json');

        Schema::table('allocation_rows', function (Blueprint $table) {
            $table->string('cost_type')->nullable();
            $table->char('currency_code', 3)->default('GBP')->nullable();
            $table->string('rule_version')->nullable();
            $table->string('rule_hash')->nullable();
        });

        DB::statement('DROP TYPE IF EXISTS allocation_row_allocation_type');
    }
};
