<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_pack_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('settlement_pack_id')->nullable(false);
            $table->uuid('approver_user_id')->nullable(false);
            $table->string('approver_role', 64)->nullable(false);
            $table->string('status', 20)->nullable(false)->default('PENDING'); // PENDING | APPROVED | REJECTED
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->text('comment')->nullable();
            $table->char('snapshot_sha256', 64)->nullable(false);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('settlement_pack_id')->references('id')->on('settlement_packs')->onDelete('cascade');
            $table->foreign('approver_user_id')->references('id')->on('users');
            $table->unique(['tenant_id', 'settlement_pack_id', 'approver_user_id']);
            $table->index(['tenant_id']);
            $table->index(['settlement_pack_id']);
            $table->index(['approver_user_id']);
        });

        DB::statement('ALTER TABLE settlement_pack_approvals ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_pack_approvals');
    }
};
