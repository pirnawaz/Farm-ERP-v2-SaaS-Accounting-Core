<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TYPE posting_group_source_type ADD VALUE IF NOT EXISTS 'LOAN_REPAYMENT'");
        DB::statement("ALTER TYPE allocation_row_allocation_type ADD VALUE IF NOT EXISTS 'LOAN_REPAYMENT'");

        Schema::table('loan_repayments', function (Blueprint $table) {
            $table->uuid('posting_group_id')->nullable()->after('created_by');
            $table->timestampTz('posted_at')->nullable()->after('posting_group_id');
            $table->foreign('posting_group_id')->references('id')->on('posting_groups');
            $table->index(['tenant_id', 'posting_group_id']);
        });

        $tenantIds = DB::table('tenants')->pluck('id');
        foreach ($tenantIds as $tenantId) {
            $exists = DB::table('accounts')
                ->where('tenant_id', $tenantId)
                ->where('code', 'LOAN_INTEREST_EXPENSE')
                ->exists();
            if (! $exists) {
                DB::table('accounts')->insert([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $tenantId,
                    'code' => 'LOAN_INTEREST_EXPENSE',
                    'name' => 'Loan Interest Expense',
                    'type' => 'expense',
                    'is_system' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('loan_repayments', function (Blueprint $table) {
            $table->dropForeign(['posting_group_id']);
            $table->dropIndex(['tenant_id', 'posting_group_id']);
            $table->dropColumn(['posting_group_id', 'posted_at']);
        });

        DB::table('accounts')
            ->where('code', 'LOAN_INTEREST_EXPENSE')
            ->where('is_system', true)
            ->delete();
    }
};
