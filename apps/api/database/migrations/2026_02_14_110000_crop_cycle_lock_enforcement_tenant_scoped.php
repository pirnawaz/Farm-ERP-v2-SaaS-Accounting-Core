<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 0 / Sprint 0.2: Crop cycle lock enforcement (DB-level).
     * When a crop cycle is CLOSED, no posting groups may be created for that cycle.
     * Tenant-scoped: posting_groups.tenant_id must match crop_cycles.tenant_id.
     * Error message includes "crop cycle is closed" for test assertions.
     */
    public function up(): void
    {
        DB::statement("
            CREATE OR REPLACE FUNCTION fn_block_closed_crop_cycle_posting()
            RETURNS TRIGGER AS \$\$
            DECLARE
                cc_tenant_id UUID;
                cc_status TEXT;
            BEGIN
                IF NEW.crop_cycle_id IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT tenant_id, status INTO cc_tenant_id, cc_status
                FROM crop_cycles
                WHERE id = NEW.crop_cycle_id;

                IF cc_tenant_id IS NULL THEN
                    RAISE EXCEPTION 'Crop cycle not found for posting group';
                END IF;

                IF cc_tenant_id IS DISTINCT FROM NEW.tenant_id THEN
                    RAISE EXCEPTION 'Posting group tenant must match crop cycle tenant';
                END IF;

                IF cc_status = 'CLOSED' THEN
                    RAISE EXCEPTION 'Posting not allowed: crop cycle is closed';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        ");

        // Trigger already exists from 2026_01_25_200001; function body replaced above
    }

    public function down(): void
    {
        // Restore original message (no tenant check) for rollback compatibility
        DB::statement("
            CREATE OR REPLACE FUNCTION fn_block_closed_crop_cycle_posting()
            RETURNS TRIGGER AS \$\$
            DECLARE
                cc_status TEXT;
            BEGIN
                IF NEW.crop_cycle_id IS NOT NULL THEN
                    SELECT status INTO cc_status FROM crop_cycles WHERE id = NEW.crop_cycle_id;
                    IF cc_status = 'CLOSED' THEN
                        RAISE EXCEPTION 'Cannot post to a CLOSED crop cycle';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        ");
    }
};
