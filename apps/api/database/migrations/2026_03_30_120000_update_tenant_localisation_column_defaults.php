<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adjust defaults for new tenant rows only. Existing rows are unchanged.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('currency_code', 3)->default('PKR')->change();
            $table->string('locale', 10)->default('en-PK')->change();
            $table->string('timezone', 64)->default('Asia/Karachi')->change();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('currency_code', 3)->default('GBP')->change();
            $table->string('locale', 10)->default('en-GB')->change();
            $table->string('timezone', 64)->default('Europe/London')->change();
        });
    }
};
