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
        // Create Postgres ENUM type for allocation mode
        DB::statement("DO $$ BEGIN
            CREATE TYPE inv_issue_allocation_mode AS ENUM ('SHARED', 'HARI_ONLY', 'FARMER_ONLY');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        Schema::table('inv_issues', function (Blueprint $table) {
            // Add nullable columns first
            $table->uuid('hari_id')->nullable()->after('machine_id');
            $table->uuid('sharing_rule_id')->nullable()->after('hari_id');
            $table->decimal('landlord_share_pct', 5, 2)->nullable()->after('sharing_rule_id');
            $table->decimal('hari_share_pct', 5, 2)->nullable()->after('landlord_share_pct');
        });

        // Add allocation_mode as nullable first, then convert to ENUM and make NOT NULL
        Schema::table('inv_issues', function (Blueprint $table) {
            $table->string('allocation_mode')->nullable()->after('hari_share_pct');
        });

        // Set default for existing records (SHARED)
        DB::statement("UPDATE inv_issues SET allocation_mode = 'SHARED' WHERE allocation_mode IS NULL");

        // Convert to ENUM and make NOT NULL
        DB::statement('ALTER TABLE inv_issues DROP COLUMN allocation_mode');
        DB::statement("ALTER TABLE inv_issues ADD COLUMN allocation_mode inv_issue_allocation_mode NOT NULL DEFAULT 'SHARED'");

        // Add foreign keys
        Schema::table('inv_issues', function (Blueprint $table) {
            $table->foreign('hari_id')->references('id')->on('parties')->onDelete('restrict');
            $table->foreign('sharing_rule_id')->references('id')->on('share_rules')->onDelete('restrict');
        });

        // Add indexes
        Schema::table('inv_issues', function (Blueprint $table) {
            $table->index('allocation_mode');
            $table->index('hari_id');
            $table->index('sharing_rule_id');
        });

        // Add CHECK constraints
        // If allocation_mode='HARI_ONLY' then hari_id IS NOT NULL
        DB::statement("ALTER TABLE inv_issues ADD CONSTRAINT inv_issues_hari_only_hari_id_check 
            CHECK (allocation_mode != 'HARI_ONLY' OR hari_id IS NOT NULL)");

        // If allocation_mode='SHARED' then (sharing_rule_id IS NOT NULL OR (landlord_share_pct IS NOT NULL AND hari_share_pct IS NOT NULL AND landlord_share_pct + hari_share_pct = 100))
        DB::statement("ALTER TABLE inv_issues ADD CONSTRAINT inv_issues_shared_allocation_check 
            CHECK (allocation_mode != 'SHARED' OR sharing_rule_id IS NOT NULL OR 
            (landlord_share_pct IS NOT NULL AND hari_share_pct IS NOT NULL AND 
            ABS(landlord_share_pct + hari_share_pct - 100) < 0.01))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop constraints
        DB::statement('ALTER TABLE inv_issues DROP CONSTRAINT IF EXISTS inv_issues_shared_allocation_check');
        DB::statement('ALTER TABLE inv_issues DROP CONSTRAINT IF EXISTS inv_issues_hari_only_hari_id_check');

        Schema::table('inv_issues', function (Blueprint $table) {
            $table->dropIndex(['sharing_rule_id']);
            $table->dropIndex(['hari_id']);
            $table->dropIndex(['allocation_mode']);
            $table->dropForeign(['sharing_rule_id']);
            $table->dropForeign(['hari_id']);
        });

        // Convert ENUM back to string, then drop
        DB::statement('ALTER TABLE inv_issues DROP COLUMN allocation_mode');
        Schema::table('inv_issues', function (Blueprint $table) {
            $table->dropColumn(['hari_share_pct', 'landlord_share_pct', 'sharing_rule_id', 'hari_id']);
        });

        // Drop ENUM type
        DB::statement('DROP TYPE IF EXISTS inv_issue_allocation_mode');
    }
};
