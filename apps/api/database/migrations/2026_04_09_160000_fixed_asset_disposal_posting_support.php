<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TYPE posting_group_source_type ADD VALUE IF NOT EXISTS 'FIXED_ASSET_DISPOSAL'");
        DB::statement("ALTER TYPE allocation_row_allocation_type ADD VALUE IF NOT EXISTS 'FIXED_ASSET_DISPOSAL'");

        Schema::table('fixed_asset_disposals', function (Blueprint $table) {
            $table->string('proceeds_account', 32)->nullable()->after('proceeds_amount');
            $table->decimal('carrying_amount_at_post', 18, 2)->nullable()->after('posting_group_id');
            $table->decimal('gain_amount', 18, 2)->nullable()->after('carrying_amount_at_post');
            $table->decimal('loss_amount', 18, 2)->nullable()->after('gain_amount');
        });
    }

    public function down(): void
    {
        Schema::table('fixed_asset_disposals', function (Blueprint $table) {
            $table->dropColumn(['proceeds_account', 'carrying_amount_at_post', 'gain_amount', 'loss_amount']);
        });
    }
};
