<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_units', function (Blueprint $table) {
            $table->string('category')->nullable()->after('notes');
            $table->string('orchard_crop')->nullable()->after('category');
            $table->unsignedInteger('planting_year')->nullable()->after('orchard_crop');
            $table->decimal('area_acres', 12, 4)->nullable()->after('planting_year');
            $table->unsignedInteger('tree_count')->nullable()->after('area_acres');
        });
    }

    public function down(): void
    {
        Schema::table('production_units', function (Blueprint $table) {
            $table->dropColumn(['category', 'orchard_crop', 'planting_year', 'area_acres', 'tree_count']);
        });
    }
};
