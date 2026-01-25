<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_book_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('project_id')->nullable(false);
            $table->string('type')->nullable(false); // EXPENSE, INCOME
            $table->string('status')->default('DRAFT')->nullable(false); // DRAFT, POSTED, VOID
            $table->date('event_date')->nullable(false);
            $table->string('description')->nullable(false);
            $table->decimal('gross_amount', 14, 2)->nullable(false);
            $table->char('currency_code', 3)->default('GBP')->nullable(false);
            $table->timestampsTz();
            
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('project_id')->references('id')->on('projects');
        });
        
        DB::statement('ALTER TABLE daily_book_entries ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE daily_book_entries ADD CONSTRAINT daily_book_entries_type_check CHECK (type IN (\'EXPENSE\', \'INCOME\'))');
        DB::statement('ALTER TABLE daily_book_entries ADD CONSTRAINT daily_book_entries_status_check CHECK (status IN (\'DRAFT\', \'POSTED\', \'VOID\'))');
        DB::statement('ALTER TABLE daily_book_entries ADD CONSTRAINT daily_book_entries_gross_amount_check CHECK (gross_amount >= 0)');
        
        Schema::table('daily_book_entries', function (Blueprint $table) {
            $table->index(['tenant_id', 'created_at']);
            $table->index(['project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_book_entries');
    }
};
