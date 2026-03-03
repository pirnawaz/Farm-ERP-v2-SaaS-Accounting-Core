<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->uuid('field_block_id')->nullable()->after('land_allocation_id');
            $table->foreign('field_block_id')->references('id')->on('field_blocks')->onDelete('set null');
            $table->index('field_block_id');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['field_block_id']);
            $table->dropColumn('field_block_id');
        });
    }
};
