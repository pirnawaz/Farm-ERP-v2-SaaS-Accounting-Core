<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TYPE posting_group_source_type ADD VALUE IF NOT EXISTS 'FIXED_ASSET_ACTIVATION'");
        DB::statement("ALTER TYPE allocation_row_allocation_type ADD VALUE IF NOT EXISTS 'FIXED_ASSET_ACTIVATION'");

        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->uuid('activation_posting_group_id')->nullable()->after('created_by');
            $table->timestampTz('activated_at')->nullable()->after('activation_posting_group_id');
            $table->uuid('activated_by_user_id')->nullable()->after('activated_at');

            $table->foreign('activation_posting_group_id')->references('id')->on('posting_groups');
            $table->foreign('activated_by_user_id')->references('id')->on('users');
            $table->index(['tenant_id', 'activation_posting_group_id']);
        });
    }

    public function down(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->dropForeign(['activation_posting_group_id']);
            $table->dropForeign(['activated_by_user_id']);
            $table->dropIndex(['tenant_id', 'activation_posting_group_id']);
            $table->dropColumn(['activation_posting_group_id', 'activated_at', 'activated_by_user_id']);
        });
        // Cannot remove enum values from PostgreSQL types safely; leave types unchanged.
    }
};
