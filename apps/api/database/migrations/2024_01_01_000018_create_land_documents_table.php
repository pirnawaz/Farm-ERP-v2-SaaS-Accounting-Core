<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('land_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('land_parcel_id')->nullable(false);
            $table->string('file_path')->nullable(false);
            $table->text('description')->nullable();
            $table->timestampTz('created_at')->nullable(false)->useCurrent();
            
            $table->foreign('land_parcel_id')->references('id')->on('land_parcels')->onDelete('cascade');
            $table->index(['land_parcel_id']);
        });
        
        DB::statement('ALTER TABLE land_documents ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('land_documents');
    }
};
