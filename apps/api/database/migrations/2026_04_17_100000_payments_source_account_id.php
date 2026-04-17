<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->uuid('source_account_id')->nullable()->after('method');
            $table->foreign('source_account_id')->references('id')->on('accounts')->nullOnDelete();
            $table->index(['tenant_id', 'source_account_id']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['source_account_id']);
            $table->dropIndex(['tenant_id', 'source_account_id']);
            $table->dropColumn('source_account_id');
        });
    }
};
