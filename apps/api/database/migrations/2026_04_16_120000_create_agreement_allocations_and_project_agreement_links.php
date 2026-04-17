<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreement_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('agreement_id');
            $table->uuid('land_parcel_id');
            $table->decimal('allocated_area', 14, 4)->nullable(false);
            $table->string('area_uom', 32)->nullable()->default('ACRE');
            $table->date('starts_on')->nullable(false);
            $table->date('ends_on')->nullable();
            $table->string('status', 32)->nullable(false)->default('ACTIVE');
            $table->string('label')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('legacy_field_id')->nullable();
            $table->uuid('backfilled_for_project_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('agreement_id')->references('id')->on('agreements')->restrictOnDelete();
            $table->foreign('land_parcel_id')->references('id')->on('land_parcels')->restrictOnDelete();
            $table->foreign('legacy_field_id')->references('id')->on('field_blocks')->nullOnDelete();
            $table->foreign('backfilled_for_project_id')->references('id')->on('projects')->nullOnDelete();

            $table->index(['tenant_id', 'land_parcel_id']);
            $table->index(['tenant_id', 'agreement_id']);
            $table->index(['tenant_id', 'status']);
            $table->unique(['tenant_id', 'backfilled_for_project_id']);
        });

        DB::statement('ALTER TABLE agreement_allocations ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        Schema::table('projects', function (Blueprint $table) {
            $table->uuid('agreement_id')->nullable();
            $table->uuid('agreement_allocation_id')->nullable();

            $table->foreign('agreement_id')->references('id')->on('agreements')->nullOnDelete();
            $table->foreign('agreement_allocation_id')->references('id')->on('agreement_allocations')->nullOnDelete();

            $table->index(['tenant_id', 'agreement_id']);
            $table->index(['tenant_id', 'agreement_allocation_id']);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['agreement_id']);
            $table->dropForeign(['agreement_allocation_id']);
            $table->dropIndex(['tenant_id', 'agreement_id']);
            $table->dropIndex(['tenant_id', 'agreement_allocation_id']);
            $table->dropColumn(['agreement_id', 'agreement_allocation_id']);
        });

        Schema::dropIfExists('agreement_allocations');
    }
};
