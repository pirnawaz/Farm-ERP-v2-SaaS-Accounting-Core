<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add is_system boolean field with default false
        Schema::table('accounts', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->nullable(false)->after('type');
        });

        // Add index for system account queries
        Schema::table('accounts', function (Blueprint $table) {
            $table->index('is_system');
        });

        // Update existing type CHECK constraint to use lowercase values per spec
        // First, drop the existing constraint
        DB::statement("ALTER TABLE accounts DROP CONSTRAINT IF EXISTS accounts_type_check");
        
        // Update existing data to lowercase
        DB::statement("UPDATE accounts SET type = LOWER(type)");
        
        // Add new CHECK constraint with lowercase values
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_type_check CHECK (type IN ('asset', 'liability', 'equity', 'income', 'expense'))");
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndex(['is_system']);
            $table->dropColumn('is_system');
        });

        // Restore original uppercase constraint if needed
        DB::statement("ALTER TABLE accounts DROP CONSTRAINT IF EXISTS accounts_type_check");
        DB::statement("UPDATE accounts SET type = UPPER(type)");
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_type_check CHECK (type IN ('ASSET','LIABILITY','EQUITY','INCOME','EXPENSE'))");
    }
};
