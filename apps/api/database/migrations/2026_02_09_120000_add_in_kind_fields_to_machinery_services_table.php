<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional in-kind payment (e.g. wheat per mann/hour).
     * Editable only when status = DRAFT; immutable once POSTED.
     */
    public function up(): void
    {
        Schema::table('machinery_services', function (Blueprint $table) {
            $table->uuid('in_kind_item_id')->nullable()->after('allocation_scope');
            $table->decimal('in_kind_rate_per_unit', 14, 4)->nullable()->after('in_kind_item_id');
            $table->decimal('in_kind_quantity', 14, 4)->nullable()->after('in_kind_rate_per_unit');
            $table->uuid('in_kind_store_id')->nullable()->after('in_kind_quantity');
            $table->uuid('in_kind_inventory_issue_id')->nullable()->after('in_kind_store_id');

            $table->foreign('in_kind_item_id')->references('id')->on('inv_items')->nullOnDelete();
            $table->foreign('in_kind_store_id')->references('id')->on('inv_stores')->nullOnDelete();
            $table->foreign('in_kind_inventory_issue_id')->references('id')->on('inv_issues')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('machinery_services', function (Blueprint $table) {
            $table->dropForeign(['in_kind_item_id']);
            $table->dropForeign(['in_kind_store_id']);
            $table->dropForeign(['in_kind_inventory_issue_id']);
            $table->dropColumn([
                'in_kind_item_id',
                'in_kind_rate_per_unit',
                'in_kind_quantity',
                'in_kind_store_id',
                'in_kind_inventory_issue_id',
            ]);
        });
    }
};
