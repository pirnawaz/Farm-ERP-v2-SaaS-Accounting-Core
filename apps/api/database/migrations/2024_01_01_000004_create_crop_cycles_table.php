<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Create enum type
        DB::statement("DO $$ BEGIN
            CREATE TYPE crop_cycle_status AS ENUM ('OPEN', 'CLOSED');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        Schema::create('crop_cycles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->string('name')->nullable(false);
            $table->date('start_date')->nullable(false);
            $table->date('end_date')->nullable(false);
            $table->string('status')->nullable(false)->default('OPEN');
            $table->timestampsTz();
            
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->index(['tenant_id']);
            $table->index(['status']);
        });
        
        DB::statement('ALTER TABLE crop_cycles ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE crop_cycles ADD CONSTRAINT crop_cycles_date_range CHECK (start_date <= end_date)");
        DB::statement("ALTER TABLE crop_cycles ADD CONSTRAINT crop_cycles_status_check CHECK (status IN ('OPEN', 'CLOSED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('crop_cycles');
        DB::statement('DROP TYPE IF EXISTS crop_cycle_status');
    }
};
