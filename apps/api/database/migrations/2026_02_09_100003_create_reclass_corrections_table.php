<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reclass_corrections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('operational_transaction_id')->nullable(false);
            $table->uuid('posting_group_id')->nullable(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'operational_transaction_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('operational_transaction_id')->references('id')->on('operational_transactions');
            $table->foreign('posting_group_id')->references('id')->on('posting_groups');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reclass_corrections');
    }
};
