<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->string('email', 255)->nullable(false);
            $table->string('role', 50)->nullable(false);
            $table->uuid('invited_by_user_id')->nullable(false);
            $table->string('token_hash', 64)->nullable(false);
            $table->timestampTz('expires_at')->nullable(false);
            $table->timestampTz('created_at')->nullable(false)->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('invited_by_user_id')->references('id')->on('users');
            $table->unique('token_hash');
            $table->index(['tenant_id', 'email']);
        });

        if (config('database.default') === 'pgsql') {
            DB::statement('ALTER TABLE user_invitations ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitations');
    }
};
