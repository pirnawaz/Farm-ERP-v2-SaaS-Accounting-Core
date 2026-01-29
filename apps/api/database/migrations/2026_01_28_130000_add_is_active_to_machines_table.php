<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('status');
        });

        // Migrate existing status -> is_active: "Active"/"active" -> true, else false
        DB::table('machines')
            ->whereRaw("LOWER(TRIM(status)) NOT IN ('active', '1', 'true', 'yes')")
            ->whereNotNull('status')
            ->update(['is_active' => false]);
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
