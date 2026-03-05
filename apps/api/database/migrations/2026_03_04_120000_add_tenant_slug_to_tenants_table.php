<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('slug', 100)->nullable()->after('name');
        });

        // Backfill: slugify name, ensure uniqueness with -2, -3, ...
        $rows = DB::table('tenants')->orderBy('created_at')->get(['id', 'name']);
        $used = [];
        foreach ($rows as $row) {
            $base = $this->slugify($row->name ?? 'tenant');
            $slug = $base;
            $n = 2;
            while (isset($used[$slug])) {
                $slug = $base . '-' . $n;
                $n++;
            }
            $used[$slug] = true;
            DB::table('tenants')->where('id', $row->id)->update(['slug' => $slug]);
        }

        Schema::table('tenants', function (Blueprint $table) {
            $table->string('slug', 100)->nullable(false)->change();
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }

    private function slugify(string $value): string
    {
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value);
        $value = trim($value, '-');
        $value = strtolower($value);
        return $value === '' ? 'tenant' : substr($value, 0, 100);
    }
};
