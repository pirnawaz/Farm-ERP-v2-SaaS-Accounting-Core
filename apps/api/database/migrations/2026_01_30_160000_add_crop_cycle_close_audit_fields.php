<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crop_cycles', function (Blueprint $table) {
            $table->timestampTz('closed_at')->nullable()->after('status');
            $table->uuid('closed_by_user_id')->nullable()->after('closed_at');
            $table->text('close_note')->nullable()->after('closed_by_user_id');
            $table->index('closed_at');
        });

        Schema::table('crop_cycles', function (Blueprint $table) {
            $table->foreign('closed_by_user_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::table('crop_cycles', function (Blueprint $table) {
            $table->dropForeign(['closed_by_user_id']);
            $table->dropIndex(['closed_at']);
            $table->dropColumn(['closed_at', 'closed_by_user_id', 'close_note']);
        });
    }
};
