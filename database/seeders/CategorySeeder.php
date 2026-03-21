<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $seed = [
            ['name' => 'Makanan', 'sort_order' => 1],
            ['name' => 'Minuman', 'sort_order' => 2],
            ['name' => 'Snack', 'sort_order' => 3],
        ];

        foreach ($seed as $row) {
            $slug = Str::slug($row['name']);

            Category::query()->firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $row['name'],
                    'sort_order' => $row['sort_order'],
                ]
            );
        }
    }
}
