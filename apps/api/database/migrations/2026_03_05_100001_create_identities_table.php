<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_platform_admin')->default(false);
            $table->unsignedInteger('token_version')->default(1);
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE identities ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        Schema::table('identities', function (Blueprint $table) {
            $table->index(['email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identities');
    }
};
