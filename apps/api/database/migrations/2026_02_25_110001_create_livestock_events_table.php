<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('livestock_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('production_unit_id')->nullable(false);
            $table->date('event_date')->nullable(false);
            $table->string('event_type', 32)->nullable(false); // PURCHASE | SALE | BIRTH | DEATH | ADJUSTMENT
            $table->integer('quantity')->nullable(false); // positive for add, negative for subtract; ADJUSTMENT can be +/-
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('production_unit_id')->references('id')->on('production_units')->cascadeOnDelete();
            $table->index(['tenant_id']);
            $table->index(['production_unit_id', 'event_date']);
        });

        DB::statement('ALTER TABLE livestock_events ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('livestock_events');
    }
};
