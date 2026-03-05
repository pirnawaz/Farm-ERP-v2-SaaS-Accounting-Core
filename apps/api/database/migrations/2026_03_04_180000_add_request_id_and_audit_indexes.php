<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add request_id to identity_audit_log for traceability; add load-safety indexes.
     */
    public function up(): void
    {
        Schema::table('identity_audit_log', function (Blueprint $table) {
            $table->uuid('request_id')->nullable()->after('id');
            $table->index('created_at');
            $table->index('tenant_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index('token_version');
        });
    }

    public function down(): void
    {
        Schema::table('identity_audit_log', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['tenant_id']);
            $table->dropColumn('request_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['token_version']);
        });
    }
};
