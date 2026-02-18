<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_pack_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('settlement_pack_id')->nullable(false);
            $table->unsignedInteger('version')->nullable(false);
            $table->string('status', 20)->nullable(false)->default('GENERATED');
            $table->string('storage_key')->nullable(false);
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->char('sha256_hex', 64)->nullable(false);
            $table->string('content_type', 64)->nullable(false)->default('application/pdf');
            $table->timestampTz('generated_at')->nullable(false);
            $table->uuid('generated_by_user_id')->nullable();
            $table->jsonb('meta_json')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('settlement_pack_id')->references('id')->on('settlement_packs')->onDelete('cascade');
            $table->foreign('generated_by_user_id')->references('id')->on('users');
            $table->unique(['tenant_id', 'settlement_pack_id', 'version']);
            $table->index(['tenant_id']);
            $table->index(['settlement_pack_id']);
        });

        DB::statement('ALTER TABLE settlement_pack_documents ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_pack_documents');
    }
};
