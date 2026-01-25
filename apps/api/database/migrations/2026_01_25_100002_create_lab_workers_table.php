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
            CREATE TYPE lab_worker_type AS ENUM ('HARI', 'STAFF', 'CONTRACT');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        DB::statement("DO $$ BEGIN
            CREATE TYPE lab_rate_basis AS ENUM ('DAILY', 'HOURLY', 'PIECE');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        Schema::create('lab_workers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->string('worker_no')->nullable();
            $table->string('name')->nullable(false);
            $table->string('worker_type')->nullable(false)->default('HARI');
            $table->string('rate_basis')->nullable(false)->default('DAILY');
            $table->decimal('default_rate', 18, 6)->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('party_id')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('party_id')->references('id')->on('parties');
            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id']);
            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'worker_type']);
        });

        DB::statement('ALTER TABLE lab_workers ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE lab_workers DROP COLUMN worker_type');
        DB::statement("ALTER TABLE lab_workers ADD COLUMN worker_type lab_worker_type NOT NULL DEFAULT 'HARI'");
        DB::statement('ALTER TABLE lab_workers DROP COLUMN rate_basis');
        DB::statement("ALTER TABLE lab_workers ADD COLUMN rate_basis lab_rate_basis NOT NULL DEFAULT 'DAILY'");
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_workers');
        DB::statement('DROP TYPE IF EXISTS lab_rate_basis');
        DB::statement('DROP TYPE IF EXISTS lab_worker_type');
    }
};
