<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add new allocation types to the ENUM
        // PostgreSQL doesn't support ALTER TYPE ... ADD VALUE in a transaction,
        // so we need to add them one by one
        
        // Add ADVANCE
        DB::statement("DO $$ BEGIN
            ALTER TYPE allocation_row_allocation_type ADD VALUE IF NOT EXISTS 'ADVANCE';
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        // Add ADVANCE_OFFSET
        DB::statement("DO $$ BEGIN
            ALTER TYPE allocation_row_allocation_type ADD VALUE IF NOT EXISTS 'ADVANCE_OFFSET';
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        // Add SALE_REVENUE
        DB::statement("DO $$ BEGIN
            ALTER TYPE allocation_row_allocation_type ADD VALUE IF NOT EXISTS 'SALE_REVENUE';
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");
    }

    public function down(): void
    {
        // Note: PostgreSQL doesn't support removing values from ENUM types
        // This would require recreating the ENUM type, which is complex
        // For now, we'll leave the values in place
        // If needed, this can be handled manually or through a more complex migration
    }
};
