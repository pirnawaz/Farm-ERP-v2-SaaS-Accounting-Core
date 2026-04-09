<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TYPE posting_group_source_type ADD VALUE IF NOT EXISTS 'LOAN_DRAWDOWN'");
        DB::statement("ALTER TYPE allocation_row_allocation_type ADD VALUE IF NOT EXISTS 'LOAN_DRAWDOWN'");

        Schema::table('loan_drawdowns', function (Blueprint $table) {
            $table->uuid('posting_group_id')->nullable()->after('created_by');
            $table->timestampTz('posted_at')->nullable()->after('posting_group_id');
            $table->foreign('posting_group_id')->references('id')->on('posting_groups');
            $table->index(['tenant_id', 'posting_group_id']);
        });
    }

    public function down(): void
    {
        Schema::table('loan_drawdowns', function (Blueprint $table) {
            $table->dropForeign(['posting_group_id']);
            $table->dropIndex(['tenant_id', 'posting_group_id']);
            $table->dropColumn(['posting_group_id', 'posted_at']);
        });
        // PostgreSQL cannot remove enum values safely; leave enum values in place.
    }
};
