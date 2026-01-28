<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->string('code')->nullable(false);
            $table->string('name')->nullable(false);
            $table->string('machine_type')->nullable(false);
            $table->string('ownership_type')->nullable(false);
            $table->string('status')->nullable(false);
            $table->string('meter_unit')->nullable(false);
            $table->decimal('opening_meter', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id']);
            $table->index(['tenant_id', 'status']);
        });

        DB::statement('ALTER TABLE machines ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('machines');
    }
};
