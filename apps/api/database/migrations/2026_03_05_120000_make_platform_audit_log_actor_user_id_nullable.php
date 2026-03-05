<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allow platform_audit_log.actor_user_id to be null for identity-based platform auth
     * (no User record). Actor identity is stored in metadata.actor_identity_id and metadata.actor_email.
     */
    public function up(): void
    {
        Schema::table('platform_audit_log', function (Blueprint $table) {
            $table->uuid('actor_user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('platform_audit_log', function (Blueprint $table) {
            $table->uuid('actor_user_id')->nullable(false)->change();
        });
    }
};
