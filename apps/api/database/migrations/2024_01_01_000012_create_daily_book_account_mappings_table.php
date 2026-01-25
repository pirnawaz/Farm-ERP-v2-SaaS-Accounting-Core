<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_book_account_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->string('version')->nullable(false);
            $table->date('effective_from')->nullable(false);
            $table->date('effective_to')->nullable();
            $table->uuid('expense_debit_account_id')->nullable(false);
            $table->uuid('expense_credit_account_id')->nullable(false);
            $table->uuid('income_debit_account_id')->nullable(false);
            $table->uuid('income_credit_account_id')->nullable(false);
            $table->timestampsTz();
            
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('expense_debit_account_id')->references('id')->on('accounts');
            $table->foreign('expense_credit_account_id')->references('id')->on('accounts');
            $table->foreign('income_debit_account_id')->references('id')->on('accounts');
            $table->foreign('income_credit_account_id')->references('id')->on('accounts');
            $table->unique(['tenant_id', 'version', 'effective_from']);
            $table->index(['tenant_id', 'effective_from', 'effective_to']);
        });
        
        DB::statement('ALTER TABLE daily_book_account_mappings ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE daily_book_account_mappings ADD CONSTRAINT daily_book_account_mappings_date_range CHECK (effective_to IS NULL OR effective_to >= effective_from)');
        
        // Trigger to ensure mapping account_ids belong to same tenant_id
        // This is handled at application level in Laravel, but we can add a DB trigger too
        DB::statement("
            CREATE OR REPLACE FUNCTION validate_daily_book_account_mapping_tenant()
            RETURNS TRIGGER AS \$\$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM accounts 
                    WHERE id = NEW.expense_debit_account_id AND tenant_id = NEW.tenant_id
                ) THEN
                    RAISE EXCEPTION 'expense_debit_account_id does not belong to tenant_id %', NEW.tenant_id;
                END IF;
                
                IF NOT EXISTS (
                    SELECT 1 FROM accounts 
                    WHERE id = NEW.expense_credit_account_id AND tenant_id = NEW.tenant_id
                ) THEN
                    RAISE EXCEPTION 'expense_credit_account_id does not belong to tenant_id %', NEW.tenant_id;
                END IF;
                
                IF NOT EXISTS (
                    SELECT 1 FROM accounts 
                    WHERE id = NEW.income_debit_account_id AND tenant_id = NEW.tenant_id
                ) THEN
                    RAISE EXCEPTION 'income_debit_account_id does not belong to tenant_id %', NEW.tenant_id;
                END IF;
                
                IF NOT EXISTS (
                    SELECT 1 FROM accounts 
                    WHERE id = NEW.income_credit_account_id AND tenant_id = NEW.tenant_id
                ) THEN
                    RAISE EXCEPTION 'income_credit_account_id does not belong to tenant_id %', NEW.tenant_id;
                END IF;
                
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        ");
        
        // Drop trigger if exists (separate statement)
        DB::statement('DROP TRIGGER IF EXISTS daily_book_account_mappings_tenant_check ON daily_book_account_mappings');
        
        // Create trigger (separate statement)
        DB::statement("
            CREATE TRIGGER daily_book_account_mappings_tenant_check
                BEFORE INSERT OR UPDATE ON daily_book_account_mappings
                FOR EACH ROW
                EXECUTE FUNCTION validate_daily_book_account_mapping_tenant()
        ");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS daily_book_account_mappings_tenant_check ON daily_book_account_mappings');
        DB::statement('DROP FUNCTION IF EXISTS validate_daily_book_account_mapping_tenant()');
        Schema::dropIfExists('daily_book_account_mappings');
    }
};
