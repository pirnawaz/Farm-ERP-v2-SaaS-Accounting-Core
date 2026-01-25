<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posting_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('project_id')->nullable(false);
            $table->string('source_type')->nullable(false);
            $table->uuid('source_id')->nullable(false);
            $table->date('posting_date')->nullable(false);
            $table->timestampsTz();
            
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('project_id')->references('id')->on('projects');
            $table->unique(['tenant_id', 'source_type', 'source_id']);
            $table->index(['tenant_id']);
            $table->index(['project_id']);
            $table->index(['tenant_id', 'source_type', 'source_id']);
            $table->index(['posting_date']);
        });
        
        DB::statement('ALTER TABLE posting_groups ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('posting_groups');
    }
};
