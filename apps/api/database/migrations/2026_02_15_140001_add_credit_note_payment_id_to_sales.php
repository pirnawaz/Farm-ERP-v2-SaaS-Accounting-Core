<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link posted credit note sales to a synthetic Payment used for apply-to-invoice allocations.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->uuid('credit_note_payment_id')->nullable()->after('reversal_posting_group_id');
            $table->foreign('credit_note_payment_id')->references('id')->on('payments');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['credit_note_payment_id']);
            $table->dropColumn('credit_note_payment_id');
        });
    }
};
