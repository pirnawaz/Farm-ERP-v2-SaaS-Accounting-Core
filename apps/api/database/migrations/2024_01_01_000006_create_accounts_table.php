<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->string('code')->nullable(false);
            $table->string('name')->nullable(false);
            $table->string('type')->nullable(false);
            $table->timestampsTz();
            
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id']);
            $table->index(['tenant_id', 'code']);
        });
        
        DB::statement('ALTER TABLE accounts ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_type_check CHECK (type IN ('ASSET','LIABILITY','EQUITY','INCOME','EXPENSE'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
