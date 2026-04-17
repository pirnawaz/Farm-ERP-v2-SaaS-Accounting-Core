<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TYPE posting_group_source_type ADD VALUE IF NOT EXISTS 'MACHINERY_EXTERNAL_INCOME'");
        DB::statement("ALTER TYPE allocation_row_allocation_type ADD VALUE IF NOT EXISTS 'MACHINERY_EXTERNAL_INCOME'");

        Schema::table('machine_work_logs', function (Blueprint $table) {
            $table->boolean('chargeable')->default(false)->after('usage_qty');
            $table->decimal('internal_charge_rate', 14, 4)->nullable()->after('chargeable');
            $table->decimal('internal_charge_amount', 14, 2)->nullable()->after('internal_charge_rate');
        });
    }

    public function down(): void
    {
        Schema::table('machine_work_logs', function (Blueprint $table) {
            $table->dropColumn(['chargeable', 'internal_charge_rate', 'internal_charge_amount']);
        });
    }
};
