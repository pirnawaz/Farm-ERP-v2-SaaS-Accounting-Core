<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Create Postgres ENUM type for loan transaction type
        DB::statement("DO $$ BEGIN
            CREATE TYPE loan_transaction_type AS ENUM ('DISBURSEMENT', 'REPAYMENT', 'MARKUP');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        Schema::create('loan_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('loan_id')->nullable(false);
            $table->string('type')->nullable(false);
            $table->decimal('amount', 12, 2)->nullable(false);
            $table->date('transaction_date')->nullable(false);
            $table->timestampTz('created_at')->nullable(false)->useCurrent();
            
            $table->foreign('loan_id')->references('id')->on('loans')->onDelete('cascade');
            $table->index(['loan_id']);
            $table->index(['transaction_date']);
            $table->index(['type']);
        });
        
        DB::statement('ALTER TABLE loan_transactions ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        
        // Convert type to ENUM
        DB::statement('ALTER TABLE loan_transactions DROP COLUMN type');
        DB::statement("ALTER TABLE loan_transactions ADD COLUMN type loan_transaction_type NOT NULL DEFAULT 'DISBURSEMENT'");
        
        // Add CHECK constraint: amount must be greater than 0
        DB::statement('ALTER TABLE loan_transactions ADD CONSTRAINT loan_transactions_amount_check CHECK (amount > 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE loan_transactions DROP CONSTRAINT IF EXISTS loan_transactions_amount_check');
        
        DB::statement('ALTER TABLE loan_transactions DROP COLUMN type');
        DB::statement("ALTER TABLE loan_transactions ADD COLUMN type VARCHAR(255) NOT NULL");
        
        Schema::dropIfExists('loan_transactions');
        
        DB::statement('DROP TYPE IF EXISTS loan_transaction_type');
    }
};
