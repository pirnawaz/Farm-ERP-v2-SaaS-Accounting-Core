<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE settlement_packs DROP CONSTRAINT IF EXISTS settlement_packs_tenant_id_reference_no_unique');

        $exists = DB::selectOne("
            SELECT 1 AS ok FROM pg_constraint
            WHERE conname = 'settlement_packs_tenant_id_project_id_reference_no_unique'
            LIMIT 1
        ");
        if (! $exists) {
            Schema::table('settlement_packs', function (Blueprint $table) {
                $table->unique(['tenant_id', 'project_id', 'reference_no']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('settlement_packs', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'project_id', 'reference_no']);
        });
        Schema::table('settlement_packs', function (Blueprint $table) {
            $table->unique(['tenant_id', 'reference_no']);
        });
    }
};
