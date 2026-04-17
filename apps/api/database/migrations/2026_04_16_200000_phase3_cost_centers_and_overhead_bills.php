<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_centers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->string('name', 255)->nullable(false);
            $table->string('code', 64)->nullable();
            $table->string('status', 20)->nullable(false)->default('ACTIVE');
            $table->text('description')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->index(['tenant_id']);
            $table->index(['tenant_id', 'status']);
        });

        DB::statement('ALTER TABLE cost_centers ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE cost_centers ADD CONSTRAINT cost_centers_status_check CHECK (status IN ('ACTIVE', 'INACTIVE'))");
        DB::statement('CREATE UNIQUE INDEX cost_centers_tenant_code_unique ON cost_centers (tenant_id, code) WHERE (code IS NOT NULL AND btrim(code) <> \'\')');

        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->uuid('cost_center_id')->nullable()->after('project_id');
            $table->date('due_date')->nullable()->after('invoice_date');
            $table->foreign('cost_center_id')->references('id')->on('cost_centers')->nullOnDelete();
            $table->index(['tenant_id', 'cost_center_id']);
        });

        Schema::table('allocation_rows', function (Blueprint $table) {
            $table->uuid('cost_center_id')->nullable()->after('project_id');
            $table->foreign('cost_center_id')->references('id')->on('cost_centers')->nullOnDelete();
            $table->index(['tenant_id', 'cost_center_id']);
        });
    }

    public function down(): void
    {
        Schema::table('allocation_rows', function (Blueprint $table) {
            $table->dropForeign(['cost_center_id']);
            $table->dropIndex(['tenant_id', 'cost_center_id']);
            $table->dropColumn('cost_center_id');
        });

        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropForeign(['cost_center_id']);
            $table->dropIndex(['tenant_id', 'cost_center_id']);
            $table->dropColumn(['cost_center_id', 'due_date']);
        });

        DB::statement('DROP INDEX IF EXISTS cost_centers_tenant_code_unique');
        DB::statement('ALTER TABLE cost_centers DROP CONSTRAINT IF EXISTS cost_centers_status_check');
        Schema::dropIfExists('cost_centers');
    }
};
