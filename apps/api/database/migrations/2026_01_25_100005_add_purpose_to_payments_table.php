<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('purpose', 32)->nullable()->default('GENERAL');
        });

        DB::statement("UPDATE payments SET purpose = 'GENERAL' WHERE purpose IS NULL");
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });
    }
};
