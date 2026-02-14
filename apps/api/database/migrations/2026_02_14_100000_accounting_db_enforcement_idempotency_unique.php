<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 0 / Sprint 0.1: Explicit idempotency constraint on posting_groups.
     * Duplicate POST for same (tenant_id, source_type, source_id) must fail at DB level.
     * Replaces previous unique index with a named constraint for clarity.
     */
    public function up(): void
    {
        // Drop legacy-named unique index if present (from 2024_01_01_000024)
        DB::statement('DROP INDEX IF EXISTS posting_groups_tenant_source_unique');
        DB::statement('DROP INDEX IF EXISTS posting_groups_tenant_id_source_type_source_id_unique');

        // Enforce idempotency: one posting group per (tenant_id, source_type, source_id)
        DB::statement('
            ALTER TABLE posting_groups
            ADD CONSTRAINT posting_groups_idempotency_unique
            UNIQUE (tenant_id, source_type, source_id)
        ');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE posting_groups DROP CONSTRAINT IF EXISTS posting_groups_idempotency_unique');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS posting_groups_tenant_source_unique ON posting_groups(tenant_id, source_type, source_id)');
    }
};
