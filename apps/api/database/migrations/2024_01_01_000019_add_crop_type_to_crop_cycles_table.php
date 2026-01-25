<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add crop_type field to crop_cycles table
        Schema::table('crop_cycles', function (Blueprint $table) {
            $table->string('crop_type')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('crop_cycles', function (Blueprint $table) {
            $table->dropColumn('crop_type');
        });
    }
};
