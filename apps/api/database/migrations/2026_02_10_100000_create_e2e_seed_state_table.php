<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dev-only table for E2E seed idempotency: store seeded entity IDs per tenant
     * so multiple seed runs reuse the same records.
     */
    public function up(): void
    {
        Schema::create('e2e_seed_state', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->string('key', 64);
            $table->string('value', 512);
            $table->timestamps();
            $table->primary(['tenant_id', 'key']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e2e_seed_state');
    }
};
