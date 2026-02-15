<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('land_leases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('project_id')->nullable(false);
            $table->uuid('land_parcel_id')->nullable(false);
            $table->uuid('landlord_party_id')->nullable(false);
            $table->date('start_date')->nullable(false);
            $table->date('end_date')->nullable();
            $table->decimal('rent_amount', 18, 2)->nullable(false);
            $table->string('frequency', 20)->nullable(false)->default('MONTHLY');
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('project_id')->references('id')->on('projects');
            $table->foreign('land_parcel_id')->references('id')->on('land_parcels');
            $table->foreign('landlord_party_id')->references('id')->on('parties');
            $table->foreign('created_by')->references('id')->on('users');
            $table->index(['tenant_id']);
            $table->index(['project_id']);
            $table->index(['land_parcel_id']);
            $table->index(['landlord_party_id']);
        });

        DB::statement('ALTER TABLE land_leases ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('land_leases');
    }
};
