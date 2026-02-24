<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crop_cycles', function (Blueprint $table) {
            $table->uuid('tenant_crop_item_id')->nullable()->after('name');
            $table->uuid('crop_variety_id')->nullable()->after('tenant_crop_item_id');
        });

        Schema::table('crop_cycles', function (Blueprint $table) {
            $table->foreign('tenant_crop_item_id')->references('id')->on('tenant_crop_items')->nullOnDelete();
            $table->foreign('crop_variety_id')->references('id')->on('crop_varieties')->nullOnDelete();
            $table->index(['tenant_crop_item_id']);
            $table->index(['crop_variety_id']);
        });
    }

    public function down(): void
    {
        Schema::table('crop_cycles', function (Blueprint $table) {
            $table->dropForeign(['tenant_crop_item_id']);
            $table->dropForeign(['crop_variety_id']);
        });
        Schema::table('crop_cycles', function (Blueprint $table) {
            $table->dropColumn(['tenant_crop_item_id', 'crop_variety_id']);
        });
    }
};
