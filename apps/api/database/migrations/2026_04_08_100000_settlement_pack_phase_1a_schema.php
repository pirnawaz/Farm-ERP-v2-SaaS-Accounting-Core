<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlement_packs', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'project_id', 'register_version']);
        });

        Schema::table('settlement_packs', function (Blueprint $table) {
            $table->uuid('crop_cycle_id')->nullable()->after('project_id');
            $table->string('reference_no', 64)->nullable()->after('status');
            $table->date('as_of_date')->nullable()->after('finalized_by_user_id');
            $table->text('notes')->nullable()->after('as_of_date');
        });

        DB::statement('
            UPDATE settlement_packs sp
            SET crop_cycle_id = p.crop_cycle_id
            FROM projects p
            WHERE sp.project_id = p.id AND sp.tenant_id = p.tenant_id
        ');

        DB::statement('
            UPDATE settlement_packs sp
            SET crop_cycle_id = (
                SELECT cc.id FROM crop_cycles cc
                WHERE cc.tenant_id = sp.tenant_id
                ORDER BY cc.start_date DESC NULLS LAST
                LIMIT 1
            )
            WHERE sp.crop_cycle_id IS NULL
        ');

        DB::statement("
            UPDATE settlement_packs
            SET reference_no = 'SP-' || substr(replace(id::text, '-', ''), 1, 12)
            WHERE reference_no IS NULL
        ");

        DB::statement('
            UPDATE settlement_packs
            SET as_of_date = COALESCE(
                (generated_at AT TIME ZONE \'UTC\')::date,
                (created_at AT TIME ZONE \'UTC\')::date
            )
            WHERE as_of_date IS NULL
        ');

        DB::statement("UPDATE settlement_packs SET status = 'FINALIZED' WHERE status = 'FINAL'");
        DB::statement("UPDATE settlement_packs SET status = 'DRAFT' WHERE status = 'PENDING_APPROVAL'");

        Schema::table('settlement_packs', function (Blueprint $table) {
            $table->dropForeign(['generated_by_user_id']);
        });

        DB::statement('ALTER TABLE settlement_packs RENAME COLUMN generated_by_user_id TO prepared_by_user_id');
        DB::statement('ALTER TABLE settlement_packs RENAME COLUMN generated_at TO prepared_at');

        Schema::table('settlement_packs', function (Blueprint $table) {
            $table->foreign('prepared_by_user_id')->references('id')->on('users');
            $table->foreign('crop_cycle_id')->references('id')->on('crop_cycles');
        });

        Schema::create('settlement_pack_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('settlement_pack_id')->nullable(false);
            $table->unsignedInteger('version_no')->nullable(false);
            $table->jsonb('snapshot_json')->nullable(false);
            $table->uuid('generated_by_user_id')->nullable();
            $table->timestampTz('generated_at')->nullable(false);
            $table->string('pdf_path')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('settlement_pack_id')->references('id')->on('settlement_packs')->onDelete('cascade');
            $table->foreign('generated_by_user_id')->references('id')->on('users');
            $table->unique(['tenant_id', 'settlement_pack_id', 'version_no']);
            $table->index(['tenant_id', 'settlement_pack_id']);
        });

        DB::statement('ALTER TABLE settlement_pack_versions ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement('
            INSERT INTO settlement_pack_versions (
                id, tenant_id, settlement_pack_id, version_no, snapshot_json,
                generated_by_user_id, generated_at, pdf_path, created_at, updated_at
            )
            SELECT
                gen_random_uuid(),
                tenant_id,
                id,
                1,
                COALESCE(summary_json, \'{}\'::jsonb),
                prepared_by_user_id,
                prepared_at,
                NULL,
                NOW(),
                NOW()
            FROM settlement_packs
        ');

        Schema::table('settlement_packs', function (Blueprint $table) {
            $table->dropColumn(['summary_json', 'register_version']);
        });

        DB::statement('ALTER TABLE settlement_packs ALTER COLUMN reference_no SET NOT NULL');
        DB::statement('ALTER TABLE settlement_packs ALTER COLUMN as_of_date SET NOT NULL');

        DB::statement('ALTER TABLE settlement_packs ALTER COLUMN crop_cycle_id SET NOT NULL');

        DB::statement('ALTER TABLE settlement_packs ADD CONSTRAINT settlement_packs_status_check CHECK (status IN (\'DRAFT\', \'FINALIZED\', \'VOID\'))');

        Schema::table('settlement_packs', function (Blueprint $table) {
            $table->unique(['tenant_id', 'project_id', 'reference_no']);
            $table->index(['tenant_id', 'project_id']);
        });

        Schema::create('settlement_pack_signoffs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false);
            $table->uuid('settlement_pack_id')->nullable(false);
            $table->uuid('party_id')->nullable(false);
            $table->string('status', 20)->nullable(false)->default('PENDING');
            $table->timestampTz('responded_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('settlement_pack_id')->references('id')->on('settlement_packs')->onDelete('cascade');
            $table->foreign('party_id')->references('id')->on('parties');
            $table->unique(['tenant_id', 'settlement_pack_id', 'party_id']);
            $table->index(['tenant_id', 'settlement_pack_id']);
        });

        DB::statement('ALTER TABLE settlement_pack_signoffs ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement("ALTER TABLE settlement_pack_signoffs ADD CONSTRAINT settlement_pack_signoffs_status_check CHECK (status IN ('PENDING', 'ACCEPTED', 'REJECTED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_pack_signoffs');
        Schema::dropIfExists('settlement_pack_versions');

        Schema::table('settlement_packs', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'project_id', 'reference_no']);
            $table->dropIndex(['tenant_id', 'project_id']);
        });

        DB::statement('ALTER TABLE settlement_packs DROP CONSTRAINT IF EXISTS settlement_packs_status_check');

        Schema::table('settlement_packs', function (Blueprint $table) {
            $table->jsonb('summary_json')->nullable();
            $table->string('register_version', 64)->nullable(false)->default('default');
        });

        Schema::table('settlement_packs', function (Blueprint $table) {
            $table->dropForeign(['crop_cycle_id']);
            $table->dropForeign(['prepared_by_user_id']);
        });

        DB::statement('ALTER TABLE settlement_packs RENAME COLUMN prepared_by_user_id TO generated_by_user_id');
        DB::statement('ALTER TABLE settlement_packs RENAME COLUMN prepared_at TO generated_at');

        Schema::table('settlement_packs', function (Blueprint $table) {
            $table->foreign('generated_by_user_id')->references('id')->on('users');
        });

        DB::statement("UPDATE settlement_packs SET status = 'FINAL' WHERE status = 'FINALIZED'");

        Schema::table('settlement_packs', function (Blueprint $table) {
            $table->dropColumn([
                'crop_cycle_id',
                'reference_no',
                'as_of_date',
                'notes',
            ]);
        });

        Schema::table('settlement_packs', function (Blueprint $table) {
            $table->unique(['tenant_id', 'project_id', 'register_version']);
        });
    }
};
