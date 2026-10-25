<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

/**
 * Mobile shop er shadharon category. Slug diye khoje, thakle kichu bodlay na (bar bar chalano nirapod).
 * Product er behaviour (IMEI lagbe kina, RAM/storage) category na, product "type" theke ashe.
 */
class DefaultCategorySeeder extends Seeder
{
    public const CATEGORIES = [
        'smartphone'  => 'Smartphone',
        'button-phone' => 'Button Phone',
        'tablet'      => 'Tablet',
        'smart-watch' => 'Smart Watch',
        'accessories' => 'Accessories',
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $slug => $name) {
            // naam age thekei onno slug e thakle (unique) notun toiri na
            if (Category::where('slug', $slug)->orWhere('name', $name)->exists()) {
                continue;
            }
            Category::create(['name' => $name, 'slug' => $slug, 'status' => 1]);
        }
    }
}
