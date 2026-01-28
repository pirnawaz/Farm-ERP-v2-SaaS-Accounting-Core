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
        Schema::table('inv_issues', function (Blueprint $table) {
            $table->uuid('machine_id')->nullable()->after('activity_id');
            $table->foreign('machine_id')->references('id')->on('machines');
            $table->index(['tenant_id', 'machine_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inv_issues', function (Blueprint $table) {
            $table->dropForeign(['machine_id']);
            $table->dropIndex(['tenant_id', 'machine_id']);
            $table->dropColumn('machine_id');
        });
    }
};
