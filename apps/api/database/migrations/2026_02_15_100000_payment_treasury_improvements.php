<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Treasury / Payments: add PAYMENT source_type and allocation_type,
     * PARTY_ONLY allocation_scope, and payment reversal fields.
     */
    public function up(): void
    {
        DB::statement("ALTER TYPE posting_group_source_type ADD VALUE IF NOT EXISTS 'PAYMENT'");
        DB::statement("ALTER TYPE allocation_row_allocation_type ADD VALUE IF NOT EXISTS 'PAYMENT'");
        DB::statement("ALTER TYPE allocation_row_allocation_scope ADD VALUE IF NOT EXISTS 'PARTY_ONLY'");

        Schema::table('payments', function (Blueprint $table) {
            $table->uuid('reversal_posting_group_id')->nullable()->after('posting_group_id');
            $table->timestampTz('reversed_at')->nullable()->after('reversal_posting_group_id');
            $table->uuid('reversed_by')->nullable()->after('reversed_at');
            $table->text('reversal_reason')->nullable()->after('reversed_by');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('reversal_posting_group_id')->references('id')->on('posting_groups');
            $table->foreign('reversed_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['reversed_by']);
            $table->dropForeign(['reversal_posting_group_id']);
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['reversal_posting_group_id', 'reversed_at', 'reversed_by', 'reversal_reason']);
        });
        // PostgreSQL does not support removing enum values
    }
};
