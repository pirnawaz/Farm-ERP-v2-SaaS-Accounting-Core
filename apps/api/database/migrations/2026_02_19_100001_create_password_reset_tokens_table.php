<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * One-time tokens for platform-initiated tenant admin password reset.
     */
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable(false);
            $table->string('token_hash', 64)->nullable(false);
            $table->timestampTz('expires_at')->nullable(false);
            $table->timestampTz('created_at')->nullable(false)->useCurrent();

            $table->foreign('user_id')->references('id')->on('users');
            $table->unique('token_hash');
            $table->index(['user_id', 'expires_at']);
        });

        if (config('database.default') === 'pgsql') {
            DB::statement('ALTER TABLE password_reset_tokens ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
