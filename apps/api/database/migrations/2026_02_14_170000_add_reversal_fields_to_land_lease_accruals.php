<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('land_lease_accruals', function (Blueprint $table) {
            $table->uuid('reversal_posting_group_id')->nullable()->after('posted_by');
            $table->timestampTz('reversed_at')->nullable()->after('reversal_posting_group_id');
            $table->uuid('reversed_by')->nullable()->after('reversed_at');
            $table->text('reversal_reason')->nullable()->after('reversed_by');

            $table->foreign('reversal_posting_group_id')->references('id')->on('posting_groups');
            $table->foreign('reversed_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::table('land_lease_accruals', function (Blueprint $table) {
            $table->dropForeign(['reversal_posting_group_id']);
            $table->dropForeign(['reversed_by']);
            $table->dropColumn([
                'reversal_posting_group_id',
                'reversed_at',
                'reversed_by',
                'reversal_reason',
            ]);
        });
    }
};
