<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("DO $$ BEGIN
            CREATE TYPE crop_activity_status AS ENUM ('DRAFT', 'POSTED', 'REVERSED');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        Schema::create('crop_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->string('doc_no')->nullable(false);
            $table->uuid('activity_type_id')->nullable(false);
            $table->date('activity_date')->nullable(false);
            $table->uuid('crop_cycle_id')->nullable(false);
            $table->uuid('project_id')->nullable(false);
            $table->uuid('land_parcel_id')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->nullable(false)->default('DRAFT');
            $table->date('posting_date')->nullable();
            $table->uuid('posting_group_id')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampTz('reversed_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('activity_type_id')->references('id')->on('crop_activity_types');
            $table->foreign('crop_cycle_id')->references('id')->on('crop_cycles');
            $table->foreign('project_id')->references('id')->on('projects');
            $table->foreign('land_parcel_id')->references('id')->on('land_parcels');
            $table->foreign('posting_group_id')->references('id')->on('posting_groups');
            $table->foreign('created_by')->references('id')->on('users');
            $table->unique(['tenant_id', 'doc_no']);
            $table->index(['tenant_id', 'activity_date']);
            $table->index(['tenant_id', 'crop_cycle_id', 'project_id']);
            $table->index(['tenant_id', 'status']);
        });

        DB::statement('ALTER TABLE crop_activities ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE crop_activities DROP COLUMN status');
        DB::statement("ALTER TABLE crop_activities ADD COLUMN status crop_activity_status NOT NULL DEFAULT 'DRAFT'");
    }

    public function down(): void
    {
        Schema::dropIfExists('crop_activities');
        DB::statement('DROP TYPE IF EXISTS crop_activity_status');
    }
};
