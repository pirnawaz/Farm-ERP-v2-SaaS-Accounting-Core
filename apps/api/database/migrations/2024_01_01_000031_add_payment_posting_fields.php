<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Create Postgres ENUM type for payment status
        DB::statement("DO $$ BEGIN
            CREATE TYPE payment_status AS ENUM ('DRAFT', 'POSTED');
        EXCEPTION
            WHEN duplicate_object THEN null;
        END $$;");

        // Add new columns
        Schema::table('payments', function (Blueprint $table) {
            $table->string('status')->default('DRAFT')->nullable(false)->after('reference');
            $table->timestampTz('posted_at')->nullable()->after('status');
            $table->uuid('posting_group_id')->nullable()->after('posted_at');
            $table->uuid('settlement_id')->nullable()->after('posting_group_id');
            $table->text('notes')->nullable()->after('settlement_id');
        });

        // Convert status column to use ENUM type
        DB::statement("UPDATE payments SET status = 'DRAFT' WHERE status IS NULL OR status NOT IN ('DRAFT', 'POSTED')");
        DB::statement('ALTER TABLE payments DROP COLUMN status');
        DB::statement("ALTER TABLE payments ADD COLUMN status payment_status NOT NULL DEFAULT 'DRAFT'");

        // Add foreign keys
        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('posting_group_id')->references('id')->on('posting_groups');
            $table->foreign('settlement_id')->references('id')->on('settlements');
        });

        // Add indexes
        Schema::table('payments', function (Blueprint $table) {
            $table->index(['tenant_id', 'party_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'payment_date']);
            $table->index(['tenant_id', 'settlement_id']);
        });
    }

    public function down(): void
    {
        // Drop indexes
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'settlement_id']);
            $table->dropIndex(['tenant_id', 'payment_date']);
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropIndex(['tenant_id', 'party_id']);
        });

        // Drop foreign keys
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['settlement_id']);
            $table->dropForeign(['posting_group_id']);
        });

        // Revert status column
        DB::statement('ALTER TABLE payments DROP COLUMN status');
        DB::statement("ALTER TABLE payments ADD COLUMN status VARCHAR(255) NOT NULL DEFAULT 'DRAFT'");

        // Drop columns
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['notes', 'settlement_id', 'posting_group_id', 'posted_at', 'status']);
        });

        DB::statement('DROP TYPE IF EXISTS payment_status');
    }
};
