<?php

namespace Database\Seeders;

use App\Models\Color;
use Illuminate\Database\Seeder;

class ColorSeeder extends Seeder
{
    public function run(): void
    {
        $colors = [];

        foreach ($colors as $data) {
            Color::firstOrCreate(['code' => $data['code']], $data);
        }
    }
}
