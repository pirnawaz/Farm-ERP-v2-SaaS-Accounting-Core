<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operational_transactions', function (Blueprint $table) {
            $table->uuid('posting_group_id')->nullable()->after('classification');
            $table->foreign('posting_group_id')->references('id')->on('posting_groups');
            $table->index('posting_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('operational_transactions', function (Blueprint $table) {
            $table->dropForeign(['posting_group_id']);
            $table->dropIndex(['posting_group_id']);
            $table->dropColumn('posting_group_id');
        });
    }
};
