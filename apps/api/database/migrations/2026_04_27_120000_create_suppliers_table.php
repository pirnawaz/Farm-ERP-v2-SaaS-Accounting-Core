<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->string('name', 255)->nullable(false);
            $table->string('status', 20)->nullable(false)->default('ACTIVE');

            // Optional linkage to existing Parties (no changes to parties table).
            $table->uuid('party_id')->nullable();

            $table->string('phone', 64)->nullable();
            $table->string('email', 255)->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();

            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('party_id')->references('id')->on('parties')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'name']);
        });

        DB::statement('ALTER TABLE suppliers ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE suppliers ADD CONSTRAINT suppliers_status_check CHECK (status IN ('ACTIVE', 'INACTIVE'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE suppliers DROP CONSTRAINT IF EXISTS suppliers_status_check');
        Schema::dropIfExists('suppliers');
    }
};

