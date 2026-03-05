<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('identity_id');
            $table->uuid('tenant_id');
            $table->string('role', 32); // tenant_admin, accountant, operator
            $table->boolean('is_enabled')->default(true);
            $table->timestampsTz();

            $table->foreign('identity_id')->references('id')->on('identities')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['identity_id', 'tenant_id']);
            $table->index(['identity_id']);
            $table->index(['tenant_id']);
        });

        DB::statement('ALTER TABLE tenant_memberships ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_memberships');
    }
};
