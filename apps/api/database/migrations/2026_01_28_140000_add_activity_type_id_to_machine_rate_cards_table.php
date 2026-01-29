<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machine_rate_cards', function (Blueprint $table) {
            $table->uuid('activity_type_id')->nullable()->after('machine_type');
            $table->foreign('activity_type_id')->references('id')->on('crop_activity_types');
        });
    }

    public function down(): void
    {
        Schema::table('machine_rate_cards', function (Blueprint $table) {
            $table->dropForeign(['activity_type_id']);
            $table->dropColumn('activity_type_id');
        });
    }
};
