<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;

class UnitSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            ['name' => 'Box', 'symbol' => 'box'],
            ['name' => 'Kilogram', 'symbol' => 'kg'],
        ];

        foreach ($units as $unit) {
            Unit::firstOrCreate(['symbol' => $unit['symbol']], $unit);
        }
    }
}
