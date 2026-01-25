<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('currency_code', 3)->default('GBP')->nullable(false)->after('status');
            $table->string('locale', 10)->default('en-GB')->nullable(false)->after('currency_code');
            $table->string('timezone', 64)->default('Europe/London')->nullable(false)->after('locale');
        });

        // Update existing tenants to have defaults (they should already have them from column defaults, but ensure consistency)
        \DB::table('tenants')->whereNull('currency_code')->update(['currency_code' => 'GBP']);
        \DB::table('tenants')->whereNull('locale')->update(['locale' => 'en-GB']);
        \DB::table('tenants')->whereNull('timezone')->update(['timezone' => 'Europe/London']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['currency_code', 'locale', 'timezone']);
        });
    }
};
