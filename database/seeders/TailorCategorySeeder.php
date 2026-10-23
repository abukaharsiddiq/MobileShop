<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TailorCategory;
use Illuminate\Support\Str;

class TailorCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            // Full Sets
            ['name' => 'Three-Piece (৩-পিস)', 'type' => 'full_set'],
            ['name' => 'Two-Piece (২-পিস)', 'type' => 'full_set'],
            ['name' => 'Anarkali Set', 'type' => 'full_set'],
            
            // Top Wear
            ['name' => 'Simple Kameez', 'type' => 'top_wear'],
            ['name' => 'A-Line Kurti', 'type' => 'top_wear'],
            ['name' => 'Short Kurti', 'type' => 'top_wear'],
            ['name' => 'Gown Style', 'type' => 'top_wear'],
            
            // Bottom Wear
            ['name' => 'Salwar', 'type' => 'bottom_wear'],
            ['name' => 'Palazzo', 'type' => 'bottom_wear'],
            ['name' => 'Pant Cut Salwar', 'type' => 'bottom_wear'],
            ['name' => 'Churidar', 'type' => 'bottom_wear'],
        ];

        foreach ($categories as $cat) {
            TailorCategory::create([
                'name' => $cat['name'],
                'type' => $cat['type'],
            ]);
        }
    }
}