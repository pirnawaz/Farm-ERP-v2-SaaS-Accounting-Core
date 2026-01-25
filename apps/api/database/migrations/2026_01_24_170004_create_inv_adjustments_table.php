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
            CREATE TYPE inv_adjustment_status AS ENUM ('DRAFT', 'POSTED', 'REVERSED');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        Schema::create('inv_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->string('doc_no')->nullable(false);
            $table->uuid('store_id')->nullable(false);
            $table->string('reason', 50)->nullable(false); // LOSS, DAMAGE, COUNT_GAIN, COUNT_LOSS, OTHER
            $table->text('notes')->nullable();
            $table->date('doc_date')->nullable(false);
            $table->string('status')->nullable(false)->default('DRAFT');
            $table->date('posting_date')->nullable();
            $table->uuid('posting_group_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('store_id')->references('id')->on('inv_stores');
            $table->foreign('posting_group_id')->references('id')->on('posting_groups');
            $table->foreign('created_by')->references('id')->on('users');
            $table->unique(['tenant_id', 'doc_no']);
            $table->index(['tenant_id']);
            $table->index(['status']);
        });

        DB::statement('ALTER TABLE inv_adjustments ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE inv_adjustments DROP COLUMN status');
        DB::statement("ALTER TABLE inv_adjustments ADD COLUMN status inv_adjustment_status NOT NULL DEFAULT 'DRAFT'");
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_adjustments');
        DB::statement('DROP TYPE IF EXISTS inv_adjustment_status');
    }
};
