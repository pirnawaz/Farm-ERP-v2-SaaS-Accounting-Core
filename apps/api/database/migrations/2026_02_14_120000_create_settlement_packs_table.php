<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_packs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('project_id')->nullable(false);
            $table->uuid('generated_by_user_id')->nullable();
            $table->timestampTz('generated_at')->nullable(false);
            $table->string('status', 20)->nullable(false)->default('DRAFT'); // DRAFT | FINAL
            $table->jsonb('summary_json')->nullable();
            $table->string('register_version', 64)->nullable(false);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('project_id')->references('id')->on('projects');
            $table->foreign('generated_by_user_id')->references('id')->on('users');
            $table->unique(['tenant_id', 'project_id', 'register_version']);
            $table->index(['tenant_id']);
            $table->index(['project_id']);
            $table->index(['status']);
        });

        DB::statement('ALTER TABLE settlement_packs ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_packs');
    }
};
