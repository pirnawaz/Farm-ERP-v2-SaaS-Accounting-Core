<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add CREDIT_NOTE to payment_method so credit notes can use a synthetic Payment as allocation instrument.
     */
    public function up(): void
    {
        DB::statement("ALTER TYPE payment_method ADD VALUE IF NOT EXISTS 'CREDIT_NOTE'");
    }

    public function down(): void
    {
        // PostgreSQL does not support removing enum values; leave CREDIT_NOTE in type.
    }
};
