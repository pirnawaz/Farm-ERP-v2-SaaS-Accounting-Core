<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('land_parcel_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('land_parcel_id')->nullable(false);
            $table->string('changed_by_user_id')->nullable();
            $table->string('changed_by_role')->nullable();
            $table->string('field_name')->nullable(false);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestampTz('changed_at')->nullable(false)->useCurrent();
            $table->string('request_id')->nullable();
            $table->string('source')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('land_parcel_id')->references('id')->on('land_parcels')->onDelete('cascade');
            $table->index(['land_parcel_id', 'changed_at']);
        });

        DB::statement('ALTER TABLE land_parcel_audit_logs ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('land_parcel_audit_logs');
    }
};
