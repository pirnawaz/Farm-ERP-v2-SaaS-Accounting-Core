<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_worker_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('worker_id')->nullable(false);
            $table->decimal('payable_balance', 18, 2)->default(0);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('worker_id')->references('id')->on('lab_workers');
            $table->unique(['tenant_id', 'worker_id']);
        });

        DB::statement('ALTER TABLE lab_worker_balances ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_worker_balances');
    }
};
