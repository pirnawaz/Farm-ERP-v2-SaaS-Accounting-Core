<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Token lifecycle: version for logout-all invalidation, last_password_change_at for audit.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('token_version')->default(1)->after('is_enabled');
            $table->timestampTz('last_password_change_at')->nullable()->after('token_version');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['token_version', 'last_password_change_at']);
        });
    }
};
