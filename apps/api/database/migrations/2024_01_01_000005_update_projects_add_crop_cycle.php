<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->uuid('crop_cycle_id')->nullable()->after('tenant_id');
            $table->foreign('crop_cycle_id')->references('id')->on('crop_cycles');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['crop_cycle_id']);
            $table->dropColumn('crop_cycle_id');
        });
    }
};
