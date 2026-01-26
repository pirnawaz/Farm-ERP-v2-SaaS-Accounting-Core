<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Create Postgres ENUM type for share rule applies_to
        DB::statement("DO $$ BEGIN
            CREATE TYPE share_rule_applies_to AS ENUM ('CROP_CYCLE', 'PROJECT', 'SALE');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        // Create Postgres ENUM type for share rule basis
        DB::statement("DO $$ BEGIN
            CREATE TYPE share_rule_basis AS ENUM ('MARGIN', 'REVENUE');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        Schema::create('share_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->string('name')->nullable(false);
            $table->string('applies_to')->nullable(false); // CROP_CYCLE | PROJECT | SALE
            $table->string('basis')->nullable(false); // MARGIN | REVENUE
            $table->date('effective_from')->nullable(false);
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->nullable(false)->default(true);
            $table->integer('version')->nullable(false)->default(1);
            $table->timestampsTz();
            
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->index(['tenant_id']);
            $table->index(['tenant_id', 'applies_to', 'is_active']);
            $table->index(['effective_from', 'effective_to']);
        });
        
        DB::statement('ALTER TABLE share_rules ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        
        // Convert applies_to to ENUM
        DB::statement('ALTER TABLE share_rules DROP COLUMN applies_to');
        DB::statement("ALTER TABLE share_rules ADD COLUMN applies_to share_rule_applies_to NOT NULL");
        
        // Convert basis to ENUM
        DB::statement('ALTER TABLE share_rules DROP COLUMN basis');
        DB::statement("ALTER TABLE share_rules ADD COLUMN basis share_rule_basis NOT NULL");

        Schema::create('share_rule_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('share_rule_id')->nullable(false);
            $table->uuid('party_id')->nullable(false);
            $table->decimal('percentage', 5, 2)->nullable(false);
            $table->string('role')->nullable(); // LANDLORD, GROWER, PARTNER, etc.
            $table->timestampsTz();
            
            $table->foreign('share_rule_id')->references('id')->on('share_rules')->onDelete('cascade');
            $table->foreign('party_id')->references('id')->on('parties');
            $table->index(['share_rule_id']);
            $table->index(['party_id']);
        });
        
        DB::statement('ALTER TABLE share_rule_lines ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        
        // Ensure percentage is between 0 and 100
        DB::statement('ALTER TABLE share_rule_lines ADD CONSTRAINT share_rule_lines_percentage_check CHECK (percentage >= 0 AND percentage <= 100)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE share_rule_lines DROP CONSTRAINT IF EXISTS share_rule_lines_percentage_check');
        
        Schema::dropIfExists('share_rule_lines');
        
        DB::statement('ALTER TABLE share_rules DROP COLUMN basis');
        DB::statement("ALTER TABLE share_rules ADD COLUMN basis VARCHAR(255) NOT NULL");
        
        DB::statement('ALTER TABLE share_rules DROP COLUMN applies_to');
        DB::statement("ALTER TABLE share_rules ADD COLUMN applies_to VARCHAR(255) NOT NULL");
        
        Schema::dropIfExists('share_rules');
        
        DB::statement('DROP TYPE IF EXISTS share_rule_basis');
        DB::statement('DROP TYPE IF EXISTS share_rule_applies_to');
    }
};
