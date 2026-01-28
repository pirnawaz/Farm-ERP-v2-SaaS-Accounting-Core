<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('machine_work_logs', function (Blueprint $table) {
            $table->uuid('machinery_charge_id')->nullable()->after('reversal_posting_group_id');
            $table->foreign('machinery_charge_id')->references('id')->on('machinery_charges');
            $table->index(['tenant_id', 'machinery_charge_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('machine_work_logs', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'machinery_charge_id']);
            $table->dropForeign(['machinery_charge_id']);
            $table->dropColumn('machinery_charge_id');
        });
    }
};
