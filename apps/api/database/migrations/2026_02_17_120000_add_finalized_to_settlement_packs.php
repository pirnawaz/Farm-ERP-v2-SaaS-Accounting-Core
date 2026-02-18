<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlement_packs', function (Blueprint $table) {
            $table->timestampTz('finalized_at')->nullable()->after('status');
            $table->uuid('finalized_by_user_id')->nullable()->after('finalized_at');
            $table->foreign('finalized_by_user_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::table('settlement_packs', function (Blueprint $table) {
            $table->dropForeign(['finalized_by_user_id']);
            $table->dropColumn(['finalized_at', 'finalized_by_user_id']);
        });
    }
};
