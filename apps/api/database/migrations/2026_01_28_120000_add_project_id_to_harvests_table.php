<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('harvests', function (Blueprint $table) {
            $table->uuid('project_id')->nullable()->after('crop_cycle_id');
            $table->foreign('project_id')->references('id')->on('projects');
            $table->index(['tenant_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::table('harvests', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropIndex(['tenant_id', 'project_id']);
            $table->dropColumn('project_id');
        });
    }
};
