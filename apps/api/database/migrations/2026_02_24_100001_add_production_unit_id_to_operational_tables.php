<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crop_activities', function (Blueprint $table) {
            $table->uuid('production_unit_id')->nullable()->after('project_id');
            $table->foreign('production_unit_id')->references('id')->on('production_units');
        });

        Schema::table('lab_work_logs', function (Blueprint $table) {
            $table->uuid('production_unit_id')->nullable()->after('project_id');
            $table->foreign('production_unit_id')->references('id')->on('production_units');
        });

        Schema::table('inv_issues', function (Blueprint $table) {
            $table->uuid('production_unit_id')->nullable()->after('project_id');
            $table->foreign('production_unit_id')->references('id')->on('production_units');
        });

        Schema::table('harvests', function (Blueprint $table) {
            $table->uuid('production_unit_id')->nullable()->after('project_id');
            $table->foreign('production_unit_id')->references('id')->on('production_units');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->uuid('production_unit_id')->nullable()->after('crop_cycle_id');
            $table->foreign('production_unit_id')->references('id')->on('production_units');
        });
    }

    public function down(): void
    {
        Schema::table('crop_activities', function (Blueprint $table) {
            $table->dropForeign(['production_unit_id']);
        });
        Schema::table('lab_work_logs', function (Blueprint $table) {
            $table->dropForeign(['production_unit_id']);
        });
        Schema::table('inv_issues', function (Blueprint $table) {
            $table->dropForeign(['production_unit_id']);
        });
        Schema::table('harvests', function (Blueprint $table) {
            $table->dropForeign(['production_unit_id']);
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['production_unit_id']);
        });
    }
};
