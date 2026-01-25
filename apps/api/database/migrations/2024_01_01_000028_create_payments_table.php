<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Create Postgres ENUM types for payment direction and method
        DB::statement("DO $$ BEGIN
            CREATE TYPE payment_direction AS ENUM ('IN', 'OUT');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        DB::statement("DO $$ BEGIN
            CREATE TYPE payment_method AS ENUM ('CASH', 'BANK');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('party_id')->nullable(false);
            $table->string('direction')->nullable(false);
            $table->decimal('amount', 12, 2)->nullable(false);
            $table->date('payment_date')->nullable(false);
            $table->string('method')->nullable(false);
            $table->string('reference')->nullable();
            $table->timestampTz('created_at')->nullable(false)->useCurrent();
            
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('party_id')->references('id')->on('parties');
            $table->index(['tenant_id']);
            $table->index(['party_id']);
            $table->index(['payment_date']);
            $table->index(['direction']);
        });
        
        DB::statement('ALTER TABLE payments ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        
        // Convert direction and method to ENUMs
        DB::statement('ALTER TABLE payments DROP COLUMN direction');
        DB::statement("ALTER TABLE payments ADD COLUMN direction payment_direction NOT NULL DEFAULT 'IN'");
        DB::statement('ALTER TABLE payments DROP COLUMN method');
        DB::statement("ALTER TABLE payments ADD COLUMN method payment_method NOT NULL DEFAULT 'CASH'");
        
        // Add CHECK constraint: amount must be greater than 0
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_check CHECK (amount > 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_amount_check');
        
        DB::statement('ALTER TABLE payments DROP COLUMN method');
        DB::statement("ALTER TABLE payments ADD COLUMN method VARCHAR(255) NOT NULL");
        DB::statement('ALTER TABLE payments DROP COLUMN direction');
        DB::statement("ALTER TABLE payments ADD COLUMN direction VARCHAR(255) NOT NULL");
        
        Schema::dropIfExists('payments');
        
        DB::statement('DROP TYPE IF EXISTS payment_method');
        DB::statement('DROP TYPE IF EXISTS payment_direction');
    }
};
