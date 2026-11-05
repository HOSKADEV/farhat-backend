<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Type;
use Illuminate\Database\Seeder;

class TypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [];

        foreach ($types as $categoryName => $typeNames) {
            $category = Category::firstOrCreate(['name' => $categoryName]);

            foreach ($typeNames as $typeName) {
                Type::firstOrCreate(
                    ['category_id' => $category->id, 'name' => $typeName]
                );
            }
        }
    }
}
