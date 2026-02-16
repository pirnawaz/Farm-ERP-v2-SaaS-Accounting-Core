<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * General Journal header: draft → posted → reversed.
     */
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(false)->index();
            $table->string('journal_number')->nullable(false);
            $table->date('entry_date')->nullable(false);
            $table->text('memo')->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->uuid('posting_group_id')->nullable();
            $table->uuid('posted_by')->nullable();
            $table->timestamptz('posted_at')->nullable();
            $table->timestamptz('reversed_at')->nullable();
            $table->uuid('reversal_posting_group_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('posting_group_id')->references('id')->on('posting_groups');
            $table->foreign('reversal_posting_group_id')->references('id')->on('posting_groups');
            $table->foreign('posted_by')->references('id')->on('users');
            $table->unique(['tenant_id', 'journal_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
