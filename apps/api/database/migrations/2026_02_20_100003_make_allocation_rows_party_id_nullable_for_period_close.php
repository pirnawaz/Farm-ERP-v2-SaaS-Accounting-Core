<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * PERIOD_CLOSE allocation rows have no party; allow party_id to be null.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE allocation_rows ALTER COLUMN party_id DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE allocation_rows ALTER COLUMN party_id SET NOT NULL');
    }
};
