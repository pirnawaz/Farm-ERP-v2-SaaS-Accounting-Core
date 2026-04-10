<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TYPE posting_group_source_type ADD VALUE IF NOT EXISTS 'FIELD_JOB'");

        Schema::table('field_jobs', function (Blueprint $table) {
            $table->timestampTz('posted_at')->nullable()->after('posting_date');
            $table->timestampTz('reversed_at')->nullable()->after('reversal_posting_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('field_jobs', function (Blueprint $table) {
            $table->dropColumn(['posted_at', 'reversed_at']);
        });
        // PostgreSQL: cannot remove enum value safely; FIELD_JOB remains on posting_group_source_type
    }
};
