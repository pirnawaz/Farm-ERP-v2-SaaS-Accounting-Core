<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crop_activity_labour', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('activity_id')->nullable(false);
            $table->uuid('worker_id')->nullable(false);
            $table->string('rate_basis')->nullable();
            $table->decimal('units', 18, 6)->nullable(false);
            $table->decimal('rate', 18, 6)->nullable(false);
            $table->decimal('amount', 18, 2)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('activity_id')->references('id')->on('crop_activities')->cascadeOnDelete();
            $table->foreign('worker_id')->references('id')->on('lab_workers');
            $table->index(['tenant_id', 'activity_id']);
        });

        DB::statement('ALTER TABLE crop_activity_labour ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE crop_activity_labour ADD CONSTRAINT crop_activity_labour_units_positive CHECK (units > 0)');
        DB::statement('ALTER TABLE crop_activity_labour ADD CONSTRAINT crop_activity_labour_rate_non_neg CHECK (rate >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('crop_activity_labour');
    }
};
