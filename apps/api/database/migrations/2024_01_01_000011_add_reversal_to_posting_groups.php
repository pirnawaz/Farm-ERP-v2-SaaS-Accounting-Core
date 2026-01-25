<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posting_groups', function (Blueprint $table) {
            $table->uuid('reversal_of_posting_group_id')->nullable()->after('posting_date');
            $table->text('correction_reason')->nullable()->after('reversal_of_posting_group_id');
            
            $table->foreign('reversal_of_posting_group_id')->references('id')->on('posting_groups');
            $table->index(['tenant_id', 'reversal_of_posting_group_id'], 'idx_posting_groups_reversal');
        });
        
        // Add constraint: reversal_of_posting_group_id != id
        DB::statement('ALTER TABLE posting_groups ADD CONSTRAINT posting_groups_no_self_reversal CHECK (reversal_of_posting_group_id != id)');
        
        // Add unique constraint: (tenant_id, reversal_of_posting_group_id, posting_date)
        // Note: This allows multiple reversals on different dates, but prevents duplicate reversals on same date
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS idx_posting_groups_reversal_unique ON posting_groups(tenant_id, reversal_of_posting_group_id, posting_date) WHERE reversal_of_posting_group_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('posting_groups', function (Blueprint $table) {
            $table->dropForeign(['reversal_of_posting_group_id']);
            $table->dropIndex('idx_posting_groups_reversal');
            $table->dropColumn(['reversal_of_posting_group_id', 'correction_reason']);
        });
        
        DB::statement('DROP INDEX IF EXISTS idx_posting_groups_reversal_unique');
        DB::statement('ALTER TABLE posting_groups DROP CONSTRAINT IF EXISTS posting_groups_no_self_reversal');
    }
};
