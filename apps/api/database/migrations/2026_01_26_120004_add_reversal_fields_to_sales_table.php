<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->timestampTz('reversed_at')->nullable()->after('posted_at');
            $table->uuid('reversal_posting_group_id')->nullable()->after('reversed_at');
            
            $table->foreign('reversal_posting_group_id')->references('id')->on('posting_groups');
            $table->index(['tenant_id', 'reversal_posting_group_id']);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['reversal_posting_group_id']);
            $table->dropIndex(['tenant_id', 'reversal_posting_group_id']);
            $table->dropColumn(['reversed_at', 'reversal_posting_group_id']);
        });
    }
};
