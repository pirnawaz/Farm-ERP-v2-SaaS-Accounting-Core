<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_units', function (Blueprint $table) {
            $table->string('livestock_type')->nullable()->after('tree_count');
            $table->unsignedInteger('herd_start_count')->nullable()->after('livestock_type');
        });
    }

    public function down(): void
    {
        Schema::table('production_units', function (Blueprint $table) {
            $table->dropColumn(['livestock_type', 'herd_start_count']);
        });
    }
};
