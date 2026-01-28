<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE crop_cycles DROP CONSTRAINT IF EXISTS crop_cycles_date_range');
        DB::statement('ALTER TABLE crop_cycles ALTER COLUMN end_date DROP NOT NULL');
        DB::statement('ALTER TABLE crop_cycles ADD CONSTRAINT crop_cycles_date_range CHECK (end_date IS NULL OR start_date <= end_date)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE crop_cycles DROP CONSTRAINT IF EXISTS crop_cycles_date_range');
        DB::statement('ALTER TABLE crop_cycles ALTER COLUMN end_date SET NOT NULL');
        DB::statement('ALTER TABLE crop_cycles ADD CONSTRAINT crop_cycles_date_range CHECK (start_date <= end_date)');
    }
};
