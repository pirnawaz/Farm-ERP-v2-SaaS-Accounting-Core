<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id')->nullable(false);
            $table->decimal('profit_split_landlord_pct', 5, 2)->nullable(false);
            $table->decimal('profit_split_hari_pct', 5, 2)->nullable(false);
            $table->decimal('kamdari_pct', 5, 2)->nullable(false);
            $table->uuid('kamdar_party_id')->nullable();
            $table->string('kamdari_order')->nullable(false); // BEFORE_SPLIT
            $table->string('pool_definition')->nullable(false); // REVENUE_MINUS_SHARED_COSTS
            $table->timestampTz('created_at')->nullable(false)->useCurrent();
            
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
            $table->foreign('kamdar_party_id')->references('id')->on('parties');
            $table->index(['project_id']);
        });
        
        DB::statement('ALTER TABLE project_rules ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        
        // Add CHECK constraints for percentages
        // Percentages must be >= 0
        DB::statement('ALTER TABLE project_rules ADD CONSTRAINT project_rules_profit_split_landlord_pct_check CHECK (profit_split_landlord_pct >= 0)');
        DB::statement('ALTER TABLE project_rules ADD CONSTRAINT project_rules_profit_split_hari_pct_check CHECK (profit_split_hari_pct >= 0)');
        DB::statement('ALTER TABLE project_rules ADD CONSTRAINT project_rules_kamdari_pct_check CHECK (kamdari_pct >= 0)');
        
        // Percentages should sum to 100 (enforced at service layer, but add CHECK for basic validation)
        // Note: This is a simplified check - full validation happens at service layer
        DB::statement('ALTER TABLE project_rules ADD CONSTRAINT project_rules_percentages_sum_check CHECK ((profit_split_landlord_pct + profit_split_hari_pct) <= 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('project_rules');
    }
};
