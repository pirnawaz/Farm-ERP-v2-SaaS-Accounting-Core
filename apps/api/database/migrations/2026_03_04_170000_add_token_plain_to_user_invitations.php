<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store plain invite token for re-invite (return same link when not expired).
     */
    public function up(): void
    {
        Schema::table('user_invitations', function (Blueprint $table) {
            $table->string('token_plain', 64)->nullable()->after('token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('user_invitations', function (Blueprint $table) {
            $table->dropColumn('token_plain');
        });
    }
};
