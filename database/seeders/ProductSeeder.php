<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/products.txt');

        if (! is_file($path)) {
            return;
        }

        $names = collect(file($path, FILE_IGNORE_NEW_LINES))
            ->map(fn (string $line): string => trim(preg_replace('/\s+/u', ' ', $line) ?? ''))
            ->filter()
            ->unique()
            ->values();

        foreach ($names as $name) {
            Product::firstOrCreate(
                ['name' => $name],
                ['min_quantity' => 5],
            );
        }
    }
}
