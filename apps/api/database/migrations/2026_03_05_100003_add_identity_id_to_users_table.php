<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('identity_id')->nullable()->after('id');
            $table->foreign('identity_id')->references('id')->on('identities')->nullOnDelete();
            $table->index(['identity_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['identity_id']);
            $table->dropIndex(['identity_id']);
        });
    }
};
