<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('sale_no')->nullable()->after('id');
            $table->date('sale_date')->nullable()->after('posting_date');
            $table->date('due_date')->nullable()->after('sale_date');
        });

        // Set default values: sale_date = posting_date, due_date = posting_date
        // This will be handled in the model or service layer
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['sale_no', 'sale_date', 'due_date']);
        });
    }
};
