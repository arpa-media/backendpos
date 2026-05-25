<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('categories')) {
            return;
        }

        $now = now();
        $existing = DB::table('categories')
            ->where(function ($query) {
                $query->where('slug', 'packaging')
                    ->orWhereRaw('UPPER(name) = ?', ['PACKAGING']);
            })
            ->whereNull('deleted_at')
            ->first();

        if ($existing) {
            DB::table('categories')
                ->where('id', $existing->id)
                ->update([
                    'name' => 'Packaging',
                    'slug' => 'packaging',
                    'kind' => 'PACKAGING',
                    'updated_at' => $now,
                ]);
            return;
        }

        DB::table('categories')->insert([
            'id' => (string) Str::ulid(),
            'name' => 'Packaging',
            'slug' => 'packaging',
            'kind' => 'PACKAGING',
            'sort_order' => 900,
            'image_path' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('categories')) {
            return;
        }

        DB::table('categories')
            ->where('slug', 'packaging')
            ->where('kind', 'PACKAGING')
            ->delete();
    }
};
